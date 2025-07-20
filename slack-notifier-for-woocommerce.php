<?php 
/* 
Plugin Name: Slack Notifier for WooCommerce
Description: Sends order and inventory notifications to Slack using Slack blocks and markdown, grouped by thread. 
Version: 1.8
Author: Michael Patrick
License: GPLv2 or later
Requires Plugins: woocommerce
*/ 

if (!defined('ABSPATH')) exit; 

register_activation_hook(__FILE__, 'wsn_check_woocommerce_active');
define('WSN_SLACK_WEBHOOK', 'https://slack.com/api/chat.postMessage');

function wsn_check_woocommerce_active() {
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die('Slack Notifier for WooCommerce requires WooCommerce to be installed and active.');
    }
}

add_action('admin_init', function () {
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
            echo '<div class="notice notice-error"><p><strong>Slack Notifier for WooCommerce</strong> requires WooCommerce to be active.</p></div>';
        });
    }
});

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $settings = get_option('wsn_settings');
});

add_action('woocommerce_thankyou', 'wsn_notify_new_order', 20, 1);
add_action('woocommerce_order_status_processing', 'wsn_notify_new_order', 10, 1);
add_action('woocommerce_order_status_completed', 'wsn_notify_new_order', 10, 1);

add_action('woocommerce_order_status_changed', 'wsn_notify_order_status_change', 10, 4); 
add_action('woocommerce_low_stock', 'wsn_notify_low_stock'); 
add_action('woocommerce_no_stock', 'wsn_notify_no_stock'); 
add_action('woocommerce_product_set_stock', 'wsn_check_product_details_on_stock_change', 10, 1); 
add_action('woocommerce_product_set_stock_status', 'wsn_notify_backorder', 10, 2);
add_action('updated_post_meta', 'wsn_hook_meta_changes', 10, 4);
add_action('save_post_product', 'wsn_notify_product_change', 10, 3);
add_action('save_post_product_variation', 'wsn_notify_product_change', 10, 3);

add_action('publish_post', 'wsn_notify_new_post', 10, 2);
add_action('user_register', 'wsn_notify_new_customer');
add_action('comment_post', 'wsn_notify_new_review', 10, 2);

add_action('admin_menu', 'wsn_admin_menu');
add_action('admin_init', 'wsn_register_settings');

function wsn_admin_menu() {
    add_options_page(
        'Woo Slack Notifier',
        'Slack Notifier',
        'manage_options',
        'wsn-settings',
        'wsn_settings_page'
    );
}

function wsn_register_settings() {
    register_setting('wsn_settings_group', 'wsn_settings', 'wsn_sanitize_settings');
}

function wsn_filter_markdown($text) {
    // Replace <br> tags with newlines
    $text = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $text);

    // Decode HTML entities like &amp;, &quot;, etc.
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Strip remaining HTML tags
    $text = wp_strip_all_tags($text);

    return trim($text);
}

function wsn_order_value_emoji($total) {
    if ($total > 500) return '💎';
    if ($total > 200) return '🔥';
    if ($total > 100) return '💰';
    return '🛒';
}

