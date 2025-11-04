#!/usr/bin/env bash
# Script to package the WordPress plugin into dist/subsales-management.zip
# Usage: ./scripts/package-plugin.sh
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

DIST_DIR="$ROOT_DIR/dist"
PLUGIN_DIR="$ROOT_DIR/wordpress-plugin"
PKG_NAME="subsales-management.zip"
PKG_PATH="$DIST_DIR/$PKG_NAME"

# Clean previous artifacts
rm -rf "$DIST_DIR/subsales-management" "$PKG_PATH"

# Copy plugin into a single top-level folder inside dist
mkdir -p "$DIST_DIR"
cp -a "$PLUGIN_DIR" "$DIST_DIR/subsales-management"
# Remove any VCS metadata if present
rm -rf "$DIST_DIR/subsales-management/.git" || true

# Create zip
( cd "$DIST_DIR" && zip -r "$PKG_NAME" subsales-management )

# Summarize
ls -lh "$PKG_PATH"
sha256sum "$PKG_PATH"

echo "Created $PKG_PATH"
