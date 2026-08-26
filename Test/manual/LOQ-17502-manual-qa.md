# LOQ-17502 — verification steps

The fix is a change to `view/base/web/capture.js` only. There is no JS test runner in
this repository (`Test/Unit` is PHPUnit, and it covers PHP), so nothing here is
executed by `composer test`. Verification is the harness in step 1 plus the manual
passes in steps 3-6.

## What the fix changes

`dispatchPopulateEvents()` in the module's own wrapper now dispatches, from the
control's `populate` event, the one event the bundled SDK never sends: a bubbling
`input` on an input or textarea, `change` on anything else. Nothing the SDK already
dispatches is duplicated. Fields the SDK is not allowed to write to are untouched,
because the hook filters on the same `mode & pca.fieldMode.POPULATE` test the SDK's
own populate loop uses.

Two fields are deliberately **not** covered by that hook:

- **country** — `pca.fieldMode.COUNTRY` does not carry the `POPULATE` bit, so the
  country is filled in by the SDK's country list, which fires a native `change` of
  its own (`capture.js`, `countrylist.populate()`).
- **the region `<select>`** — still handled by `mapRegionSelectValueWithRetry()`,
  which resolves the option to select and dispatches its own `change`.

## 1. Standalone harness — no Magento needed

Open `Test/manual/LOQ-17502-alpine-repro.html` in a browser and press
**"Run both scenarios"**.

Expected: `HARNESS PASS` — scenario A (what the SDK does on its own) wipes
`street0`, `city` and `postcode`; scenario B (with the `input` event the fix adds)
keeps them. The region `<select>` survives in both, which is why this ticket is
about the text fields only.

If the page reports `HARNESS INCONCLUSIVE`, Alpine did not load — the page pulls it
from a CDN, and a corporate TLS proxy will block that. Save Alpine 3 next to the
file and point the `<script src>` at it.

## 2. Optional — drive the real widget headlessly

Stronger than step 1, because it exercises the actual `capture.js` rather than a
model of its event behaviour. Serve a page over `http://` that loads
`view/base/web/capture.js`, Alpine, an address form whose fields are bound with
`x-model` and named as the module's `default` mapping expects (`street[0]`, `city`,
`postcode`, `region_id`, `country_id`), and a `#loqate-urls` div pointing at stub
`find` / `retrieve` endpoints. The controllers return the Loqate response
unwrapped — a bare JSON array, not `{Items: [...]}` — because the control is
constructed with `endpoint.unwrapped = true`.

Then, in a headless browser: type an address into `street[0]`, click the suggestion,
read the Alpine component state, and finally overwrite every field from that state
(what a Magewire commit + morph does). Before the fix the state is missing `city` and
`postcode`; after it, both are present. Note that `street[0]` survives either way —
the shopper typed it, so real `input` events already fired. That asymmetry is the
signature of this bug.

Worth asserting at the same time: each populated text field receives exactly one
`input` and one `change`, and `pca.Address` is not constructed repeatedly (a
`MutationObserver` re-init loop would show up as a climbing count).

## 3. Deploy to the devcontainer

The devcontainer installs Luma; it does **not** install Hyvä, which needs private
Hyvä GitLab credentials. So steps 3-5 are what you can verify locally, and step 6
needs a Hyvä environment.

```
.devcontainer/setup-magento.sh          # first time only
.devcontainer/sync-extension.sh         # after every change to the module
bin/magento setup:upgrade && bin/magento cache:flush
```

`sync-extension.sh` copies the module into `vendor/` — it is not a live symlink, so
a change is not picked up until you re-run it. Also flush the browser cache or use a
private window; `capture.js` is a static view file and is aggressively cached.

## 4. Luma checkout — the regression that matters most

`view/frontend/layout/checkout_index_index.xml`. Knockout binds on `change`, which
the SDK already sent and the fix does not touch, so this must behave exactly as
before.

1. Add a product to the cart and go to checkout.
2. Type a partial address into the street field and pick a suggestion.
3. Confirm street, city, postcode, region and country all populate.
4. Click into other fields and back. Nothing may revert.
5. Confirm no validation error appears against a field the lookup left empty
   (`street[1]`, `company`).
6. Place the order and confirm the saved shipping address matches what was shown.

## 5. Admin and the other frontend forms

Same lookup-and-tab-away flow, checking nothing reverts and no premature validation
error appears:

- Admin order create — `view/adminhtml/layout/sales_order_create_customer_block.xml`
  (uses the `billingFields` / `shippingFields` mappings, not `default`).
- Admin customer edit — `view/adminhtml/layout/customer_index_edit.xml`.
- Customer address book — `view/frontend/layout/customer_address_form.xml`.
- Multishipping — `view/frontend/layout/multishipping_checkout.xml`.

The admin uses Magento UI components rather than Knockout-bound plain inputs, so
pay attention to whether a populated value is still there after the field loses
focus, and whether the form saves the populated values.

## 6. Hyvä Checkout — the reported defect (needs a Hyvä environment)

`view/frontend/layout/hyva_checkout_index_index.xml`. Use the customer's build, a
staging site with Hyvä Checkout, or a local install with a Hyvä licence.

1. Reproduce first **without** the fix, so you know the environment shows the bug:
   look up `Fatimastraat 19`, pick the suggestion, then move focus to another field.
   Postcode and city should blank out.
2. Apply the fix and repeat. The values must survive.
3. Continue to the next checkout step and place the order. Confirm the address that
   is saved is the one the lookup filled in — the DOM looking right is not enough,
   the value has to have reached the component state.
4. In DevTools, confirm:
   - no repeated `find` / `retrieve` requests firing in a loop;
   - the Magewire commit request carries the populated `city` / `postcode`;
   - no console errors from `capture.js`.
5. Check the country field specifically. If country still reverts, that is **not**
   this bug — country is populated by a different code path that already dispatches
   its own `change`. Likely causes are the Hyvä country control not being a plain
   `<select>`, or the region reload re-rendering and wiping its siblings. Diagnose
   in DevTools and raise a separate ticket rather than extending this fix by
   analogy.
