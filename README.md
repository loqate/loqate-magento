# Loqate Magento 2 API Integration

## What is the Loqate API Integration?

Performs address capture and data validation (email, phone number and address) using Loqate API.

##Download
###Download via composer

Request composer to fetch the module:

```
composer require loqate-integration/adobe
```

###Manual Download

Download & copy the git content to /app/code/Loqate/ApiIntegration

## Install

Please run the following commands after you download the module

```
php bin/magento module:enable Loqate_ApiIntegration
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

## Configuration Instructions

The configuration for the module is located under Stores -> Configuration -> Loqate.

## Magento 2 DevContainer Setup

This repository includes a [devcontainer](.devcontainer/) for rapid Magento 2 extension development.

### Quick Start

1. **Open in VS Code**: Use the "Reopen in Container" command (requires the Remote - Containers extension).
2. **Wait for Setup**: The devcontainer will build, install dependencies, and set up Magento 2 automatically.
3. **Access Magento**:
   - Storefront: [http://localhost](http://localhost)
   - Admin: [http://localhost/admin](http://localhost/admin)
   - Default admin user: `admin` / `admin123`
4. **Live Extension Development**: Your extension source is mounted into the running Magento instance. Changes are reflected immediately after running `bin/magento setup:upgrade` and clearing cache.

### Services

- PHP-FPM (8.1)
- Nginx
- MySQL 8
- Elasticsearch 7
- Redis

### Notes

- The first startup may take several minutes (Magento install, Composer, DB setup).
- The extension is symlinked into `app/code/Loqate/ApiIntegration`.
- To re-run setup, use `.devcontainer/setup-magento.sh` inside the container.
