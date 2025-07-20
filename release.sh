#!/bin/bash

set -e

# ─── CONFIG ─────────────────────────────────────────────────────────────
PLUGIN_DIR="$(basename "$PWD")"
VERSION="$1"
TAG="v$VERSION"
ZIP_NAME="../$PLUGIN_DIR.zip"

# Files to exclude from zip
EXCLUDES=(
  ".git*"
  "release.sh"
  "README.md"
  "readme.txt~"
  "$ZIP_NAME"
  "build/*"
)

# ─── HELPERS ─────────────────────────────────────────────────────────────
abort() {
  echo "❌ $1"
  exit 1
}

# ─── CHECKS ─────────────────────────────────────────────────────────────
[[ -z "$VERSION" ]] && abort "Usage: ./release.sh <version>"

if ! command -v git &>/dev/null; then
  abort "Git not found"
fi

if ! command -v gh &>/dev/null; then
  abort "GitHub CLI (gh) not found"
fi

if git rev-parse --is-inside-work-tree &>/dev/null; then
  echo "📁 Inside Git repo"
else
  echo "⚠️ Not in a Git repo. Initializing..."
  git init
  git add .
  git commit -m "Initial commit for $PLUGIN_DIR"
fi

# ─── FIX DUBIOUS OWNERSHIP ─────────────────────────────────────────────
if git config --show-origin --get-regexp 'safe.directory' | grep -q "$PWD"; then
  :
else
  echo "⚠️ Git detected dubious ownership. Marking directory safe..."
  git config --global --add safe.directory "$PWD"
  echo "✅ Directory marked safe"
fi

# ─── REMOTE SETUP ───────────────────────────────────────────────────────
if ! git remote | grep -q origin; then
  echo "🔗 No 'origin' remote found."
  read -p "Enter your GitHub username: " GH_USER
  REPO_NAME="$PLUGIN_DIR"
  if gh repo view "$GH_USER/$REPO_NAME" &>/dev/null; then
    echo "✅ GitHub repo already exists. Adding remote origin..."
    git remote add origin "https://github.com/$GH_USER/$REPO_NAME.git"
  else
    echo "📡 Creating GitHub repo..."
    gh repo create "$GH_USER/$REPO_NAME" --public --source=. --push || abort "Failed to create GitHub repo"
  fi
fi

# ─── PUSH MAIN BRANCH ────────────────────────────────────────────────────
if ! git ls-remote --exit-code origin main &>/dev/null; then
  echo "📤 Pushing main branch to origin..."
  git checkout -B main
  git push -u origin main || abort "Failed to push main branch"
else
  echo "✅ main branch already pushed"
fi

# ─── UPDATE VERSION IN FILES ─────────────────────────────────────────────
echo "📝 Updating version in $PLUGIN_DIR.php..."
sed -i "s/^\\(\\s*Version:\\s*\\).*$/\\1$VERSION/" "$PLUGIN_DIR.php"

echo "📝 Updating version in readme.txt..."
sed -i "s/^\\(\\s*Stable tag:\\s*\\).*$/\\1$VERSION/" readme.txt

# ─── ZIP PLUGIN ─────────────────────────────────────────────────────────
echo "📦 Building ZIP: $ZIP_NAME"
zip -r "$ZIP_NAME" . -x "${EXCLUDES[@]}" || abort "Failed to zip plugin"

# ─── COMMIT AND TAG ─────────────────────────────────────────────────────
git add .
git commit -m "Release v$VERSION" || echo "Nothing to commit"
git tag -f "$TAG"
git push origin "$TAG" || echo "⚠️ Tag push failed (may already exist or no remote)"

# ─── DELETE EXISTING GITHUB RELEASE ─────────────────────────────────────
if gh release view "$TAG" &>/dev/null; then
  echo "⚠️ GitHub release $TAG exists. Deleting..."
  gh release delete "$TAG" --yes || abort "Failed to delete release"
fi

# ─── CREATE GITHUB RELEASE ──────────────────────────────────────────────
echo "🚀 Creating GitHub release..."
gh release create "$TAG" "$ZIP_NAME" --title "Version $VERSION" --notes "Release version $VERSION" || abort "GitHub release failed"

echo "✅ Release v$VERSION completed and published to GitHub."

