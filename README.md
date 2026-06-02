# Loqate Magento 2 API Integration

## What is the Loqate API Integration?

Performs address capture and data validation (email, phone number and address) using Loqate API.

## Download

### Download via composer

Request composer to fetch the module:

```
composer require loqate-integration/adobe
```

### Manual Download

Download & copy the git content to `/app/code/Loqate/ApiIntegration`.

## Install

Please run the following commands after you download the module.

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

1. **Create a devcontainer.env**: Before opening the devcontainer you will need to create a `devcontainer.env` file which you can copy from [`devcontainer.env.example`](.devcontainer/devcontainer.env.example).
1. **Open in VS Code**: Use the "Reopen in Container" command (requires the Remote - Containers extension).
1. **Wait for Setup**: The devcontainer will build, install dependencies, and set up Magento 2 automatically.
1. **Access Magento**:
   - Storefront: [http://localhost:8080](http://localhost:8080)
   - Admin: [http://localhost:8080/admin](http://localhost:8080/admin)
   - Default admin user: `admin` / `admin123`
1. **Live Extension Development**: Your extension source is mounted into the running Magento instance. Changes are reflected immediately after running `bin/magento setup:upgrade` and clearing cache.

### Services

- PHP-FPM (8.1)
- Nginx
- MySQL 8
- Opensearch
- Redis

### Notes

- The first startup may take several minutes (Magento install, Composer, DB setup).
- The extension is symlinked into `app/code/Loqate/ApiIntegration`.
- To re-run setup, use [`.devcontainer/setup-magento.sh`](.devcontainer/setup-magento.sh) inside the container.
- If you have any DNS issues, you will need to copy your Zscaler certificate into the PHP container - see the Zscaler workaround comment in the [`Dockerfile`](.devcontainer/Dockerfile).

## Useful helpers

- `php -r '$e=include "app/etc/env.php"; $d=$e["db"]["connection"]["default"]; printf("mysql -h%s -u%s -p%s %s\n",$d["host"],$d["username"],$d["password"],$d["dbname"]);'` Will extract the command to access mysql within the devcontainer, currently that command is `mysql -hdb -umagento -pmagento magento`
- `bin/magento config:show` will list all of the config currently set in the instance, this can be set with `bin/magento config:set <PATH> <VALUE>`


## Deployment

Releasing a new version requires two separate deployments: one to the **Adobe Marketplace** and one via **Composer**. Before proceeding with either, update the version number in both [`composer.json`](composer.json) and [`etc/module.xml`](etc/module.xml) to reflect the new release, then commit and push the change.

### Adobe Marketplace

1. Create a zip archive of the module directory. Ensure that `.devcontainer/devcontainer.env` is excluded, as it contains sensitive credentials. The following command will produce a clean archive:
   ```bash
   zip -r loqate-integration.zip . -x "*.git*" -x ".devcontainer/devcontainer.env" -x ".devcontainer/zscaler.crt"
   ```
2. Log in to your Adobe account at [account.magento.com](https://account.magento.com/customer/account/login).
3. Navigate to the [extension versions page](https://commercedeveloper.adobe.com/extensions/versions/gbg-loqate-loqate-integration) on the Adobe Commerce Developer Portal.
4. Upload the zip archive.
5. Adobe will automatically process and scan the submission. This can take up to **3 days**.
   - If the scan **fails**, review the provided feedback, address the reported issues, and resubmit.
   - If the scan **passes**, the extension will be published to the marketplace within the hour.

### Composer

1. Create a new Git tag in GitHub matching the release version number (e.g. `2.0.4`) and push it:
   ```bash
   git tag 2.0.4
   git push origin 2.0.4
   ```
2. Composer will automatically detect the new tag and make the release available.