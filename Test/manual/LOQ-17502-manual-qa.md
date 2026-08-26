# LOQ-17502 — verification steps

The fix is a change to `view/base/web/capture.js` only, but it is no longer unverified
by machine. There is now a JavaScript suite in this repository — `npm test`, over
`Test/js/`, run in CI as its own job — which loads the real `capture.js` and asserts
its event contract. `composer test` still covers PHP only (`Test/Unit` is PHPUnit),
so it says nothing about this fix.

What the automated suite proves: that the script dispatches the events it is supposed
to dispatch, and that an Alpine `x-model` component bound the way Magewire binds one
ends up holding the populated values. What only a human on a real Hyvä install can
prove: that Hyvä Checkout's own Magewire components, morphing and validation behave —
nothing automated here runs against Hyvä Checkout. So step 1 is the desk check, steps
2-4 are what a local Luma devcontainer can show, and step 5 is still required before
release.

## What the fix changes

The fix is in the bundled SDK's own event dispatcher, `pca.reactTriggerChange`
(`view/base/web/capture.js:3202-3228`). An `input[type=text]` now emits **exactly one
bubbling `input`, then exactly one bubbling `change`**, in that order — native
semantics: `input` while editing, `change` on commit. `select`, `input[type=file]`,
checkbox, radio, textarea and every other `supportedInputTypes` key keep their
upstream behaviour, untouched.

`input` is what Alpine's `x-model` needs (Alpine syncs a text input on `input` only,
and uses `change` only for select, checkbox and radio), and `x-model` is what Magewire
generates from the `wire:model.defer` bindings Hyvä Checkout uses. `change` is what
Luma's Knockout `value` binding, the customer address book, multishipping and the
admin Magento UI components listen for. Both events are required; dropping either
breaks one of the two front ends.

It sits in the dispatcher, rather than on the capture control's `populate` event, for
two reasons:

- **It covers every path the SDK writes a field from.** Not only the populate loop
  (`capture.js:7354`), but also the drill-down `filterSearch` write back to the search
  field (`capture.js:7165`), the country list's `hide` restore of the stored search
  (`capture.js:6549`) and `address.clear()` (`capture.js:7609`). A hook on the
  `populate` event would cover the first of those four and leave the other three
  writing values Hyvä never learns about.
- **It does not depend on a local fork change elsewhere in the same file.** A hook that
  only added `input` would have relied on the dispatcher's local `input[type=text]`
  clause to keep supplying the `change`. A clean re-vendor of the SDK deletes that
  clause, and Luma would have broken silently. Both events now live in one place, in
  one branch, described in the local-changes block at the top of `capture.js`.

Two fields get their event from somewhere else, and neither is affected by this
change:

- **country** — `pca.fieldMode.COUNTRY` (8) carries no `POPULATE` bit (2), so the
  populate loop skips the country field entirely (`capture.js:7323`). It is filled in
  by the SDK's country list, which fires its own native `change`
  (`countrylist.populate()`, `capture.js:5733`).
- **the region `<select>`** — `pca.setValue` returns as soon as it has set
  `selectedIndex` on a select (`capture.js:3364-3373`), so a select never reaches the
  dispatcher and the SDK sends no event at all for it. The module's own `populate`
  listener (`capture.js:8133-8151`) resolves the option — matching the region codes
  Magento keeps in data attributes, which `pca.setValue` misses — and dispatches the
  `change` a select needs. `mapRegionSelectValueWithRetry()` is the only thing
  providing that event, and `change` is exactly what Alpine, Knockout and the admin UI
  components listen for on a select. The region elements are now resolved by name on
  every populate rather than snapshotted at init, so a `region_id` select that renders
  later (Magewire re-rendering the address form, or Magento loading the region list
  once a country is chosen) is still mapped. `region` (text input) and `region_id`
  (select) both map to `ProvinceName`; only the select goes through the mapper, the
  text input is populated by the ordinary loop like any other text field.

**Because the fix is in the shared dispatcher, the Luma and admin regression passes
matter more than they would for a hook on `populate`:** every text input the SDK
writes, anywhere, now receives an extra `input` event it did not receive before. That
is the correct native order and no consumer of `change` loses anything, but a listener
that reacts to both will now run twice. Steps 3 and 4 exist to look for that.

## 1. Desk checks — no Magento install needed

### `npm test` — the automated suite

```
npm ci        # first time, and after the lockfile changes
npm test
```

Node's built-in test runner (`node --test`; there is no vitest or jest here), over
`Test/js/`, using jsdom and the real Alpine package from npm.

`jsdom@30` sets the floor, so the suite needs **Node `^22.22.2 || ^24.15.0 ||
>=26.0.0`** — which is what `package.json` declares. Node 20 cannot run it at all;
neither can a 22.x older than 22.22.2 or a 24.x older than 24.15.0. On any of those
`npm ci` warns `EBADENGINE` and the run cannot be trusted. CI covers `22` and `24`,
each resolved to its newest patch, so CI is always above the floor.

