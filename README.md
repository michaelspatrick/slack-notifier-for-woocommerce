# WooCommerce Slack Notifier

A WordPress plugin that sends Slack notifications for important WooCommerce and WordPress events, including orders, stock alerts, product changes, and more.

---

## ✅ Features

### 🔔 Order Notifications

- New order alerts with:
  - Ordered item details
  - Quantities and prices
  - Shipping info, order notes, and status
  - Customer contact info and payment method
  - Discount/coupon codes
  - Link to view the order in WooCommerce
- Threaded Slack updates for each order
- Emoji summary for high-value orders (e.g., 🔥 for orders over $200)

### 📦 Product Stock Alerts

- Low stock and out-of-stock notifications with:
  - Product name and SKU
  - Image preview
  - Markdown formatting
- Grouped into threads per product
- Optional banner for product alerts (⚠️ Inventory Alert)

### 🛍️ Product Change Notifications

- Alerts for:
  - New products added
  - Product updates (price, title, etc.)
- Includes product:
  - Name and permalink
  - SKU and price
  - Image preview

### 🧠 Smart Formatting

- Uses Slack **blocks** and **markdown**
- Avoids HTML escaping issues
- Threaded context for ongoing updates

---

## ⚙️ Settings

Available under **Settings → Slack Notifier**:

- Slack bot token and channel
- Enable/disable:
  - New order alerts
  - Low/no stock alerts
  - Missing product info (weight/dimensions)
  - New/updated product messages
  - Banner warning for product alerts

---

## 🧠 Supported Notifications

| Event Type           | Setting Key             |
|----------------------|--------------------------|
| New Order            | `New Orders`             |
| Low Stock            | `Low Stock`              |
| Out of Stock         | `No Stock`               |
| Missing Product Info | `Missing Product Info`   |
| New Blog Post        | `New Blog Posts`         |
| New Review           | `New Reviews`            |
| New Customer         | `New Customers`          |
| Backorders           | `Backorders`             |

---

## 🚀 Installation

1. Create a zip of the plugin directory (`woocommerce-slack-notifier`).
2. In your WordPress dashboard, go to **Plugins → Add New → Upload Plugin**.
3. Select the ZIP file and click **Install Now**, then **Activate**.

---

## 🔧 Configuration

1. Navigate to **Settings → Slack Notifier**.
2. Enter:
   - **Slack Bot Token** – your `xoxb-` bot token from your Slack app
   - **Slack Channel** – the name (e.g., `#orders`) or ID (e.g., `C01ABC1234`)
3. Select which events to receive notifications for.
4. Click **Save Changes**.

> ✅ Use the **Send Test Slack Message** button to verify connectivity.

---

## 🧪 Requirements

- WordPress 5.0+
- WooCommerce 4.0+
- PHP 7.2+ (7.4+ recommended)

---

## 💬 Slack Bot Setup

1. Create a Slack app: https://api.slack.com/apps
2. Enable the **chat:write** scope for the bot.
3. Install the app to your workspace.
4. Copy the generated `xoxb-` token into the plugin settings.
5. Invite the bot to your desired channel:
   ```bash
   /invite @your-bot-name
   ```

---

## 🛠️ Development

To contribute or customize:

```bash
git clone https://github.com/michaelspatrick/woocommerce-slack-notifier.git
```

---

## 📃 License

This plugin is licensed under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
