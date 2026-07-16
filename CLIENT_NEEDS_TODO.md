# Client Action Required

The following items must be completed by the customer (Taylored Investments / buyshedsdirect.co.uk) or their development team before the Loqate plugin will be fully operational on their Hyvä Checkout.

---

## 1. Update the Composer dependency and install the new plugin

The old package name (`loqate-integration/adobe`) has been abandoned. The current package is `gbg-loqate/loqate-integration`.

Run the following in the Magento root:

```bash
composer remove loqate-integration/adobe
composer require gbg-loqate/loqate-integration
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy
php bin/magento cache:flush
```

---

## 2. Update or remove the Hyvä bridge package

The package `hyva-themes/magento2-hyva-checkout-loqate` was built against the old package (`loqate-integration/adobe`, max v1.1.6). It is **not compatible** with the new `gbg-loqate/loqate-integration` package.

**Options:**

- **Recommended:** Remove the Hyvä bridge package. The changes made in this release add native Hyvä Checkout compatibility directly to `gbg-loqate/loqate-integration`, so the separate bridge is no longer needed.

  ```bash
  composer remove hyva-themes/magento2-hyva-checkout-loqate
  ```

- **Alternative:** Contact Hyvä Themes and request that they publish a new release of `hyva-themes/magento2-hyva-checkout-loqate` that declares a dependency on `gbg-loqate/loqate-integration` v2+.

---

## 3. Flush caches and redeploy static content

After any package or code change:

```bash
php bin/magento cache:flush
php bin/magento setup:static-content:deploy
```

---

## 4. Verify admin configuration

In **Stores > Configuration > Loqate**, confirm the following are enabled as required:

| Setting | Recommended state |
|---|---|
| Address Capture at Checkout | Enabled |
| Address Verify at Checkout | Enabled (was disabled — re-enable once package is updated) |
| Email Verify at Checkout | Enabled |
| Phone Verify at Checkout | Enabled |
| API Key | Set to the new key provided by Loqate CSM |

---

## 5. Test on staging before deploying to production

- Complete a guest checkout and a logged-in checkout on the Hyvä storefront.
- Confirm the address autocomplete widget appears and populates fields correctly.
- Confirm that an invalid address is rejected at order placement.
- Confirm there is no checkout lag (the polling interval has been replaced — see HYVA_DONE.md).
