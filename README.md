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

Before installing, ensure your Magento Marketplace credentials are set in [`.devcontainer/devcontainer.env`](.devcontainer/devcontainer.env):

```
MAG_REPO_PUBLIC_KEY=your_public_key
MAG_REPO_PRIVATE_KEY=your_private_key
```

Obtain these at [https://commercemarketplace.adobe.com](https://commercemarketplace.adobe.com) under My Profile -> Access Keys.

> **Already have a running container?** You do not need to restart. Instead, configure Composer auth directly in the terminal:
> ```
> composer config --global http-basic.repo.magento.com your_public_key your_private_key
> ```
> Alternatively, export the variables for the current shell session:
> ```
> export MAG_REPO_PUBLIC_KEY=your_public_key
> export MAG_REPO_PRIVATE_KEY=your_private_key
> ```
> Changes to `devcontainer.env` are only picked up on container restart.

Run the provided install script from the extension root, passing your Magento root directory as an argument:

```
./install.sh /path/to/magento/root
```

This will run the following commands in your Magento root directory:

```
composer require lqt/api-connector
php bin/magento module:enable Loqate_ApiIntegration
php bin/magento setup:upgrade
php bin/magento setup:di:compile
```

## Configuration Instructions

The configuration for the module is located under Stores -> Configuration -> Loqate.

You will need a **Loqate API key** to use this module. You can obtain one by registering at [https://www.loqate.com](https://www.loqate.com). Enter your key under Stores -> Configuration -> Loqate -> API Key.

## Magento 2 DevContainer Setup

This repository includes a [devcontainer](.devcontainer/) for rapid Magento 2 extension development.

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (or Docker Engine on Linux)
- [VS Code](https://code.visualstudio.com/) with the [Dev Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers) extension

### Quick Start

1. **Create a devcontainer.env**: Before opening the devcontainer you will need to create a `devcontainer.env` file which you can copy from [`devcontainer.env.example`](.devcontainer/devcontainer.env.example). Fill in your **Magento Marketplace credentials** (`MAG_REPO_PUBLIC_KEY` and `MAG_REPO_PRIVATE_KEY`) — obtain these at [https://commercemarketplace.adobe.com](https://commercemarketplace.adobe.com) under My Profile -> Access Keys.
1. **Open in VS Code**: Use the "Reopen in Container" command (requires the [Dev Containers](https://marketplace.visualstudio.com/items?itemName=ms-vscode-remote.remote-containers) extension).
1. **Wait for Setup**: The devcontainer will automatically start all services (PHP-FPM/Nginx, MySQL, Opensearch, Redis) and run the Magento 2 install. No manual steps are required.
1. **Access Magento**: Once setup is complete, the storefront is served automatically on port 8080 — no need to start anything manually:
   - Storefront: [http://localhost:8080](http://localhost:8080)
   - Admin: [http://localhost:8080/admin](http://localhost:8080/admin)
   - Default admin user: `admin` / `admin123`
1. **Live Extension Development**: Your extension source is mounted into the running Magento instance. Changes are reflected immediately after running `bin/magento setup:upgrade` and clearing cache.

### Services

- PHP-FPM (8.4)
- Nginx
- MySQL 8
- Opensearch
- Redis

### Notes

- The first startup can take **10–30 minutes** (Magento install, Composer, sample data deploy, DI compilation).
- The extension is symlinked into `app/code/Loqate/ApiIntegration`.
- To re-run setup, use [`.devcontainer/setup-magento.sh`](.devcontainer/setup-magento.sh) inside the container.
- If you have any DNS issues, you will need to copy your Zscaler certificate into the PHP container - see the Zscaler workaround comment in the [`Dockerfile`](.devcontainer/Dockerfile).

## Useful helpers

- `php -r '$e=include "app/etc/env.php"; $d=$e["db"]["connection"]["default"]; printf("mysql -h%s -u%s -p%s %s\n",$d["host"],$d["username"],$d["password"],$d["dbname"]);'` Will extract the command to access mysql within the devcontainer, currently that command is `mysql -hdb -umagento -pmagento magento`
- `bin/magento config:show` will list all of the config currently set in the instance, this can be set with `bin/magento config:set <PATH> <VALUE>`