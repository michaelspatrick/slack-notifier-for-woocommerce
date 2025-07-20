#!/bin/bash

set -e

# === Auto-detect plugin directory and name ===
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

PLUGIN_DIR="$(basename "$SCRIPT_DIR")"
PLUGIN_FILE="$PLUGIN_DIR.php"
README_FILE="readme.txt"
VERSION="$1"
ZIP_NAME="${PLUGIN_DIR}.zip"
ZIP_PATH="$(dirname "$SCRIPT_DIR")/$ZIP_NAME"
TITLE="$(tr '[:lower:]' '[:upper:]' <<< ${PLUGIN_DIR:0:1})${PLUGIN_DIR:1} v$VERSION"
NOTES="Release of version $VERSION"

# === Functions ===
abort() {
  echo "❌ $1"
  exit 1
}

# === Pre-checks ===
if [ -z "$VERSION" ]; then
  echo "Usage: ./release.sh <version>"
  exit 1
fi

command -v gh >/dev/null || abort "GitHub CLI (gh) not found."

# === Git repo check ===
if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "⚠️ Not currently inside a Git repository. Initializing Git..."
  git init
  git add .
  git commit -m "Initial commit for $PLUGIN_DIR"
fi

# === Check for missing Git remote and offer to add one ===
if ! git remote get-url origin >/dev/null 2>&1; then
  echo "🔗 No 'origin' remote found."
  read -p "Enter your GitHub username: " GH_USER
  REMOTE_URL="git@github.com:${GH_USER}/${PLUGIN_DIR}.git"
  echo "➡️  Adding remote: $REMOTE_URL"
  git remote add origin "$REMOTE_URL"
  echo "🚀 Pushing to origin..."
  git branch -M main
  git push -u origin main
fi

# === Auth check ===
if ! gh auth status >/dev/null 2>&1 && [ -z "$GH_TOKEN" ]; then
  abort "GitHub CLI is not authenticated. Run 'gh auth login' or export GH_TOKEN."
fi

# === Update version in plugin file ===
if [ -f "$SCRIPT_DIR/$PLUGIN_FILE" ]; then
  echo "📝 Updating version in $PLUGIN_FILE..."
  sed -i "s/^\(\s*\*\s*Version:\s*\).*/\1$VERSION/" "$SCRIPT_DIR/$PLUGIN_FILE"
else
  echo "⚠️  Plugin file $PLUGIN_FILE not found — skipping version bump."
fi

# === Update version in readme.txt ===
if [ -f "$SCRIPT_DIR/$README_FILE" ]; then
  echo "📝 Updating version in $README_FILE..."
  sed -i "s/^Stable tag:.*/Stable tag: $VERSION/" "$SCRIPT_DIR/$README_FILE"
  sed -i "s/^= [0-9.]\+ =/= $VERSION =/" "$SCRIPT_DIR/$README_FILE"
else
  echo "⚠️  readme.txt not found — skipping readme version bump."
fi

# === Remove existing ZIP ===
rm -f "$ZIP_PATH"

# === Build ZIP ===
echo "📦 Building ZIP: $ZIP_PATH"
cd "$(dirname "$SCRIPT_DIR")"
zip -r "$ZIP_NAME" "$PLUGIN_DIR" \
  -x "$PLUGIN_DIR/.git/*" \
  -x "$PLUGIN_DIR/.gitignore" \
  -x "$PLUGIN_DIR/build/*" \
  -x "$PLUGIN_DIR/release.sh" \
  -x "$PLUGIN_DIR/README.md" \
  -x "$PLUGIN_DIR/$ZIP_NAME" || abort "Failed to create ZIP"

cd "$SCRIPT_DIR"

# === Delete existing tag and release ===
if git rev-parse "v$VERSION" >/dev/null 2>&1; then
  echo "🧹 Deleting existing Git tag v$VERSION..."
  git tag -d "v$VERSION"
  git push origin ":refs/tags/v$VERSION"
fi

if gh release view "v$VERSION" >/dev/null 2>&1; then
  echo "🧹 Deleting existing GitHub release v$VERSION..."
  gh release delete "v$VERSION" --yes
fi

# === Create tag and push ===
echo "🔖 Creating Git tag v$VERSION..."
git tag -a "v$VERSION" -m "Release $VERSION"
git push origin "v$VERSION"

# === Create GitHub Release ===
echo "🚀 Creating GitHub release..."
gh release create "v$VERSION" "$ZIP_PATH" --title "$TITLE" --notes "$NOTES" || abort "Release failed"

echo "✅ GitHub release $VERSION for $PLUGIN_DIR published successfully!"

