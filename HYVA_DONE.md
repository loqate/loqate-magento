# Hyvä Checkout Compatibility — Changes Made

The following changes were made to `gbg-loqate/loqate-integration` (v2.0.3) to resolve the checkout lag and add native Hyvä Checkout compatibility.

---

## 1. Removed RequireJS dependency from the capture widget

**File:** `view/base/web/capture.js`

**Problem:** The entire JS widget was wrapped in `requirejs(["jquery", "mage/url", "domReady"], ...)`. RequireJS and jQuery are not loaded on Hyvä Checkout pages (Hyvä uses Alpine.js instead). This caused the widget to silently never initialise on Hyvä storefronts.

**Fix:** Replaced the RequireJS wrapper with a plain IIFE `(function () { ... })()`. Replaced `$(document).ready(...)` with a standard `DOMContentLoaded` guard. The widget now works on any page regardless of whether RequireJS or jQuery is present.

---

## 2. Replaced the polling `setInterval` with a `MutationObserver`

**File:** `view/base/web/capture.js`

**Problem:** The widget used `setInterval(..., 200)` — an interval that ran forever, querying the DOM for address fields every 200 ms. On Hyvä Checkout, Alpine.js dynamically renders and re-renders checkout sections throughout the checkout flow. The interval was firing continuously, running repeated `querySelectorAll` calls and triggering unnecessary layout/reflow work. This was the **primary cause of the reported checkout lag**.

**Fix:** Replaced `setInterval` with:
- An immediate `scanForFields()` call on page load (handles the standard Luma case).
- A `MutationObserver` on `document.body` that watches for DOM changes (`childList: true, subtree: true`) and calls `scanForFields()` only when the DOM actually mutates. The observer is debounced by 50 ms to batch rapid Alpine.js re-renders and avoid redundant work.
- The existing duplicate-instance guard (`pcaInstances` map) ensures controls are never initialised twice for the same field.

---

## 3. Fixed missing `return` statements in around plugins

**Files:**
- `Plugin/Frontend/CheckoutShippingInformation.php`
- `Plugin/Frontend/CheckoutBillingAddress.php`

**Problem:** Both `aroundSaveAddressInformation` and `aroundAssign` contained:
```php
if (empty($this->helper->getConfigValue('loqate_settings/settings/api_key'))) {
    $proceed(...); // missing return
}
// execution continued here even with no API key
```
When no API key was configured, `$proceed()` was called but the result was discarded, and execution fell through to attempt further validation — causing silent failures or unexpected behaviour.

**Fix:** Added `return` before both `$proceed()` calls so the method exits correctly when no API key is present.

---

## 4. Added Hyvä Checkout layout handle

**File:** `view/frontend/layout/hyva_checkout_index_index.xml` *(new)*

**Problem:** The plugin only injected the Loqate config block (`config.phtml`, which outputs the JS/CSS URLs and endpoint `data-*` attributes) via the `checkout_index_index` layout handle. Hyvä Checkout uses its own layout handle (`hyva_checkout_index_index`) and does not process the standard Luma handle, so the config block was never rendered on Hyvä pages.

**Fix:** Created `hyva_checkout_index_index.xml` that injects the same `Loqate_ApiIntegration::config.phtml` block into `head.additional` on Hyvä Checkout pages.

---

## 5. Added GraphQL-compatible validation via event observer

**Files:**
- `Observer/QuoteSubmitBefore.php` *(new)*
- `etc/events.xml` *(new)*

**Problem:** The existing address/phone validation plugins intercepted:
- `Magento\Checkout\Model\ShippingInformationManagement::saveAddressInformation`
- `Magento\Quote\Model\BillingAddressManagement::assign`
- `Magento\Checkout\Model\PaymentInformationManagement::savePaymentInformationAndPlaceOrder`

Hyvä Checkout submits the order via **GraphQL mutations**, not these REST service classes. All three plugins were bypassed entirely on Hyvä, meaning address and phone validation was silently skipped.

**Fix:** Created a `sales_model_service_quote_submit_before` event observer (`Observer/QuoteSubmitBefore.php`). This Magento core event fires on **every** checkout path — Luma REST, Hyvä GraphQL, multishipping, and admin orders — immediately before the order is persisted. The observer validates both the shipping and billing addresses (and phone numbers) and throws a `LocalizedException` if validation fails, which Magento translates into an appropriate error response for whichever front-end initiated the request.

---

## Summary of files changed / created

| File | Status | Change |
|---|---|---|
| `view/base/web/capture.js` | Modified | Removed RequireJS/jQuery, replaced setInterval with MutationObserver |
| `view/frontend/layout/hyva_checkout_index_index.xml` | New | Hyvä layout handle |
| `Plugin/Frontend/CheckoutShippingInformation.php` | Modified | Fixed missing `return` |
| `Plugin/Frontend/CheckoutBillingAddress.php` | Modified | Fixed missing `return` |
| `Observer/QuoteSubmitBefore.php` | New | GraphQL-compatible validation observer |
| `etc/events.xml` | New | Registers the observer |
