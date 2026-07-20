# Loqate Magento 2 API Integration

## What is the Loqate API Integration?

Performs address capture and data validation (email, phone number and address) using Loqate API.

## Download

> **Developing in this repo's devcontainer?** You don't need any of the download steps below — the devcontainer already installs and symlinks your local working copy. See [Using the local dev copy](#using-the-local-dev-copy).

### Download via composer

Request composer to fetch the published release from Packagist:

```
composer require lqt/loqate-integration
```

### Manual Download

Download & copy the git content to `app/code/Loqate/ApiIntegration`.

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
1. **Live Extension Development**: The devcontainer runs **your local working copy** (`/workspace/loqate-magento`), mirrored into the Magento instance. After editing the source, run [`sync-extension.sh`](.devcontainer/sync-extension.sh) to apply the changes (see [Using the local dev copy](#using-the-local-dev-copy) below).

### Using the local dev copy

The devcontainer runs **your local working copy** (`/workspace/loqate-magento`) rather than the published Marketplace/Packagist release, so your edits are what Magento executes.

#### Why it's a copy, not a symlink

Magento **cannot render templates (or read other view files) from a module whose real path is outside the Magento base dir**. A Composer path repository on the same filesystem is installed as a **symlink**, and the symlinked module registers its *real* path (`/workspace/loqate-magento`, via `registration.php`'s `__DIR__`) — which is outside `/workspace/magento2`. The result is an error at checkout / address forms:

```
Path "/workspace/loqate-magento/view/frontend/templates/config.phtml"
cannot be used with directory "/workspace/magento2/"
```

A bind mount would keep the files under the base dir *and* stay live-editable, but this container isn't privileged enough to bind-mount. So the extension is installed as a **real copy** under `vendor/gbg-loqate/loqate-integration`, and edits are re-applied with a sync script. [`setup-magento.sh`](.devcontainer/setup-magento.sh) wires this up:

1. A Composer **path repository** named `loqate-local` is registered against the extension directory.
2. The extension is required by its **path-repo package name**, `gbg-loqate/loqate-integration` (the `name` in this repo's [`composer.json`](composer.json)), which registers the package + pulls dependencies:

   ```bash
   composer require gbg-loqate/loqate-integration:@dev
   ```

3. [`sync-extension.sh`](.devcontainer/sync-extension.sh) then replaces the symlink Composer created with a real copy of the source.

> **Important:** do *not* require `lqt/loqate-integration` — that is the **published Packagist release** and would install a fixed version into `vendor/lqt/loqate-integration`, ignoring your local changes. Only one of the two may be installed at a time: both register the same module name (`Loqate_ApiIntegration`), so having both present causes a "module already registered" error.

#### After editing the code — sync your changes

Run the sync script from anywhere in the container:

```bash
.devcontainer/sync-extension.sh          # copy source + cache:flush  (PHP body / template / JS edits)
.devcontainer/sync-extension.sh --full   # also setup:upgrade + di:compile  (di.xml / config / plugins / schema)
```

> Because it's a copy, **your edits are not live** — nothing changes in Magento until you run the sync script. (`composer install`/`update`/`reinstall` will re-create the symlink and reintroduce the template error; if that happens, just run the sync script again.)

**Verify you're running the local copy:**

```bash
cd /workspace/magento2
grep '"version"' vendor/gbg-loqate/loqate-integration/composer.json   # your working-copy version
ls -ld vendor/gbg-loqate/loqate-integration                          # a real directory, NOT a symlink
bin/magento module:status Loqate_ApiIntegration                      # -> "Module is enabled"
```

**Switching an already-built instance from the published copy to the local copy** (e.g. if `vendor/lqt/loqate-integration` was installed first):

```bash
cd /workspace/magento2
composer config repositories.loqate-local path /workspace/loqate-magento
composer remove lqt/loqate-integration
composer require gbg-loqate/loqate-integration:@dev
.devcontainer/sync-extension.sh --full
```

### Services

- PHP-FPM (8.4)
- Nginx
- MySQL 8
- Opensearch
- Redis

### Notes

- The first startup may take several minutes (Magento install, Composer, DB setup).
- The extension is installed from the local `loqate-local` Composer path repository as a **real copy** in `vendor/gbg-loqate/loqate-integration`; re-apply source edits with [`sync-extension.sh`](.devcontainer/sync-extension.sh) (see [Using the local dev copy](#using-the-local-dev-copy)).
- To re-run setup, use [`.devcontainer/setup-magento.sh`](.devcontainer/setup-magento.sh) inside the container.
- If you have any DNS issues, you will need to copy your Zscaler certificate into the PHP container - see the Zscaler workaround comment in the [`Dockerfile`](.devcontainer/Dockerfile).

## Useful helpers

- `php -r '$e=include "app/etc/env.php"; $d=$e["db"]["connection"]["default"]; printf("mysql -h%s -u%s -p%s %s\n",$d["host"],$d["username"],$d["password"],$d["dbname"]);'` Will extract the command to access mysql within the devcontainer, currently that command is `mysql -hdb -umagento -pmagento magento`
- `bin/magento config:show` will list all of the config currently set in the instance, this can be set with `bin/magento config:set <PATH> <VALUE>`


## Testing

### Automated unit tests

Unit tests live under [`Test/Unit`](Test/Unit) and run with Magento's unit test suite. From the Magento root of an instance that has the module installed:

```bash
# Module installed via composer:
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
  vendor/gbg-loqate/loqate-integration/Test/Unit

# Module installed under app/code:
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist \
  app/code/Loqate/ApiIntegration/Test/Unit
```

`Test/Unit/Helper/ValidatorTest.php` covers the captured-address (Loqate lookup) bypass: array-street parsing, that a looked-up address is recognised across countries (e.g. UK province-name vs region, US `CA` vs `California`), case/whitespace tolerance, and the guards that a different or empty address is **not** bypassed.

### Manual testing (checkout flow)

**Prerequisites** (Admin → Stores → Configuration → Loqate, or `bin/magento config:set`):

- `loqate_settings/settings/api_key` — a valid Loqate API key
- `loqate_settings/address_settings/enable_checkout` = `1` (verification enabled at checkout)
- Capture/lookup enabled on checkout
- Optional: set strict thresholds under `loqate_settings/verify_threshold_settings` so a hand-typed address would be rejected, making the bypass behaviour obvious

**Verify a looked-up address is accepted (the main regression):**

1. Go to checkout (guest or logged-in).
2. Use the **Loqate lookup** and **select a suggested address from the dropdown** — do not hand-type it.
3. Proceed through shipping / place order. The address should be accepted and checkout should proceed (the selected address bypasses re-verification).

**Different-country checks** (the bypass is locale-agnostic):

- **US** — lookup returns the state as a full name (e.g. *California*) while Magento stores the region as `CA`; the address should still be accepted.
- **UK** — lookup an address with no region; should be accepted.

**Guard checks (verification is still active):**

- Hand-type an invalid address **without** using the lookup → verification still fires and rejects it.
- Select a lookup address, then **edit** a field (e.g. the street) → it is re-verified, since it no longer matches the captured address.

**Observe via logs** — a bypassed (captured) address makes no verify API call, whereas a hand-typed one does:

```bash
tail -f var/log/loqate*.log
```


## Deployment

Releasing a new version requires two separate deployments: one to the **Adobe Marketplace** and one via **Composer**. Before proceeding with either, update the version number in both [`composer.json`](composer.json) and [`etc/module.xml`](etc/module.xml) to reflect the new release, then commit and push the change.

Note that the Git tag for the Composer release is created automatically when changes are merged to `master` — see the [Composer](#composer) section below.

### Adobe Marketplace

1. Create a zip of the whole repository. Ensure that `.devcontainer`, `.git` and `.gitignore` are excluded, as the Magento malware scan does not allow them to be uploaded. The following command will produce a clean archive:
   ```bash
   zip -r loqate-integration.zip . -x "*.git*" -x "*.devcontainer*"
   ```
2. Log in to your Adobe account at [account.magento.com](https://account.magento.com/customer/account/login).
3. Navigate to the [extension versions page](https://commercedeveloper.adobe.com/extensions/versions/gbg-loqate-loqate-integration) on the Adobe Commerce Developer Portal.
4. Upload the zip archive.
5. Adobe will automatically process and scan the submission. This can take up to **15 business days** if a manual approval is required.
   - If the scan **fails**, review the provided feedback, address the reported issues, and resubmit.
   - If the scan **passes**, the extension will be published to the marketplace within the hour.

### Composer

The Git tag for a Composer release is created automatically. When changes are merged to `master`, the [`auto-tag.yml`](.github/workflows/auto-tag.yml) GitHub Action analyses the commits since the previous tag and creates a new version tag based on [Conventional Commits](https://www.conventionalcommits.org/):

- `feat:` commits → **MINOR** bump (e.g. `v2.0.4` → `v2.1.0`)
- `fix:` commits → **PATCH** bump (e.g. `v2.0.4` → `v2.0.5`)
- `feat!:` or `BREAKING CHANGE:` → **MAJOR** bump (e.g. `v2.0.4` → `v3.0.0`)
- Other types (`docs:`, `style:`, `refactor:`, etc.) → no bump (no tag created)

If none of the commits since the previous tag are `feat:`, `fix:`, or a breaking change, **no tag is created and no release is published** — so commits like `docs:`, `chore:`, `ci:`, `refactor:`, etc. can be merged to `master` without cutting a release. When a release *is* cut, the workflow also publishes a GitHub release with an auto-generated changelog. Once the new tag is pushed, Composer will automatically detect it and make the release available on [packagist](https://packagist.org/packages/lqt/loqate-integration).

To ensure a release is tagged correctly, make sure your commit messages follow the Conventional Commits format.