#!/bin/bash

# ========== CONFIG ==========
PLUGIN_SLUG=$(basename "$PWD")
ZIP_NAME="../$PLUGIN_SLUG.zip"
MAIN_FILE="$PLUGIN_SLUG.php"
README_FILE="readme.txt"
EXCLUDES=(".git/*" ".gitignore" "build/*" "release.sh" "README.md" "$ZIP_NAME")
# ============================

abort() { echo "❌ $1"; exit 1; }

VERSION="$1"
[ -z "$VERSION" ] && abort "Usage: ./release.sh <version>"

# Step 1: Check for Git repo
if ! git rev-parse --is-inside-work-tree &>/dev/null; then
  echo "⚠️ Not currently inside a Git repository. Initializing Git..."
  git init || abort "Git init failed"
  git add . && git commit -m "Initial commit for $PLUGIN_SLUG"
  echo "✅ Git repo initialized. Please manually add a remote if needed."
fi

# Step 2: Handle Git "dubious ownership"
if git status 2>&1 | grep -q "dubious ownership"; then
  echo "⚠️ Git detected dubious ownership. Making directory safe..."
  git config --global --add safe.directory "$PWD" || abort "Failed to mark directory safe"
  echo "✅ Directory marked as safe for Git"
fi

# Step 3: Update plugin version in PHP and readme.txt
echo "📝 Updating version in $MAIN_FILE..."
sed -i "s/\(Version:\s*\).*/\1$VERSION/" "$MAIN_FILE" || abort "Failed to update $MAIN_FILE"

echo "📝 Updating version in $README_FILE..."
sed -i "s/Stable tag:\s*.*/Stable tag: $VERSION/" "$README_FILE" || abort "Failed to update $README_FILE"

# Step 4: Build zip
echo "📦 Building ZIP: $ZIP_NAME"
rm -f "$ZIP_NAME"
ZIP_EXCLUDE_ARGS=()
for exclude in "${EXCLUDES[@]}"; do
  ZIP_EXCLUDE_ARGS+=("-x" "$PLUGIN_SLUG/$exclude")
done
(cd .. && zip -r "$ZIP_NAME" "$PLUGIN_SLUG" "${ZIP_EXCLUDE_ARGS[@]}") || abort "Failed to zip plugin"

# Step 5: Git tag
echo "🔖 Creating Git tag v$VERSION..."
git tag -d "v$VERSION" &>/dev/null
git tag "v$VERSION"
git add . && git commit -am "Release v$VERSION"

# Step 6: Ensure remote exists or create it
if ! git remote get-url origin &>/dev/null; then
  echo "🔗 No 'origin' remote found."
  read -rp "Enter your GitHub username: " GH_USER
  REPO_NAME="$PLUGIN_SLUG"
  REPO_URL="https://github.com/$GH_USER/$REPO_NAME.git"

  if gh repo view "$GH_USER/$REPO_NAME" &>/dev/null; then
    echo "✅ GitHub repo already exists. Adding remote origin..."
    git remote add origin "$REPO_URL" || abort "Failed to add remote"
    git push -u origin main
  else
    echo "📡 Creating GitHub repository..."
    gh repo create "$GH_USER/$REPO_NAME" --source=. --public --push || abort "Failed to create GitHub repo."
  fi
else
  echo "🚀 Pushing to existing remote..."
  git push origin main
  git push origin "v$VERSION"
fi

# Step 7: Create GitHub release
echo "🚀 Creating GitHub release..."
gh release delete "v$VERSION" -y &>/dev/null
gh release create "v$VERSION" "$ZIP_NAME" --title "v$VERSION" --notes "Release $VERSION" || abort "GitHub release failed"

echo "✅ Release v$VERSION complete and uploaded to GitHub!"

