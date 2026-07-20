#!/usr/bin/env bash
#
# Sync the local extension working copy into the Magento install.
#
# Why this exists: Composer installs a same-filesystem path repository as a SYMLINK,
# but Magento refuses to render templates (and read other view files) from a module
# whose *real* path is outside the Magento base dir — the symlinked module registers
# its real path (/workspace/loqate-magento) via registration.php's __DIR__, which sits
# outside /workspace/magento2, so you hit:
#   Path "/workspace/loqate-magento/view/.../config.phtml" cannot be used with
#   directory "/workspace/magento2/"
# A bind mount would avoid the copy while keeping live edit, but this container lacks
# the privileges to bind-mount. So we mirror the source into vendor/ as REAL files.
#
# Run this after editing the extension source. It is safe to run repeatedly.
#
# Usage:
#   sync-extension.sh            # copy source + flush cache (fast; for PHP/template edits)
#   sync-extension.sh --full     # also setup:upgrade + di:compile (for di.xml/config/schema changes)
#   sync-extension.sh --no-build # copy source only (no cache flush / build; used by setup-magento.sh)
set -e

MAGENTO_DIR="${MAGENTO_DIR:-/workspace/magento2}"
EXTENSION_DIR="${EXTENSION_DIR:-/workspace/loqate-magento}"
TARGET="$MAGENTO_DIR/vendor/gbg-loqate/loqate-integration"

MODE="flush"
case "${1:-}" in
  --full)     MODE="full" ;;
  --no-build) MODE="none" ;;
  "")         MODE="flush" ;;
  *) echo "Unknown option: $1"; echo "Usage: $0 [--full|--no-build]"; exit 1 ;;
esac

if [ ! -f "$EXTENSION_DIR/registration.php" ]; then
  echo "Error: extension source not found at '$EXTENSION_DIR'."
  exit 1
fi

echo "Syncing $EXTENSION_DIR -> $TARGET (real copy)"
rm -rf "$TARGET"
mkdir -p "$TARGET"
# Mirror the module, excluding VCS / dev-container / nested composer + node deps.
tar --exclude=.git \
    --exclude=.devcontainer \
    --exclude=./vendor \
    --exclude=node_modules \
    --exclude=.github \
    -C "$EXTENSION_DIR" -cf - . | tar -C "$TARGET" -xf -

cd "$MAGENTO_DIR"
# Ensure Composer's PSR-4 map points at the copied files (harmless if already correct).
composer dump-autoload >/dev/null 2>&1 || true

case "$MODE" in
  none)  echo "Copied (no build). Run 'bin/magento setup:upgrade && setup:di:compile' as needed." ;;
  full)  bin/magento setup:upgrade && bin/magento setup:di:compile && bin/magento cache:flush ;;
  flush) bin/magento cache:flush ;;
esac

echo "Done."
