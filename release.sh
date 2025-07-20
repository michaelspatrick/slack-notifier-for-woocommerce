#!/bin/bash
set -e

VERSION=$1
PLUGIN_DIR=$(basename "$PWD")
ZIP_NAME="../${PLUGIN_DIR}.zip"

if [[ -z "$VERSION" ]]; then
  echo "❌ Usage: ./release.sh <version>"
  exit 1
fi

# Mark dir safe for Git if needed
if ! git status &>/dev/null; then
  echo "⚠️ Git detected dubious ownership or uninitialized repo. Attempting to fix..."
  git config --global --add safe.directory "$PWD" || true
fi

# Init Git if needed
if ! git rev-parse --is-inside-work-tree &>/dev/null; then
  echo "⚠️ Not in a Git repo. Initializing..."
  git init
  git add .
  git commit -m "Initial commit for $PLUGIN_DIR"
fi

# Update version in PHP and readme
if [[ -f "$PLUGIN_DIR.php" ]]; then
  echo "📝 Updating version in $PLUGIN_DIR.php..."
  sed -i "s/^\( \?\* Version:\s*\).*/\1$VERSION/" "$PLUGIN_DIR.php"
fi

if [[ -f "readme.txt" ]]; then
  echo "📝 Updating version in readme.txt..."
  sed -i "s/^Stable tag:\s*.*/Stable tag: $VERSION/" readme.txt
fi

# Build ZIP
echo "📦 Building ZIP: $ZIP_NAME"
rm -f "$ZIP_NAME"
zip -r "$ZIP_NAME" . \
  -x "*.git*" \
     ".gitignore" \
     "*.sh" \
     "*.zip" \
     "README.md" \
     "build/*" \
     "release.sh"

# Commit and tag
git add .
git commit -m "Release v$VERSION"
git tag -f "v$VERSION"

# Check for remote
if ! git remote get-url origin &>/dev/null; then
  echo "🔗 No 'origin' remote found."
  read -p "Enter your GitHub username: " GH_USER
  REPO_NAME="$PLUGIN_DIR"
  echo "📡 Creating GitHub repo (public)..."
  if ! gh repo create "$GH_USER/$REPO_NAME" --public --source=. --push; then
    echo "✅ Repo likely exists. Adding remote..."
    git remote add origin "git@github.com:$GH_USER/$REPO_NAME.git"
    git branch -M main || true
    git push -u origin main || true
  fi
else
  echo "✅ Remote already set. Skipping repo creation."
  # Only push tag using GH CLI (if remote is HTTPS, avoid asking for password)
  echo "📤 Pushing tag using GitHub CLI..."
  gh repo set-default "$(git remote get-url origin | sed 's/.*github.com[:\/]\(.*\)\.git/\1/')"
  git push --tags || echo "⚠️ Tag push failed (may already exist)"
fi

# Create GitHub release
echo "🚀 Creating GitHub release..."
gh release create "v$VERSION" "$ZIP_NAME" --title "v$VERSION" --notes "Release version $VERSION" || {
  echo "❌ GitHub release failed"
  exit 1
}

echo "✅ Release v$VERSION complete!"

