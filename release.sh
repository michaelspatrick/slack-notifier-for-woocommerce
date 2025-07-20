#!/bin/bash

set -e

VERSION=$1
PLUGIN_DIR=$(basename "$PWD")
ZIP_NAME="../${PLUGIN_DIR}.zip"

if [[ -z "$VERSION" ]]; then
  echo "❌ Usage: ./release.sh <version>"
  exit 1
fi

# Make current directory safe for Git if needed
if ! git status &>/dev/null; then
  echo "⚠️ Git detected dubious ownership or uninitialized repo. Attempting to fix..."
  git config --global --add safe.directory "$PWD" || true
fi

# Initialize Git if not already a repo
if ! git rev-parse --is-inside-work-tree &>/dev/null; then
  echo "⚠️ Not currently inside a Git repository. Initializing Git..."
  git init
  git add .
  git commit -m "Initial commit for $PLUGIN_DIR"
  echo "✅ Git repo initialized. You may need to manually add a remote:"
  echo "   git remote add origin <git@github.com:yourusername/$PLUGIN_DIR.git>"
fi

# Update version in plugin main file
if [[ -f "$PLUGIN_DIR.php" ]]; then
  echo "📝 Updating version in $PLUGIN_DIR.php..."
  sed -i "s/^\( \?\* Version:\s*\).*/\1$VERSION/" "$PLUGIN_DIR.php"
fi

# Update version in readme.txt
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

# Check for remote origin
if ! git remote get-url origin &>/dev/null; then
  echo "🔗 No 'origin' remote found."
  read -p "Enter your GitHub username: " GH_USER
  REPO_NAME="$PLUGIN_DIR"
  echo "📡 Attempting to create GitHub repository..."
  if ! gh repo create "$GH_USER/$REPO_NAME" --public --source=. --push; then
    echo "✅ GitHub repo likely already exists. Adding origin..."
    git remote add origin "https://github.com/$GH_USER/$REPO_NAME.git"
    git push -u origin main || echo "⚠️ Initial push may fail if branch is not 'main'."
  fi
fi

# Push changes and tag
git push origin main || true
git push origin "v$VERSION" || true

# Create GitHub release
echo "🚀 Creating GitHub release..."
if ! gh release create "v$VERSION" "$ZIP_NAME" --title "v$VERSION" --notes "Release version $VERSION"; then
  echo "❌ GitHub release failed"
  exit 1
fi

echo "✅ Release v$VERSION complete!"

