#!/usr/bin/env bash
# Script to package the WordPress plugin into dist/subsales-management.zip
# Usage: ./scripts/package-plugin.sh
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PLUGIN_DIR="$ROOT_DIR/wordpress-plugin"
PKG_NAME="subsales-management.zip"
# Create the package at the repository root so it's always at: $ROOT_DIR/$PKG_NAME
PKG_PATH="$ROOT_DIR/$PKG_NAME"

## Clean previous artifacts
rm -rf "$ROOT_DIR/subsales-management" "$PKG_PATH"

# Copy plugin into a temporary folder at repo root and zip that folder directly
cp -a "$PLUGIN_DIR" "$ROOT_DIR/subsales-management"
# Remove any VCS metadata if present
rm -rf "$ROOT_DIR/subsales-management/.git" || true

# Create zip at repo root
( cd "$ROOT_DIR" && zip -r "$PKG_NAME" subsales-management )

# Summarize
ls -lh "$PKG_PATH"
sha256sum "$PKG_PATH"

echo "Created $PKG_PATH"
