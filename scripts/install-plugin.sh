#!/usr/bin/env bash
# Usage:
# ./install-plugin.sh /absolute/path/to/subsales-management.zip user@yourserver.com /remote/path/to/wp-root
# Example:
# ./install-plugin.sh /home/dev/subsales-management.zip ubuntu@13.58.131.153 /var/www/html

set -euo pipefail
ZIP_PATH="$1"
REMOTE_HOST="$2"
WP_ROOT="$3"
REMOTE_TMP="/tmp/$(basename "$ZIP_PATH")"

if [ ! -f "$ZIP_PATH" ]; then
  echo "Zip not found: $ZIP_PATH" >&2
  exit 2
fi

echo "Uploading $ZIP_PATH to $REMOTE_HOST:$REMOTE_TMP"
scp "$ZIP_PATH" "$REMOTE_HOST:$REMOTE_TMP"

echo "Installing plugin on remote WP (path: $WP_ROOT) using WP-CLI"
ssh "$REMOTE_HOST" "set -euo pipefail; cd '$WP_ROOT'; wp plugin install '$REMOTE_TMP' --activate --allow-root"

echo "Cleaning up remote temporary file"
ssh "$REMOTE_HOST" "rm -f '$REMOTE_TMP' || true"

echo "Done. Plugin installed and activated."
