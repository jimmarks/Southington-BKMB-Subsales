#!/usr/bin/env bash
# Script to package the WordPress plugin into dist/subsales-management.zip
# Automatically increments the patch version (2.0.0.X -> 2.0.0.X+1)
# Usage: ./scripts/package-plugin.sh
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

PLUGIN_DIR="$ROOT_DIR/wordpress-plugin"
PLUGIN_FILE="$PLUGIN_DIR/subsales-management.php"
PKG_NAME="subsales-management.zip"
# Create the package at the repository root so it's always at: $ROOT_DIR/$PKG_NAME
PKG_PATH="$ROOT_DIR/$PKG_NAME"

## Auto-increment patch version
echo "Checking current version..."
CURRENT_VERSION=$(grep -m1 "^ \* Version:" "$PLUGIN_FILE" | grep -oE '[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?' | head -1)
echo "Current version: $CURRENT_VERSION"

# Extract version parts (expecting format: MAJOR.MINOR.PATCH or MAJOR.MINOR.PATCH.BUILD)
if [[ $CURRENT_VERSION =~ ^([0-9]+)\.([0-9]+)\.([0-9]+)(\.([0-9]+))?$ ]]; then
    MAJOR="${BASH_REMATCH[1]}"
    MINOR="${BASH_REMATCH[2]}"
    PATCH="${BASH_REMATCH[3]}"
    BUILD="${BASH_REMATCH[5]:-0}"  # Default to 0 if not present
    
    # Increment the last number (build if 4-part, patch if 3-part)
    if [ -n "${BASH_REMATCH[4]}" ]; then
        # 4-part version: increment build number
        NEW_BUILD=$((BUILD + 1))
        NEW_VERSION="${MAJOR}.${MINOR}.${PATCH}.${NEW_BUILD}"
    else
        # 3-part version: increment patch number
        NEW_PATCH=$((PATCH + 1))
        NEW_VERSION="${MAJOR}.${MINOR}.${NEW_PATCH}"
    fi
    
    echo "Incrementing to: $NEW_VERSION"
    
    # Update version in plugin header (line 6) - preserve any text after version
    sed -i -E "s/(^ \* Version: *)([0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?)(.*)/\1$NEW_VERSION\4/" "$PLUGIN_FILE"
    
    # Update SUBSALES_VERSION constant (line 37)
    sed -i -E "s/(define\( 'SUBSALES_VERSION', ')([0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?)(' \);)/\1$NEW_VERSION\4/" "$PLUGIN_FILE"
    
    echo "✓ Version updated in $PLUGIN_FILE"
else
    echo "⚠ Warning: Could not parse version number '$CURRENT_VERSION'. Skipping auto-increment."
    NEW_VERSION="$CURRENT_VERSION"
fi

## Clean previous artifacts
rm -rf "$ROOT_DIR/subsales-management" "$PKG_PATH"

# Copy plugin into a temporary folder at repo root and zip that folder directly
cp -a "$PLUGIN_DIR" "$ROOT_DIR/subsales-management"
# Remove any VCS metadata if present
rm -rf "$ROOT_DIR/subsales-management/.git" || true

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
