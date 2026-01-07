#!/usr/bin/env bash
# Script to package the WordPress plugin into dist/subsales-management.zip
# Auto-increments patch version (e.g., 2.2.1.0 -> 2.2.1.1)
# Usage: ./scripts/package-plugin.sh
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PLUGIN_DIR="$ROOT_DIR/wordpress-plugin"
PLUGIN_FILE="$PLUGIN_DIR/subsales-management.php"
PKG_NAME="subsales-management.zip"
# Create the package at the repository root so it's always at: $ROOT_DIR/$PKG_NAME
PKG_PATH="$ROOT_DIR/$PKG_NAME"

## Get current version and auto-increment patch
echo "Checking current version..."
CURRENT_VERSION=$(grep -m1 "^ \* Version:" "$PLUGIN_FILE" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?' | head -1)
echo "Current version: $CURRENT_VERSION"

# Parse version components
IFS='.' read -r MAJOR MINOR PATCH BUILD <<< "$CURRENT_VERSION"
if [ -z "$BUILD" ]; then
    BUILD=0
fi

# Increment patch version
BUILD=$((BUILD + 1))
NEW_VERSION="$MAJOR.$MINOR.$PATCH.$BUILD"
echo "New version: $NEW_VERSION"

# Update version in plugin file
sed -i "s/^ \* Version: $CURRENT_VERSION/ * Version: $NEW_VERSION/" "$PLUGIN_FILE"
echo "Updated plugin file to version $NEW_VERSION"

## Validate PHP syntax before packaging
echo "Validating PHP syntax..."
SYNTAX_ERRORS=0
while IFS= read -r -d '' file; do
    if ! php -l "$file" > /dev/null 2>&1; then
        echo "ERROR: Syntax error in $file"
        php -l "$file"
        SYNTAX_ERRORS=$((SYNTAX_ERRORS + 1))
    fi
done < <(find "$PLUGIN_DIR" -name "*.php" -not -path "*/vendor/*" -print0)

if [ $SYNTAX_ERRORS -gt 0 ]; then
    echo "❌ Found $SYNTAX_ERRORS PHP syntax error(s). Aborting package."
    exit 1
fi
echo "✓ All PHP files passed syntax validation"

## Clean previous artifacts
rm -rf "$ROOT_DIR/subsales-management" "$PKG_PATH"

# Copy plugin into a temporary folder at repo root and zip that folder directly
cp -a "$PLUGIN_DIR" "$ROOT_DIR/subsales-management"
# Remove any VCS metadata if present
rm -rf "$ROOT_DIR/subsales-management/.git" || true

# Remove vendor test files and development artifacts to reduce size
if [ -d "$ROOT_DIR/subsales-management/vendor" ]; then
    echo "Optimizing vendor directory..."
    find "$ROOT_DIR/subsales-management/vendor" -type d -name "test" -o -name "tests" -o -name "Test" -o -name "Tests" | xargs rm -rf
    find "$ROOT_DIR/subsales-management/vendor" -name "phpunit.xml*" -o -name ".gitattributes" -o -name ".gitignore" | xargs rm -f
    
    # Remove large font files from endroid/qr-code assets (16MB!) - we don't use labels
    if [ -d "$ROOT_DIR/subsales-management/vendor/endroid/qr-code/assets" ]; then
        echo "Removing unnecessary QR code assets (fonts)..."
        rm -f "$ROOT_DIR/subsales-management/vendor/endroid/qr-code/assets/"*.otf
        rm -f "$ROOT_DIR/subsales-management/vendor/endroid/qr-code/assets/"*.ttf
    fi
fi

# Create zip at repo root
( cd "$ROOT_DIR" && zip -r "$PKG_NAME" subsales-management )

# Remove temporary folder
rm -rf "$ROOT_DIR/subsales-management"

# Summarize
echo ""
echo "======================================"
echo "Package created successfully!"
echo "======================================"
echo "Version: $NEW_VERSION"
ls -lh "$PKG_PATH"
sha256sum "$PKG_PATH"

echo ""
echo "Created $PKG_PATH"