function wsn_notify_new_order($order_id) {
    if (get_post_meta($order_id, '_wsn_slack_notified', true)) {
      return;
    }
    update_post_meta($order_id, '_wsn_slack_notified', time());
 
    $opt = get_option('wsn_settings');
    if (!($opt['enable_new_order'] ?? false)) return;
    $order = wc_get_order($order_id);
    $items = $order->get_items();
    $lines = [];
    foreach ($items as $item) {
        $lines[] = "• *" . $item->get_name() . "* — " . $item->get_quantity() . " × " . wp_strip_all_tags(html_entity_decode(wc_price($item->get_total())));
    }
    $shipping = $order->get_shipping_total() > 0
        ? "Shipping: " . wp_strip_all_tags(html_entity_decode(wc_price($order->get_shipping_total())))
        : "Shipping: Free";
    $payment_method = $order->get_payment_method_title();
    $email = $order->get_billing_email();
    $phone = $order->get_billing_phone();
    $billing = wsn_filter_markdown($order->get_formatted_billing_address());
    $shipping_address = wsn_filter_markdown($order->get_formatted_shipping_address());
    $coupons = $order->get_coupon_codes();
    $coupon_text = !empty($coupons) ? "Coupons Used: " . implode(', ', $coupons) : "No Coupons";
    $notes = wc_get_order_notes(['order_id' => $order_id, 'type' => 'customer']);
    $note_texts = array_map(function($note) {
        return "• _" . wsn_filter_markdown($note->content) . "_";
    }, $notes);
    $admin_url = admin_url("post.php?post={$order_id}&action=edit");
    $emoji = wsn_order_value_emoji($order->get_total());
    $address_block = [
        "type" => "section",
        "text" => [
            "type" => "mrkdwn",
            "text" => $order->get_shipping_address_1()
                ? "*Shipping:*\n{$shipping_address}"
                : "*Billing:*\n{$billing}"
        ]
    ];

    $blocks = [
        [
            "type" => "actions",
            "text" => [
                "type" => "mrkdwn",
                "text" => "{$emoji} *New WooCommerce Order* #: *{$order->get_order_number()}*"
            ]
        ],
        [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => "*Status:* `{$order->get_status()}`\n*Payment:* {$payment_method}\n*Customer:* {$email} | {$phone}"
            ]
        ],
        $address_block,
        [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => "*{$coupon_text}*"
            ]
        ],
        [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => "*Order Items:*\n" . implode("\n", $lines)
            ]
        ],
        [
            "type" => "context",
            "elements" => [[
                "type" => "mrkdwn",
                "text" => $shipping . " | *Total:* " . wsn_filter_markdown(html_entity_decode(wp_strip_all_tags(wc_price($order->get_total()))))
            ]]
        ],
        [
            "type" => "actions",
            "elements" => [[
                "type" => "button",
                "text" => [ "type" => "plain_text", "text" => "View in WooCommerce" ],
                "url" => $admin_url
            ]]
        ]
    ];
    if (!empty($note_texts)) {
        $blocks[] = [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => "*Customer Notes:*\n" . implode("\n", $note_texts)
            ]
        ];
    }
    $thread_ts = get_post_meta($order_id, '_wsn_slack_thread_ts', true);
    if (!$thread_ts) {
        $thread_ts = wsn_send_to_slack('', $blocks, $thread_ts, 'channel_orders');
        if ($thread_ts) {
            update_post_meta($order_id, '_wsn_slack_thread_ts', $thread_ts);
        }
    }
}

function wsn_check_product_details_on_stock_change($product) {
    $opt = get_option('wsn_settings');
    if (!($opt['enable_missing_details'] ?? false)) return;

    $missing = [];
    if (!$product->has_weight()) $missing[] = 'weight';
    if (!$product->has_dimensions()) $missing[] = 'dimensions';

    if (!empty($missing)) {
        $msg = ":mag: *Product missing details* - `{$product->get_name()}` missing: " . implode(', ', $missing);
        wsn_send_to_slack($msg, $blocks, $thread_ts, 'channel_products');
    }
}

function wsn_notify_new_post($ID, $post) {
    $opt = get_option('wsn_settings');
    if (!($opt['enable_new_post'] ?? false)) return;

    $title = get_the_title($ID);
    $link = get_permalink($ID);
    $message = ":memo: *New Post Published*: <$link|$title>";
    wsn_send_to_slack($message, $blocks, $thread_ts, 'channel_general');
}

function wsn_notify_new_customer($user_id) {
    $opt = get_option('wsn_settings');
    if (!($opt['enable_new_customer'] ?? false)) return;

    $user = get_userdata($user_id);
    if (!in_array('customer', $user->roles)) return;

    $message = ":bust_in_silhouette: *New Customer Registered*: `{$user->user_login}` ({$user->user_email})";
    wsn_send_to_slack($message, $blocks, $thread_ts, 'channel_general');
}

function wsn_notify_new_review($comment_ID, $approved) {
    if (1 !== $approved) return;

    $comment = get_comment($comment_ID);
    if ('product' !== get_post_type($comment->comment_post_ID)) return;

    $opt = get_option('wsn_settings');
    if (!($opt['enable_new_review'] ?? false)) return;

    $product = get_the_title($comment->comment_post_ID);
    $link = get_permalink($comment->comment_post_ID);
    $message = ":star: *New Review on* <$link|$product>: \"" . wsn_filter_markdown($comment->comment_content) . "\" by `{$comment->comment_author}`";
    wsn_send_to_slack($message, $blocks, $thread_ts, 'channel_products');
}

