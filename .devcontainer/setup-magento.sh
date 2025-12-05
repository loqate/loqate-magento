#!/bin/bash
set -euo pipefail

MAGENTO_DIR="/workspace/magento2"
EXTENSION_DIR="/workspace/loqate-magento"
MAG_REPO_PUBLIC_KEY="${MAG_REPO_PUBLIC_KEY:-}"
MAG_REPO_PRIVATE_KEY="${MAG_REPO_PRIVATE_KEY:-}"

# Validate required environment variables early to avoid opaque failures later
if [ -z "$MAG_REPO_PUBLIC_KEY" ] || [ -z "$MAG_REPO_PRIVATE_KEY" ]; then
  echo "ERROR: MAG_REPO_PUBLIC_KEY and MAG_REPO_PRIVATE_KEY must be set in the devcontainer environment." >&2
  echo "Hint: add them to devcontainer.json under 'containerEnv' or provide a .env with these variables forwarded." >&2
  exit 1
fi

# Wait for MySQL
until mysql --ssl=0 --connect-timeout=5 -h db -u magento -pmagento -e "show databases;"; do
  echo "Waiting for MySQL..."
  sleep 5
done

# Enable log_bin_trust_function_creators
mysql --ssl=0 -h db -u root -proot -e "SET GLOBAL log_bin_trust_function_creators = 1;";

echo "Configuring composer authentication..."


# Ensure Magento 2 exists; recreate if incomplete (missing bin/magento)
if [ ! -x "$MAGENTO_DIR/bin/magento" ]; then
  echo "Creating Magento 2..."
  rm -rf "$MAGENTO_DIR"
  composer create-project --repository-url="https://$MAG_REPO_PUBLIC_KEY:$MAG_REPO_PRIVATE_KEY@repo.magento.com/" magento/project-community-edition "$MAGENTO_DIR"
  cd "$MAGENTO_DIR"
  composer config repositories.magento composer https://repo.magento.com/
  composer config --global http-basic.repo.magento.com $MAG_REPO_PUBLIC_KEY $MAG_REPO_PRIVATE_KEY
  composer config repositories.loqate-local path $EXTENSION_DIR
  jq '.repositories["loqate-local"].options.symlink = true' composer.json > tmp.json && mv tmp.json composer.json
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

jq -n --arg user "$MAG_REPO_PUBLIC_KEY" --arg pass "$MAG_REPO_PRIVATE_KEY" '{
    "http-basic": {
      "repo.magento.com": {
        "username": $user,
        "password": $pass
      }
    }
  }' > $MAGENTO_DIR/var/composer_home/auth.json

# Disable 2 factor auth
bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth

# Deploy sample data
bin/magento sampledata:deploy

# Set permissions
find var generated vendor pub/static pub/media app/etc -type f -exec chmod g+w {} +
find var generated vendor pub/static pub/media app/etc -type d -exec chmod g+ws {} +
chown -R :www-data .
chmod u+x bin/magento

# Install the extension
composer require lqt/loqate-integration:@dev

# Install the Loqate API Connector
composer require lqt/api-connector

# Enable developer mode
bin/magento deploy:mode:set developer

# Enable the extension
bin/magento module:enable Loqate_ApiIntegration || true

# Compile the code
bin/magento setup:upgrade
bin/magento setup:di:compile

# Flush the cache
bin/magento cache:flush

# restart nginx if available
if command -v service >/dev/null 2>&1 && service nginx status >/dev/null 2>&1; then
  if command -v sudo >/dev/null 2>&1; then
    sudo service nginx restart || true
  else
    service nginx restart || true
  fi
else
  echo "nginx service not found; skipping restart"
fi