The tests load the actual `view/base/web/capture.js` rather than a re-implementation of
its behaviour, and assert:

- one `input` followed by one `change`, in that order, for each populated text field;
- that an Alpine `x-model` component ends up holding `city` and `postcode`, and that
  both survive a simulated Magewire commit and morph;
- that the drill-down path and the country-list-cancel path emit `input` as well;
- that a synthetic `input` does not trigger a `find` or `retrieve` request, so the
  extra event cannot start a lookup loop;
- that `pca.Address` is constructed only once, not repeatedly by the `MutationObserver`
  rescan.

A failure here means the event contract has moved. Read the failing assertion before
changing anything: losing `change` breaks Luma, the address book, multishipping and
the admin; losing `input` re-opens this ticket. An extra event of either kind is also
a failure — double-firing is what steps 3 and 4 hunt for by hand.

This is the strongest automated evidence available, and it is still not Hyvä Checkout.

### The Alpine harness — explains the bug, does not test the fix

Open `Test/manual/LOQ-17502-alpine-repro.html` in a browser and press **"Run both
scenarios"**.

The page never loads `capture.js`; it models the dispatch in a dozen lines of its own.
So it demonstrates *Alpine's binding behaviour* — that a text input bound with
`x-model` is only synced on `input`, and that a value which never reached component
state is overwritten by the next commit and morph — and nothing about the shipped
code. Use it to understand *why* the defect happened, or to show someone; do not treat
it as verification.

Expected: `BEHAVIOUR DEMONSTRATED` — scenario A (`change` only) loses `street0`, `city`
and `postcode` from the component state; scenario B (`input`, then `change`) keeps
them. The region `<select>` survives in both, which is why this ticket is about the
text fields only.

If the page reports `BEHAVIOUR NOT DEMONSTRATED`, Alpine most likely did not load — the
page pulls it from a CDN, and a corporate TLS proxy will block that. Save Alpine 3 next
to the file and point the `<script src>` at it.

## 2. Deploy to the devcontainer

The devcontainer installs Luma; it does **not** install Hyvä, which needs private
Hyvä GitLab credentials. So steps 2-4 are what you can verify locally, and step 5
needs a Hyvä environment.

```
.devcontainer/setup-magento.sh          # first time only
.devcontainer/sync-extension.sh         # after every change to the module
bin/magento setup:upgrade && bin/magento cache:flush
```

`sync-extension.sh` copies the module into `vendor/` — it is not a live symlink, so
a change is not picked up until you re-run it. Also flush the browser cache or use a
private window; `capture.js` is a static view file and is aggressively cached.

## 3. Luma checkout — the regression that matters most

`view/frontend/layout/checkout_index_index.xml`. Knockout binds on `change`, which the
dispatcher still sends for every node type it sent it for before, so this must behave
exactly as it did. What is new is the extra `input` on text inputs, so watch for
anything that reacts twice.

1. Add a product to the cart and go to checkout.
2. Type a partial address into the street field and pick a suggestion.
3. Confirm street, city, postcode, region and country all populate.
4. Click into other fields and back. Nothing may revert.
5. Confirm no validation error appears against a field the lookup left empty
   (`street[1]`, `company`) — a premature or duplicated validation run is the
   signature of the extra `input` being handled where it should not be.
6. Watch the browser console and the network tab while a suggestion is applied: no
   repeated `find` / `retrieve` calls, and no jQuery validation running twice over the
   same field (a `valid()` call per event rather than per commit).
7. Place the order and confirm the saved shipping address matches what was shown.

## 4. Admin and the other frontend forms

Same lookup-and-tab-away flow, checking nothing reverts, no premature validation error
appears, and nothing fires twice:

- Admin order create — `view/adminhtml/layout/sales_order_create_customer_block.xml`
  (uses the `billingFields` / `shippingFields` mappings, not `default`).
- Admin customer edit — `view/adminhtml/layout/customer_index_edit.xml`.
- Customer address book — `view/frontend/layout/customer_address_form.xml`.
- Multishipping — `view/frontend/layout/multishipping_checkout.xml`.

The admin uses Magento UI components rather than Knockout-bound plain inputs, so pay
attention to whether a populated value is still there after the field loses focus,
whether the form saves the populated values, and whether the extra `input` causes
visible component churn — a field re-rendering or re-validating twice, a spinner
flashing, or an admin order-create block reloading more than once per suggestion.

## 5. Hyvä Checkout — the reported defect (needs a Hyvä environment)

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
   - no burst of Magewire commits — `wire:model.defer` must not commit on the new
     `input` events, so a suggestion should not produce one commit per field;
   - no console errors from `capture.js`.
5. Check the country field specifically. If country still reverts, that is **not**
   this bug — country is populated by a different code path that already dispatches
   its own `change`. Likely causes are the Hyvä country control not being a plain
   `<select>`, or the region reload re-rendering and wiping its siblings. Diagnose
   in DevTools and raise a separate ticket rather than extending this fix by
   analogy.