function wsn_notify_backorder($product_id, $stock_status) {
    $opt = get_option('wsn_settings');
    if (!($opt['enable_backorder'] ?? false)) return;

    if ($stock_status === 'onbackorder') {
        $product = wc_get_product($product_id);
        $message = ":repeat: *Backorder Alert* - `{$product->get_name()}` (ID: $product_id)";
        wsn_send_to_slack('', $blocks, $thread_ts, 'channel_orders');
    }
}

function wsn_settings_page() {
    $options = get_option('wsn_settings');
    ?>
    <div class="wrap">
        <h1>WooCommerce Slack Notifier Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields('wsn_settings_group'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">Slack Bot Token</th>
                    <td><input type="text" name="wsn_settings[token]" value="<?php echo esc_attr($options['token'] ?? ''); ?>" size="50" /></td>
                </tr>

                <tr>
                    <th scope="row">Slack Channels</th>
                    <td>
                        <label>Orders Channel:<br>
                        <input type="text" name="wsn_settings[channel_orders]" value="<?php echo esc_attr($options['channel_orders'] ?? ''); ?>" size="30" />
                        </label><br><br>
                        <label>Products Channel:<br>
                        <input type="text" name="wsn_settings[channel_products]" value="<?php echo esc_attr($options['channel_products'] ?? ''); ?>" size="30" />
                        </label><br><br>
                        <label>General Channel:<br>
                        <input type="text" name="wsn_settings[channel_general]" value="<?php echo esc_attr($options['channel_general'] ?? ''); ?>" size="30" />
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Enable Notifications</th>
                    <td>
                        General Channel Notifications:<br>
                        <label><input type="checkbox" name="wsn_settings[enable_new_customer]" value="1" <?php checked($options['enable_new_customer'] ?? '', 1); ?> /> New Customers</label><br>
                        <label><input type="checkbox" name="wsn_settings[enable_new_post]" value="1" <?php checked($options['enable_new_post'] ?? '', 1); ?> /> New Blog Posts</label><br>
                        <br>
                        Orders Channel Notifications:<br>
                        <label><input type="checkbox" name="wsn_settings[enable_new_order]" value="1" <?php checked($options['enable_new_order'] ?? '', 1); ?> /> New Orders</label><br>
                        <label><input type="checkbox" name="wsn_settings[enable_backorder]" value="1" <?php checked($options['enable_backorder'] ?? '', 1); ?> /> Backorders</label><br>
                        <br>
                        Products Channel Notifications:<br>
                        <label><input type="checkbox" name="wsn_settings[enable_new_product]" value="1" <?php checked($options['enable_new_product'] ?? '', 1); ?> /> New or Updated Products</label><br>
                        <label><input type="checkbox" name="wsn_settings[show_new_product_notice]" value="1" <?php checked($options['show_new_product_notice'] ?? '', 1); ?> /> Show "New Product" Notice</label><br>
                        <label><input type="checkbox" name="wsn_settings[enable_low_stock]" value="1" <?php checked($options['enable_low_stock'] ?? '', 1); ?> /> Low Stock</label><br>
                        <label><input type="checkbox" name="wsn_settings[enable_no_stock]" value="1" <?php checked($options['enable_no_stock'] ?? '', 1); ?> /> No Stock</label><br>
                        <label><input type="checkbox" name="wsn_settings[enable_missing_details]" value="1" <?php checked($options['enable_missing_details'] ?? '', 1); ?> /> Missing Product Info</label><br>
                        <label><input type="checkbox" name="wsn_settings[enable_new_review]" value="1" <?php checked($options['enable_new_review'] ?? '', 1); ?> /> New Reviews</label><br>
                        <br>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr>
        <form method="post">
    <h2>Slack Notifier Settings</h2>
    <?php wp_nonce_field('wsn_test_action', 'wsn_test_nonce'); submit_button('Send Test Slack Message', 'secondary', 'wsn_send_test'); ?>
        </form>
    </div>

    <?php
    if (isset($_POST['wsn_send_test']) && check_admin_referer('wsn_test_action', 'wsn_test_nonce')) {
        $response = wsn_send_to_slack(":white_check_mark: *Test message sent from WooCommerce Slack Notifier!*", $blocks, $thread_ts, 'channel_general');
        if ($response === true) {
            echo '<div class="notice notice-success"><p>Test message sent!</p></div>';
        } else {
            $code = wp_remote_retrieve_response_code($response);
            if ($code === 200) {
                 // Optional: Log Slack message ID (thread_ts) for debugging
                 $body = wp_remote_retrieve_body($response);
                 $json = json_decode($body, true);
            }
        }
    }
}

function wsn_notify_no_stock($product) {
    $opt = get_option('wsn_settings');
    if (!($opt['enable_no_stock'] ?? false)) return;
    $product_id = $product->get_id();
    $product_name = $product->get_name();
    $sku = $product->get_sku();
    $image_url = wp_get_attachment_image_url($product->get_image_id(), 'medium');
    $show_notice = !empty($opt['show_new_product_notice']);
    // Store thread_ts in product meta
    $thread_ts = get_post_meta($product_id, '_wsn_thread_ts', true);
    $blocks = [];
    if ($show_notice) {
        $blocks[] = [
            "type" => "section",
            "text" => ["type" => "mrkdwn", "text" => ":warning: *Inventory Alert!*"]
        ];
    }
    $blocks[] = [
        "type" => "section",
        "text" => [
            "type" => "mrkdwn",
            "text" => ":x: *Out of Stock:* `{$product_name}`\n• *SKU:* `{$sku}`"
        ],
        "accessory" => $image_url ? [
            "type" => "image",
            "image_url" => $image_url,
            "alt_text" => $product_name
        ] : null
    ];
    $new_thread_ts = wsn_send_to_slack('', $blocks, $thread_ts, 'channel_products');
    if (!$thread_ts && $new_thread_ts) {
        update_post_meta($product_id, '_wsn_thread_ts', $new_thread_ts);
    }
}

function wsn_send_to_slack($text = '', $blocks = null, $thread_ts = null, $channel_key = 'channel_general') {
    $options = get_option('wsn_settings');
    $token = $options['token'] ?? '';
    $channel = $options[$channel_key] ?? '';
    if (empty($token) || empty($channel)) return false;
    $payload = [
        'channel' => $channel,
        'text' => $text ?: 'Slack message',
        'mrkdwn' => true,
    ];
    if ($blocks) $payload['blocks'] = $blocks;
    if ($thread_ts) $payload['thread_ts'] = $thread_ts;
    $response = wp_remote_post('https://slack.com/api/chat.postMessage', [
        'headers' => [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $token,
        ],
        'body' => json_encode($payload),
    ]);
    if (is_wp_error($response)) {
        return false;
    }
    $body = json_decode(wp_remote_retrieve_body($response), true);
    return $body['ok'] ? $body['ts'] : false;
}

function wsn_hook_meta_changes($meta_id, $object_id, $meta_key, $meta_value) {
    // Confirm it's a WooCommerce product
    if (get_post_type($object_id) !== 'product') return;

    // Meta keys we care about
    $keys_of_interest = ['_price', '_regular_price', '_sale_price', '_stock', '_stock_status'];
    if (!in_array($meta_key, $keys_of_interest)) return;

    // Optional: prevent spamming
    if (get_transient("wsn_skip_{$object_id}")) return;
    set_transient("wsn_skip_{$object_id}", true, 60);

    // Trigger Slack notification
    wsn_notify_product_change_full($object_id);
}

function wsn_notify_product_change_full($product_id) {
    $opt = get_option('wsn_settings');
    if (!($opt['enable_new_product'] ?? false)) return;

    $product = wc_get_product($product_id);
    if (!$product || 'publish' !== get_post_status($product_id)) return;

    $title = get_the_title($product_id);
    $url = get_permalink($product_id);
    $price = $product->get_price();
    $sku = $product->get_sku();
    $image_url = wp_get_attachment_image_url($product->get_image_id(), 'medium');
    $emoji = ":pencil2:";

    $blocks = [[
        "type" => "section",
        "text" => [
            "type" => "mrkdwn",
            "text" => "{$emoji} *Product Updated:* <{$url}|".wsn_filter_markdown($title).">\n• *SKU:* `{$sku}`\n• *Price:* " . wsn_filter_markdown(html_entity_decode(wp_strip_all_tags(wc_price($price))))
        ],
        "accessory" => $image_url ? [
            "type" => "image",
            "image_url" => $image_url,
            "alt_text" => $title
        ] : null
    ]];

    wsn_send_to_slack('', $blocks, $thread_ts, 'channel_products');
}

function wsn_notify_product_change($post_id, $post, $update) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $opt = get_option('wsn_settings');
    if (!($opt['enable_new_product'] ?? false)) return;

    $product = wc_get_product($post_id);
    if (!$product) return;

    // NEW: Variation info
    $title = $product->is_type('variation')
        ? get_the_title($product->get_parent_id()) . ' – ' . wc_get_formatted_variation($product, true, false, true)
        : get_the_title($post_id);

    $url = get_permalink($product->get_parent_id() ?: $post_id);
    $sku = $product->get_sku();
    $price = $product->get_price();
    $image_url = wp_get_attachment_image_url($product->get_image_id(), 'medium');
    $emoji = $update ? ":pencil2:" : ":package:";

    $blocks = [
        [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => "{$emoji} *Product " . ($update ? "Updated" : "Published") . ":* <{$url}|{$title}>\n• *SKU:* `{$sku}`\n• *Price:* " . wsn_filter_markdown(html_entity_decode(wp_strip_all_tags(wc_price($price))))
            ],
            "accessory" => $image_url ? [
                "type" => "image",
                "image_url" => $image_url,
                "alt_text" => $title
            ] : null
        ]
    ];

    // Slack thread (shared by parent + variations)
    $thread_ts = get_post_meta($product->get_parent_id() ?: $post_id, '_wsn_thread_ts', true);
    $resp = wsn_send_to_slack('', $blocks, $thread_ts, 'channel_products');
    if (!$thread_ts && $resp) {
        update_post_meta($product->get_parent_id() ?: $post_id, '_wsn_thread_ts', $resp);
    }
}

