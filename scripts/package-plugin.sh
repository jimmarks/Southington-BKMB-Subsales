#!/usr/bin/env bash
# Script to package the WordPress plugin into versioned ZIP
# Auto-increments patch version following WordPress semantic versioning (e.g., 2.3.1 -> 2.3.2)
# Usage: ./scripts/package-plugin.sh
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PLUGIN_DIR="$ROOT_DIR/wordpress-plugin"
PLUGIN_FILE="$PLUGIN_DIR/subsales-management.php"

## Get current version and auto-increment patch
echo "Checking current version..."
CURRENT_VERSION=$(grep -m1 "^ \* Version:" "$PLUGIN_FILE" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?' | head -1)
echo "Current version: $CURRENT_VERSION"

# Parse version components (supports both 3-part and 4-part versions during transition)
IFS='.' read -r MAJOR MINOR PATCH BUILD <<< "$CURRENT_VERSION"

# If we have a 4-part version (2.2.1.249), convert to 3-part by bumping minor (2.3.1)
if [ -n "$BUILD" ]; then
    MINOR=$((MINOR + 1))
    PATCH=1
    NEW_VERSION="$MAJOR.$MINOR.$PATCH"
    echo "Converting from 4-part to 3-part semantic versioning"
else
    # Standard 3-part version increment
    PATCH=$((PATCH + 1))
    NEW_VERSION="$MAJOR.$MINOR.$PATCH"
fi

echo "New version: $NEW_VERSION"

# Version-specific ZIP filename
PKG_NAME="subsales-management-${NEW_VERSION}.zip"
PKG_PATH="$ROOT_DIR/$PKG_NAME"

# Keep old versions for rollback capability (do not delete)

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
rm -rf "$ROOT_DIR/subsales-management"

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
