#!/bin/bash
set -e

MAGENTO_DIR="/workspace/magento2"
EXTENSION_DIR="/workspace/loqate-magento"

# Wait for MySQL
until mysql -h db -u magento -pmagento -e "show databases;"; do
  echo "Waiting for MySQL..."
  sleep 5
done

if [ ! -d "$MAGENTO_DIR" ]; then
  echo "Cloning Magento 2..."
  git clone https://github.com/magento/magento2.git "$MAGENTO_DIR"
  cd "$MAGENTO_DIR"
  composer install
else
  cd "$MAGENTO_DIR"
fi

if ! bin/magento info:adminuri > /dev/null 2>&1; then
  echo "Installing Magento 2..."
  bin/magento setup:install \
    --base-url=http://localhost \
    --db-host=db \
    --db-name=magento \
    --db-user=magento \
    --db-password=magento \
    --admin-firstname=Admin \
    --admin-lastname=User \
    --admin-email=admin@example.com \
    --admin-user=admin \
    --admin-password=admin123 \
    --language=en_GB \
    --currency=GBP \
    --timezone=Europe/London \
    --use-rewrites=1 \
    --elasticsearch-host=elasticsearch \
    --elasticsearch-port=9200 \
    --backend-frontname=admin

  # Disable 2FA for dev
  bin/magento module:disable Magento_TwoFactorAuth
  bin/magento setup:upgrade
  bin/magento deploy:mode:set developer
fi

# Link the extension
if [ ! -L "$MAGENTO_DIR/app/code/Loqate/ApiIntegration" ]; then
  mkdir -p "$MAGENTO_DIR/app/code/Loqate"
  ln -sfn "$EXTENSION_DIR" "$MAGENTO_DIR/app/code/Loqate/ApiIntegration"
fi

bin/magento module:enable Loqate_ApiIntegration || true
bin/magento setup:upgrade
bin/magento cache:flush 