function wsn_notify_order_status_change($order_id, $old_status, $new_status) {
    if ($old_status === $new_status) return;

    $order = wc_get_order($order_id);
    if (!$order) return;

    $total = $order->get_formatted_order_total();
    $total2 = html_entity_decode(wp_strip_all_tags($total));
    $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
    $customer_email = $order->get_billing_email();
    $payment_method = $order->get_payment_method_title();
    $order_date = $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : 'N/A';

    // Text summary for fallback or notification preview
    $text = "Order #{$order_id} status changed from *{$old_status}* to *{$new_status}*.";

    // Slack blocks for rich formatting
    $blocks = [
        [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => "*Order Status Changed* :truck:\nOrder *#{$order_id}* changed from *{$old_status}* to *{$new_status}*"
            ]
        ],
        [
            "type" => "section",
            "fields" => [
                [ "type" => "mrkdwn", "text" => "*Total:* {$total2}" ],
                [ "type" => "mrkdwn", "text" => "*Payment:* {$payment_method}" ],
                [ "type" => "mrkdwn", "text" => "*Customer:* {$customer_name}" ],
                [ "type" => "mrkdwn", "text" => "*Email:* {$customer_email}" ],
                [ "type" => "mrkdwn", "text" => "*Date:* {$order_date}" ],
            ]
        ]
    ];

    wsn_send_to_slack($text, $blocks, null, 'channel_orders'); // Change channel key as needed
}

