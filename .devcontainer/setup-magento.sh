#!/bin/bash
set -e

MAGENTO_DIR="/workspace/magento2"
EXTENSION_DIR="/workspace/loqate-magento"

# Wait for MySQL
until mysql -h db -u magento -pmagento -e "show databases;"; do
  echo "Waiting for MySQL..."
  sleep 5
done

# Enable log_bin_trust_function_creators
mysql -h db -u root -proot -e "SET GLOBAL log_bin_trust_function_creators = 1;";

# Clone Magento 2 (if it doesn't exist)
if [ ! -d "$MAGENTO_DIR" ]; then
  echo "Cloning Magento 2..."
  git clone https://github.com/magento/magento2.git "$MAGENTO_DIR"
  cd "$MAGENTO_DIR"
  composer install
else
  cd "$MAGENTO_DIR"
fi

# Install Magento 2
echo "Installing Magento 2..."
bin/magento setup:install \
--base-url=http://localhost:8080 \
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
--search-engine=opensearch \
--opensearch-host=opensearch \
--opensearch-port=9200 \
--opensearch-enable-auth=true \
--opensearch-username=admin \
--opensearch-password='0i.N020>R!=&' \
--backend-frontname=admin

# Set permissions
find var generated vendor pub/static pub/media app/etc -type f -exec chmod g+w {} +
find var generated vendor pub/static pub/media app/etc -type d -exec chmod g+ws {} +
chown -R :www-data .
chmod u+x bin/magento

# Enable developer mode
bin/magento setup:upgrade
bin/magento deploy:mode:set developer

# Link the extension
if [ ! -L "$MAGENTO_DIR/app/code/Loqate/ApiIntegration" ]; then
  mkdir -p "$MAGENTO_DIR/app/code/Loqate"
  ln -sfn "$EXTENSION_DIR" "$MAGENTO_DIR/app/code/Loqate/ApiIntegration"
fi

bin/magento module:enable Loqate_ApiIntegration || true
bin/magento setup:upgrade
bin/magento cache:flush

# Restart nginx
# service nginx restart