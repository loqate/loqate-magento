#!/usr/bin/env bash
set -e

# Path to the Magento root directory (override with first argument)
MAGENTO_ROOT="${1:-$(dirname "$(realpath "$0")")/../magento2}"

if [ ! -f "$MAGENTO_ROOT/bin/magento" ]; then
  echo "Error: Magento root not found at '$MAGENTO_ROOT'."
  echo "Usage: $0 [/path/to/magento/root]"
  exit 1
fi

cd "$MAGENTO_ROOT"

echo "Installing Loqate API Integration in: $MAGENTO_ROOT"

composer require lqt/api-connector
php bin/magento module:enable Loqate_ApiIntegration
php bin/magento setup:upgrade
php bin/magento setup:di:compile

echo "Installation complete."