function wsn_notify_low_stock($product) {
    if (!$product instanceof WC_Product) return;

    $product_name = $product->get_name();
    $product_id = $product->get_id();
    $stock_quantity = $product->get_stock_quantity();
    $product_type = $product->get_type();
    $product_url = get_edit_post_link($product_id);

    $text = "⚠️ Low stock alert for *{$product_name}* (ID: {$product_id})";

    $blocks = [
        [
            "type" => "section",
            "text" => [
                "type" => "mrkdwn",
                "text" => "*Low Stock Alert* :warning:\n*{$product_name}* (ID: {$product_id}) is running low."
            ]
        ],
        [
            "type" => "section",
            "fields" => [
                [ "type" => "mrkdwn", "text" => "*Type:*\n{$product_type}" ],
                [ "type" => "mrkdwn", "text" => "*Current Stock:*\n" . ($stock_quantity !== null ? $stock_quantity : 'N/A') ],
                [ "type" => "mrkdwn", "text" => "*Edit Product:*\n<{$product_url}|View Product>" ],
            ]
        ]
    ];

    wsn_send_to_slack($text, $blocks, null, 'channel_products'); // Adjust channel key as needed
}


function wsn_sanitize_settings($settings) {
    foreach ($settings as $key => $value) {
        $settings[$key] = is_array($value)
            ? array_map('sanitize_text_field', $value)
            : sanitize_text_field($value);
    }
    return $settings;
}


