#!/bin/bash

set -e

# ---------------------------
# Configuration
# ---------------------------

PLUGIN_FILE=$(basename *.php)                     # e.g. plugin-name.php
PLUGIN_DIR=$(basename "$PWD")                     # current directory name
VERSION="$1"
ZIP_NAME="../${PLUGIN_DIR}.zip"
TAG="v${VERSION}"
EXCLUDES=("*.git*" "*.sh" "*.DS_Store" "build/*" "README.md" "${ZIP_NAME##*/}")

# ---------------------------
# Helper Functions
# ---------------------------

abort() {
  echo "❌ $1"
  exit 1
}

update_version() {
  echo "📝 Updating version in ${PLUGIN_FILE}..."
  sed -i "s/^\(.*Version:\s*\).*$/\1${VERSION}/" "${PLUGIN_FILE}"

  echo "📝 Updating version in readme.txt..."
  sed -i "s/^Stable tag:.*$/Stable tag: ${VERSION}/" readme.txt
}

build_zip() {
  echo "📦 Building ZIP: ${ZIP_NAME}"
  zip -r "$ZIP_NAME" . "${EXCLUDES[@]/#/-x }" > /dev/null || abort "Failed to zip plugin"
}

ensure_git_repo() {
  if ! git rev-parse --is-inside-work-tree &>/dev/null; then
    echo "⚠️ Not currently inside a Git repository. Initializing Git..."
    git init
    git add .
    git commit -m "Initial commit for ${PLUGIN_DIR}"
    echo "✅ Git repo initialized."
  fi
}

mark_safe_dir() {
  SAFE_DIR=$(git rev-parse --show-toplevel)
  if ! git config --global --get-all safe.directory | grep -qx "$SAFE_DIR"; then
    echo "⚠️ Git detected dubious ownership. Making directory safe..."
    git config --global --add safe.directory "$SAFE_DIR"
    echo "✅ Directory marked as safe for Git"
  fi
}

create_tag() {
  git add .
  git commit -m "Release v${VERSION}" || echo "🔄 No changes to commit"
  git tag -f "$TAG"
  git push origin "$TAG" 2>/dev/null || echo "⚠️ Tag push failed (may already exist or no remote)"
}

ensure_remote() {
  if ! git remote get-url origin &>/dev/null; then
    echo "🔗 No 'origin' remote found."
    read -p "Enter your GitHub username: " GHUSER
    REPO_URL="git@github.com:${GHUSER}/${PLUGIN_DIR}.git"
    if gh repo view "$GHUSER/$PLUGIN_DIR" &>/dev/null; then
      echo "✅ GitHub repo already exists. Adding remote origin..."
      git remote add origin "$REPO_URL"
    else
      echo "📡 Creating GitHub repository..."
      gh repo create "$GHUSER/$PLUGIN_DIR" --public --source=. --push || abort "Failed to create GitHub repo."
    fi
  fi
}

create_release() {
  echo "🚀 Creating GitHub release..."
  gh release create "$TAG" "$ZIP_NAME" \
    --title "Version $VERSION" \
    --notes "Release of ${PLUGIN_DIR} version $VERSION" || abort "GitHub release failed"
}

# ---------------------------
# Main Logic
# ---------------------------

[[ -z "$VERSION" ]] && abort "Usage: ./release.sh 1.0"

mark_safe_dir
ensure_git_repo
ensure_remote
update_version
build_zip
create_tag
create_release

echo "✅ Release v$VERSION complete and published to GitHub."

