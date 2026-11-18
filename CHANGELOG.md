# Changelog

All notable changes to this module are documented here.

This file starts at the LOQ-16969 series; releases up to and including v2.0.17
predate it and are described by their git tags and commit history.

## [Unreleased]

### Security

- **The admin address-lookup routes now require an admin login** (LOQ-20336).
  `loqate_admin/capture/find` and `retrieve` ran a Loqate lookup on the merchant's API key
  for any request, logged in or not. Logged-in admins are unaffected, including roles
  without configuration rights. The storefront lookup routes (`loqate/capture/find` and
  `retrieve`) are public by design; LOQ-20339, below, limits them.

- **Storefront address lookups are now limited per client** (LOQ-20339). The public
  `loqate/capture/find` and `retrieve` routes ran a Loqate lookup on the merchant's API key
  for anyone, as often as they liked. Each client may now make 200 lookups, Find and
  Retrieve counted together, in each 10-minute window. The limit is approximate: a client
  sending many requests in parallel can get several times the limit through. This limit
  slows down individual clients; it does not cap total spend on the key. To do that, set
  usage limits on the API key in your Loqate account. A client over the limit gets HTTP 429
  instead of a lookup: the lookup widget keeps working (since LOQ-20320) but shows no
  suggestions until the window ends, and the shopper can still type the address by hand.
  Shoppers who share an IP address, such as behind mobile-carrier NAT or an office network,
  share one counter, and IPv6 clients are counted per /64 network. The first refused
  request in each window is logged to `var/log/loqate_log_file.log` with the client's IP
  address (its /64 network for IPv6). The counter lives in Magento's cache, so it is shared
  across web nodes when the cache is Redis; if the cache fails, lookups are allowed rather
  than blocked.

  Both routes now answer 404 when storefront lookup is switched off for the store in both
  checkout and customer account. The admin lookup routes are not limited.

  Magento must see the real client IP. Requests from a private or reserved address —
  Varnish, a TLS terminator or a load balancer on the same network — are not limited, and
  the first one in each window logs a warning. Requests from a CDN's public addresses are
  limited as one client. Preferred setup: have the web server set `REMOTE_ADDR` from the
  forwarded header, trusting it only from your proxy's addresses (nginx `set_real_ip_from`
  with only your proxy ranges, `real_ip_header` and `real_ip_recursive on`; or Apache
  `mod_remoteip` with `RemoteIPHeader` and `RemoteIPTrustedProxy`), and leave Magento's
  `RemoteAddress` `alternativeHeaders` empty. If you use `alternativeHeaders` instead,
  Magento reads that header on every request: make sure only the proxy can reach the
  origin, use a header the proxy overwrites, and list every proxy hop's exact address in
  `trustedProxies` (ranges are not supported). Otherwise any client can choose its own
  counter, use up someone else's, or skip the limit entirely by sending a private address.

Run `bin/magento setup:di:compile` after upgrading: the admin and storefront lookup
controllers' constructors changed, and until a compiled store recompiles, address lookup
fails in both.

## [2.2.1] - 2026-11-18

### Fixed

- **Address lookup keeps responding after a slow or failed search, and keeps refining
  as the shopper types** (LOQ-20320). After a lookup request failed with an HTTP error
  other than a network error — for example a gateway 504 because the module's Find proxy
  answered slowly — or simply never came back, address lookups stopped responding: the
  browser script never released the request, so every later keystroke was queued and
  never sent, and any loading indicator on the page never cleared. Failed requests are
  now reported as errors, lookup and address-retrieve requests time out after 10
  seconds, and in both cases the most recent search typed in the meantime is sent.

  The server side is bounded in this same release (LOQ-20334, below). The module now
  requires `lqt/api-connector` 1.1.4, where lookup and retrieve give up after 8 seconds
  and every call has a 2-second connect limit. So while the Loqate API is slow, an
  abandoned lookup holds its PHP-FPM worker in cURL for at most 8 seconds. That is cURL
  time only: waiting for a free PHP-FPM worker, Magento's bootstrap and any wait on the
  session lock come on top, so the browser's 10 seconds and the server's 8 do not line
  up exactly, and a lookup the browser has given up on can still be running on the
  server. Setting `request_terminate_timeout` on the PHP-FPM pool remains an optional
  backstop.

- **Calls to the Loqate API now time out instead of waiting as long as cURL does**
  (LOQ-20334). The `lqt/api-connector` SDK set no cURL timeout, so a slow or unreachable
  Loqate API held each PHP-FPM worker that called it until cURL returned or the pool's
  `request_terminate_timeout` killed it; the web server's gateway timeout does not free
  it, and `max_execution_time` does not count I/O wait on Linux. `lqt/api-connector`
  **1.1.4** gives every call a 2-second connect limit and a total limit of 8 seconds for
  address lookup and retrieve and 30 seconds for every other call the module makes.
  Email validation gets
  the 30-second limit, above the Timeout the module sends Loqate (15000 ms, the module's
  default and admin-form maximum), so Loqate's own timeout still answers first. Admin
  batch address verification — customer import (`Plugin/Admin/ValidateImportAddress.php`,
  100 rows per call) and admin order save (`Plugin/Admin/OrderSave.php`), both through
  the batch verify in `Helper/Validator.php` — now has each batch call capped at 30
  seconds (per call, not per import or order) where a slow batch used to wait without
  limit, and hitting the cap fails closed: the import stops
  verifying and fails validation with one critical error ("Addresses could not be
  validated: the Loqate service did not respond. Please try the import again."), and the
  admin order is not saved and shows "An unexpected error occurred while trying to
  validate your address."

  A failed connection was already returned as the SDK's error shape, `['error' => true,
  'message' => …]`, but with generic text: in 1.1.3 `HttpClient` returned null, and every
  client method the module calls (`Capture::find()` and `retrieve()`, the `Verify`
  checks, `Extras::ipToCountry()`) fell through to "Unexpected error occurred." With
  1.1.4 the message the module logs names the cURL error, for example `cURL error 28:
  Timeout was reached`. What shoppers see when a call fails is unchanged — lookups answer
  with the module's error JSON, and the email, phone and address checks follow their
  existing API-error handling. What is new is that slow calls are cut off.

  **To pick it up, upgrade this module to the release carrying this entry.** It requires
  `"lqt/api-connector": "^1.1.4"`, which an installed 1.1.3 does not satisfy, so name both
  packages — `composer update gbg-loqate/loqate-integration lqt/api-connector` — and
  Composer moves the SDK with the module. A merchant who stays on an older module version
  can still take the fix with `composer update lqt/api-connector`: those releases require
  `"^1.1.3"` or `"^1.1"`, which the installed SDK already satisfies, so Composer will not
  move it unless it is named. Either way, check the result with `composer show
  lqt/api-connector`, which must report 1.1.4 or later: that check is what catches a
  partial update that silently left 1.1.3 in place. Do not run a bare `composer
  update` in a Magento root — that updates the whole install's dependency tree.

## [2.2.0] - 2026-11-16

### Changed — behaviour

- **Place order no longer verifies the guest email once the address or phone check
  has rejected the order** (LOQ-20300). The order is refused anyway, so the email
  verification would be a billable call that cannot change the outcome; skipping it
  saves that call and closes the probe of forcing an address failure to check
  emails. The shopper sees the address or phone error first; the email is checked on
  the next attempt, once those pass. Anonymous checkout can still be used to test
  emails one paid call at a time (place order with a valid address, and the Luma
  shipping step), as with addresses and phone numbers; to limit this, enable Magento
  reCAPTCHA for checkout/place order or rate-limit those requests (tracked in
  LOQ-20315).

- **Email API errors are now logged at warning level and raise an admin notice at
  most once an hour** (LOQ-20300). An error (for example the API key, its credit or
  limits, or an outage) used to be logged at info level only. While it persists,
  guest orders placed at checkout go through without an email check, and account
  registration and edit, the Luma shipping step and admin customer and order forms
  show an error. The admin notice does not contain the shopper's email; it points to
  `var/log/loqate_log_file.log` for the cause. Raising it is best-effort: if it
  fails, a second warning is logged and the request carries on. The notice needs
  the Magento_AdminNotification module; without it, only the warning log line
  remains.

- **With "Prevent Submit" = Yes, guest orders placed through the REST API by
  integrations, and through GraphQL, are now email-checked** (LOQ-20300). This
  covers OMS/ERP connectors and marketplace importers that place guest orders through
  the guest-cart or cart endpoints with an integration token, as well as GraphQL
  storefronts. An invalid email now rejects these orders; marketplace relay or
  placeholder emails are the likely casualties. These requests keep no session, so
  every place-order attempt is one billable email verification. With "Prevent
  Submit" = No they are still not checked (tracked in LOQ-20301). See "Fixed" below.

- **A guest email that passed verification is not verified again in the same
  session, and Luma's REST place order now checks the email** (LOQ-20299). Once an
  email passes, later checkout checks of it in that session reuse the pass and make no
  API call, with either "Prevent Submit" setting, so Luma's shipping step followed by
  a review-page place order (PayPal Express etc.) is billed once. Only passes are
  remembered: an invalid email, an email service error or a missing API key is
  checked again next time, and a changed email is always checked. A pass is tied to
  the "Accept Valid_CatchAll response code" setting, so changing that setting
  re-verifies the email. Passes are kept as salted digests, never addresses, for at
  most 25 emails per session, separately per store view, and are forgotten on login
  or logout. The place-order check now also runs on REST requests that carry the
  storefront session cookie, which Luma's browser checkout always sends, so a Luma
  guest checkout of a virtual or downloadable-only cart now has its email checked:
  with Yes an invalid email blocks the order, with No the guest is warned once and
  the next attempt goes through. For REST without the session cookie and for
  GraphQL, see LOQ-20300. Admin customer save, admin order create and the
  storefront account forms do not use the remembered passes and still verify on
  every save; logged-in customers are still not checked at checkout.

- **Luma guest orders placed from a review page now have their email checked**
  (LOQ-19188). The new check runs on every place order in the storefront (frontend)
  area, so it also covers the review pages that place the order directly and can
  skip the shipping step, such as PayPal Express, Braintree PayPal and wallet
  checkouts. With "Prevent Submit" = Yes an invalid email now blocks the order there;
  with No the shopper is warned once and the next attempt goes through. An email
  that already passed at Luma's shipping step is not verified again (LOQ-20299).

### Fixed

- **Email validation now runs on Hyvä Checkout** (LOQ-19188). It used to run only
  when Luma's checkout saved the shipping step, which Hyvä Checkout never calls, so
  guest emails were not checked at all there. The guest's email is now checked when
  a Hyvä Checkout order is placed, with the same "Prevent Submit" behaviour as Luma:
  with Yes an invalid email blocks the order, and with No the first attempt warns and
  placing the order again with the same email goes through. If the email service
  returns an error at that point, the order goes through and the error is logged,
  as the phone check there already does. Only guest emails are checked, as on Luma.
  Luma's REST place order is checked as well since LOQ-20299, when it carries the
  storefront session cookie, and GraphQL and cookie-less REST place orders with
  "Prevent Submit" = Yes since LOQ-20300. GraphQL and cookie-less REST with
  "Prevent Submit" = No are **not** covered: they often keep no session, so the
  shopper would be warned on every attempt and could never place the order
  (tracked in LOQ-20301). CLI and cron order placement are not covered either. See
  also "Changed — behaviour" above.

- **GraphQL and headless REST guest place orders now have their email checked when
  "Prevent Submit" = Yes** (LOQ-20300). This covers GraphQL and REST place orders
  without the storefront session cookie. An invalid email blocks the order; if the
  email service returns an error, the order goes through and the error is logged. An
  email that already passed earlier in the same session is not verified again
  (LOQ-20299); without a session it is verified on every place order. With "Prevent
  Submit" = No, these place orders are still not email-checked: warning once and
  letting the next attempt through needs a session, which headless clients often do
  not keep (tracked in LOQ-20301). CLI and cron order placement are still not
  checked.

### Changed — for developers extending this module

- **`Helper\Validator`'s constructor takes two more required arguments**, a
  `Magento\Framework\Notification\NotifierInterface` and a
  `Magento\Framework\App\CacheInterface`, last (LOQ-20300). DI supplies them; a
  subclass or code that calls `new Validator(...)` must pass them.

## [2.1.4] - 2026-10-13

### Fixed

- **Address lookup now binds to Hyvä street inputs** (LOQ-18103). The single
  `street` input (1 street line) and the `street[]` inputs on the Hyvä address book
  Edit/New page and the Hyvä checkout "Add new address" modal now get Capture; before,
  it silently did not. On any theme, including Luma and admin set to 1 street line, a
  form with fewer street inputs than address lines folds every line into the ones it has.

## [2.1.3] - 2026-09-10

### Fixed

- **Every Loqate API call the module makes failed on PHP 8.5, and works again**
  (LOQ-17709). Reported by a merchant on Magento 2.4.9 / PHP 8.5. Address lookup and
  retrieve, the email and phone checks, the single and batch address verifications and
  set-country-by-IP all returned an error envelope instead of a result — 100% of the
  time, in production and developer mode alike — so the lookup offered no suggestions,
  every verification came back as a fault, and the checkout and customer address forms
  stopped pre-selecting the shopper's country. The map's reverse-geocode lookup never
  broke: the browser asks Loqate for that one directly, never this module's PHP.

  It is a deprecation promoted to an exception: PHP 8.5 deprecated `curl_close()`, and
  the `lqt/api-connector` dependency called it after every request in
  `src/Client/Http/HttpClient.php` (this module makes no cURL calls of its own). On
  Magento that is not a log line. `app/bootstrap.php` calls `error_reporting(E_ALL)`
  unconditionally, and `Magento\Framework\App\ErrorHandler`, which throws on
  `E_DEPRECATED`, is installed by both entry points: by
  `Magento\Framework\App\Bootstrap::run()` on the web path, before it consults
  `MAGE_MODE` at all, and by `bin/magento` itself before it runs the CLI application —
  so the CLI paths that carry the batch verification (customer import, cron, the
  consumers) failed exactly as web requests did. The throw lands inside
  `HttpClient::get()` and `::post()` before either returns, and the SDK's own `catch
  (Throwable)` turns it into `['error' => true, 'message' => …]`, which the module acts
  on and `Helper/Controller.php:159` writes to `var/log/`. The handler adds its own
  level, file and line around PHP's wording, so **the string to grep for is**
  `Deprecated Functionality: Function curl_close() is deprecated since 8.5, as it has no
  effect since PHP 8.0 in …/Client/Http/HttpClient.php on line 28` (line 51 on the
  address verifications, which POST). PHP 8.5 is itself past Adobe's platform matrix for
  2.4.9, which stops at 8.4, but `app/bootstrap.php` enforces only `>= 8.1.0`, so
  nothing stops a merchant running it.

  The fix is upstream, in `lqt/api-connector` **1.1.3**, which is released and on
  Packagist. It drops the SDK's reference to the handle with `unset()` instead of
  calling `curl_close()`. On PHP 8 that function was already a no-op — the handle is a
  garbage-collected object, freed when the method returned — so `unset()` now frees it a
  few statements earlier, at the same point in the code. On PHP 7, where the handle is a
  refcounted resource, `unset()` frees it at exactly the statement `curl_close()` used
  to. **Nothing changes for merchants on PHP 8.3 or 8.4**: the requests sent, the
  responses parsed and the errors the module raises from them are identical there. On
  8.5 the change is the difference between every call failing and every call working.

  **To pick it up, upgrade this module to v2.1.3 or later.** That release requires
  `"lqt/api-connector": "^1.1.3"`, so the fixed SDK is installed with it and no separate
  step is needed. A merchant who stays on an older module version can still take the fix
  by naming the package — `composer update lqt/api-connector` — because those releases
  carry the looser `"^1.1"` constraint, which 1.1.2 already satisfies, so Composer will
  not move the SDK unless it is named. Either way, check the result with `composer show
  lqt/api-connector`, which must report 1.1.3 or later. Do not run a bare `composer
  update` in a Magento root — that updates the whole install's dependency tree.

  CI now also runs the module's unit suite on PHP 8.5, alongside 8.3 and 8.4, and the
  suite carries a module-side regression guard for this defect:
  `Test/Unit/Dependency/ApiConnectorCompletesRequestsTest.php` drives the real vendored
  `HttpClient` — one case for `get()`, one for `post()`, the two call sites that broke —
  over a real socket at a stub endpoint on `127.0.0.1`, under a replica of Magento's
  throwing `ErrorHandler` with `error_reporting(E_ALL)`, and asserts that the decoded
  response body comes back. It needs no API key, no network and no billable request, and
  on PHP 8.5 it fails against the unfixed 1.1.2 — which is what makes the `^1.1.3`
  constraint an enforced guarantee rather than a hopeful one.

  **That guard only has teeth on the 8.5 leg.** `curl_close()` is not deprecated below
  8.5, so the same test passes on 8.3 and 8.4 even with the unfixed SDK in `vendor/`. It
  also installs that error handler and widens `error_reporting()` itself, because
  `phpunit.xml.dist` sets `failOnWarning` and `failOnRisky` but not `failOnDeprecation`
  — PHPUnit alone would not fail the run on the deprecation. The SDK repository keeps
  its own guard as well (`composer test`), which drives `HttpClient` at a local stub
  under a throwing error handler on PHP 7.0 through 8.5.

## [2.1.2] - 2026-09-04

### Fixed

- **An address picked from the lookup no longer empties itself again on Hyvä
  Checkout** (LOQ-17502). The shopper searched, picked a suggestion, watched city,
  postcode and region fill in, and then lost them the moment focus moved to another
  field — leaving the checkout without the data it had just shown. Reported on
  Magento 2.4.8-p5 with module 2.0.16, reproducible on Hyvä's own default theme, and
  not reproducible once the checkout was switched to Luma. Every release up to and
  including v2.1.1 is affected; upgrading alone does not fix it.

  Hyvä Checkout's fields are Magewire `wire:model.defer`, which Magewire rewrites
  into an Alpine `x-model`, and Alpine syncs a text input to the component state on
  `input` — `change` only on a select, checkbox or radio. The bundled capture SDK
  dispatched `change` and nothing else for a text input, so the component never
  learned the field had been filled in, and its next render morphed the stale empty
  value back over what the shopper could see. Luma is unaffected because Knockout's
  `value` binding listens for `change`, which is exactly what was being sent. The
  street field looked like it survived, on both front ends, only because the shopper
  typed into it and real `input` events had already fired.

  The bundled SDK's own event dispatcher, `pca.reactTriggerChange`, now sends both
  events for a text input: one bubbling `input`, then one bubbling `change`, in the
  order a browser would (`input` while editing, `change` on commit). The value the
  shopper picked therefore reaches Hyvä's component state, survives the next
  re-render, and is the address the order is placed with. Fixing it in the dispatcher
  rather than on the capture control's `populate` event also closes three sibling
  write paths a `populate` hook missed — the drill-down write back to the search
  field, the country list restoring the search text when it is dismissed, and
  clearing the address — and it removes a re-vendor trap: a hook that added only
  `input` would have depended on a separate local change further down the same file to
  keep supplying `change`, so the next clean re-vendor of the SDK would have broken
  Luma silently.

  **Luma, multishipping, the customer address book and the admin order-create and
  customer-edit screens behave the same for every consumer of `change`.** The
  dispatcher still sends `change` for every node type it already sent it for, in the
  same cases, and `select`, `input[type=file]`, checkbox, radio and textarea are not
  touched at all — a text input is the only node type whose behaviour changed.

  What is new on those screens too is that **every text input the SDK writes now also
  receives one native bubbling `input`, immediately before the `change`** — the event
  a browser would have sent and the SDK never did. No Magento binding on those screens
  acts on `input`: Luma's Knockout `value` binding, the address-book and multishipping
  forms and the admin UI components all listen for `change`. So no shopper-visible
  behaviour is expected to change there, but a custom listener or third-party
  extension bound to both events on a captured field will now run twice, and any
  integration that treats an `input` on those fields as "the shopper typed" will now
  see one. The regression steps for each of those screens are in
  `Test/manual/LOQ-17502-manual-qa.md`.

  The event contract is now asserted by an automated test: exactly one `input`, then
  exactly one `change`, per populated text field. The country field and the region
  `<select>` are unaffected; both are populated by paths that fire their own `change`.

  This is a front-end-only change, and it now has automated coverage: a JavaScript
  suite run by `npm test` — Node's built-in test runner with jsdom and the real Alpine
  package, in `Test/js/`, wired into CI as its own job — which loads the real
  `capture.js` rather than a re-implementation and asserts the event contract above,
  that an Alpine `x-model` component ends up holding the populated city and postcode
  and keeps them across a simulated Magewire commit and re-render, that the drill-down
  and country-list-cancel paths dispatch `input` as well, that the extra event starts
  no lookup loop, and that the capture control is constructed only once. Nothing
  automated runs against a real Hyvä Checkout, so a manual pass over every screen the
  script serves is still required before release; see
  `Test/manual/LOQ-17502-manual-qa.md`. The standalone Alpine harness that ships
  alongside it demonstrates the binding behaviour that caused the defect, but it does
  not load `capture.js` and so proves nothing about the shipped code.

## [2.1.0] - 2026-08-12

### Changed — behaviour

- **Admin order create is no longer AVC-checked, and obeys only the
  "Enable on Create Order (Admin)" toggles.** `Observer\QuoteSubmitBefore` now
  returns early in the admin area. Previously an admin-created order was checked
  twice — against the address quality index through `Plugin\Admin\OrderSave`, and
  against the AVC through that observer — which billed up to two extra
  single-address Cleansing requests per order on top of the batch call, and the two
  paths cannot share a verdict because they judge different thresholds.

  **Action required if you run "Enable on Create Order (Admin)" = No together with
  "Enable on Checkout" = Yes** (for Address Settings or Phone Settings). In that
  combination the observer had been the only thing verifying admin-created orders,
  so nothing verifies them now. Set "Enable on Create Order (Admin)" to Yes to keep
  verifying them. The admin now raises a system message naming the affected settings
  whenever it detects this combination.

- **An unrecognised "Address Quality Index" now rejects addresses instead of
  accepting all of them.** The threshold is compared as a string, so any value
  sorting above `E` — a lowercase `c`, a `C+`, or any stray text — previously passed
  every address on the batch paths, including the worst grade Loqate returns. Valid
  values are `A` to `E`; anything else is refused and logged. Only installs that
  wrote the value via a data patch, `config:set` or direct SQL can be affected — the
  new admin field below cannot produce such a value.

- **"Minimum Address Quality Index" is now configurable in the admin**, under
  Stores → Configuration → Loqate → Address Verification - General (LOQ-17148). It
  was previously reachable only through the shipped default, a data patch or
  `bin/magento config:set`. It is a select over `A`–`E` ("Excellent" to "Bad"), whose
  options are derived from the verifier's own accepted list so the two cannot drift,
  and it is shown at **default scope only**: the setting is read by admin order create
  and customer import, and neither names the store view of the order or the customers
  it is judging — so a website or store-view override could not be relied on to be the
  value applied, and offering one would be a knob whose effect the form could not
  predict.

  **The shipped default is unchanged (`A`, "Excellent") and no install's behaviour
  changes on upgrade.** Loosening the threshold accepts addresses that were
  previously rejected, so it is a deliberate merchant decision and not a default this
  release makes for anybody.

  **If you already set this path at website or store-view scope** (`bin/magento
  config:set --scope=stores …`, a data patch or direct SQL), that row stays in effect
  and keeps overriding the default for the scope it was written for — the new field is
  default-scope only, so it can neither display nor clear it. Remove it with
  `bin/magento config:set --scope=stores --scope-code=<code> …` set back to the value
  you want, or by deleting the `core_config_data` row, and then set the value here.

- **No change to which import rows are rejected.** `checkQualityIndex()` now
  distinguishes "readable quality index that misses the threshold" from "quality
  index or threshold that could not be read", because the first is a verdict worth
  remembering and the second is a fault report. Both still reject the row, and an
  unreadable threshold still rejects every row including grade `E`. What did change is
  the logging: the "not a recognised quality index" line is now emitted once per
  verified batch, from the pre-flight check described under Fixed, instead of once per
  verdict — and it is emitted for files that produced none of it before, because the
  old check was reached only after a readable quality index had been found in the
  response.

- **The broken-threshold log line has been reworded**, so anything matching on its
  text needs updating. It reports the configuration fault only and no longer claims an
  outcome, because it is now emitted once per batch and a batch can be answered
  entirely by the captured-address bypass without a single row being refused. It ends
  `…; no address can pass a quality bar that cannot be read. Set it to one of A, B, C,
  D, E.` where it previously ended `…; rejecting the address. Set it to one of A, B,
  C, D, E.`; the leading `Loqate: address_quality_index is not a recognised quality
  index (<value> of type <type>)` is unchanged.

- **A customer import can now fail where it previously continued silently.** The
  import plugin used to absorb every exception and hand back an untouched result,
  which meant a misconfiguration inside the module surfaced as an import reporting
  no address errors at all — indistinguishable from a clean file. It now lets
  `\InvalidArgumentException` through. In practice that is the module reporting a
  programming error, but a core or third-party `\InvalidArgumentException` raised
  during verification will now fail the import rather than pass it silently. Genuine
  runtime faults — transport, connector, unreadable source file — are still absorbed
  as before.

- **"Submit again to use this email address / phone number" is now remembered per
  shopper, per store view and for the 25 most recent values.** With "Prevent
  Submit" set to No — the shipped default — the module warns once about an invalid
  email address or phone number and accepts it on resubmission. That "already
  warned" list was kept for the whole session with no limit, was shared across a
  logout/login on the same browser, and was shared across store views. Four
  consequences, all of them **one extra billable verification** in the affected
  case and never a skipped check (LOQ-17149):

  - a shopper who signs in or out mid-session is warned once more about an address
    or number they had already resubmitted;
  - the same address or number used under two store views is verified once per
    store view, because each store view can carry its own API key and its own
    "Prevent Submit" setting;
  - only the 25 most recently submitted values per session are remembered, so a
    shopper who works through more than 25 different email addresses (or phone
    numbers) in one session is warned again about the earliest ones;
  - sessions that were already open when this release was deployed are warned once
    more about each value they had remembered, because the stored form changed.

  This applies wherever the module raises "Submit again to use this email address /
  phone number", which is every path through `Plugin\AbstractPlugin` — checkout, the
  customer account forms and admin order create. It does **not** apply to the phone
  check in `Observer\QuoteSubmitBefore`, which runs on quote submit (the Hyvä and
  GraphQL place-order path): that observer verifies the number directly and consults
  no "already warned" list at all, so it neither reads nor writes these lists, it
  bills every quote submit, and an invalid number blocks the order there whatever
  "Prevent Submit" is set to.

- **Two phone numbers that differ only by a leading `0`, `00` or `+` are now
  treated as different numbers.** They were compared with PHP's loose comparison,
  which compares two numeric strings as NUMBERS — so `0123456789` and `123456789`,
  or `+4412345` and `4412345`, counted as the same number and the second was
  accepted with no verification and no warning on the strength of the first. They
  are now verified separately: **one extra billable verification** for a shopper who
  submits both spellings, and no genuinely different number is skipped any more.
  Identical values are still recognised and still skipped, which is what the list is
  for.

### Fixed

- Repeated billable `/Cleansing/International/Batch` requests for the same address
  in one session, at checkout and on admin order create (LOQ-16969, LOQ-16976).
  Verify verdicts are cached per shopper for the session, keyed on the region value
  actually sent to Loqate.
- Repeated billable requests for the same address across the chunks of ONE customer
  import run (LOQ-17148). An import file is verified in chunks of 100 rows inside a
  single request, and the session cache holds passing verdicts only — so a REJECTED
  address was re-sent, and re-billed, in every chunk it appeared in, which on a
  default install (strictest threshold) is most of a file. Verdicts of both polarities
  are now remembered for the length of the run, so **an address repeated in a LATER
  chunk of the same run costs nothing**, however large the file. Measured on the
  branch's own acceptance fixture (260 rows, 210 distinct addresses, repeats across
  chunk boundaries): **19.2% fewer billed addresses**. Two documented cases are not
  covered and are listed under Known limitations below — copies of an address WITHIN
  the chunk it first appears in, and a row whose quality index could not be read. The
  run-scoped memory is never persisted — a rejection never outlives the request, so
  correcting the file or loosening the threshold always re-verifies.
- A customer import no longer pays for a verification whose answer is already
  decided (LOQ-17148). When "Minimum Address Quality Index" holds a value that is not
  a grade, every row is rejected whatever Loqate answers — so the threshold is now
  checked BEFORE the request is assembled, every row is rejected without one being
  made, and the "not a recognised quality index" line is logged once per batch. It
  previously billed the whole file, on every Check Data click, for an answer that
  could not change the outcome, and logged nothing at all when the responses were
  themselves unreadable.
- Multi-address verification results were collapsed onto the first row, so one
  address's verdict could be reported against another's (LOQ-16977).
- A cached verify success could be replayed for a region that was never verified
  (LOQ-16979).
- A mid-import API failure crashed the customer import with a 500 instead of
  degrading: `array_merge()` received `false` and raised a `TypeError`, which the
  surrounding `catch (\Exception)` could not catch.
- The `captured_addresses` session store grew without limit for the whole session,
  and its verify bypass survived a logout/login on a shared browser. It is now
  bounded to 50 entries with FIFO eviction, and it and both verdict caches are
  flushed when the logged-in customer changes (LOQ-16978).
- **A billing-address block earned by the PREVIOUS shopper at a shared browser no
  longer denies the next shopper their checkout.** A failed billing-address
  validation sets a session flag that both place-order plugins refuse the order on,
  with the message "Please check the error again before continuing." and no field
  named that the shopper could correct. That flag survived a logout/login, so one
  shopper's validation failure could block the next shopper's checkout — and because
  the flag is checked *before* the place-order call assigns the billing address, on a
  flow that submits the two together the next shopper had nothing on the page they
  could do about it. The flag is now cleared when the logged-in customer changes, so
  an **inherited** block is gone (LOQ-17149).

  **This does not fix the same defect for the shopper who tripped the flag
  themselves.** On those same flows their own corrected resubmission still cannot
  clear it, because the flush needs an identity change to see and there is none. That
  is a live defect, not a fixed one; it is listed under Known limitations below and
  tracked as LOQ-17195.
- **An email address left behind by an abandoned checkout was verified inside the
  next shopper's checkout.** The pending address is cleared only on a successful
  verification, so a guest who typed an email and left it behind left it in the
  session. The next shopper's shipping-address save then spent a billable email
  verification on it and, if it failed, blocked their checkout with a message about
  an address that appeared nowhere on their form. It is now cleared when the
  logged-in customer changes (LOQ-17149).
- **The "already warned about" email and phone lists no longer hold the values.**
  They held the customer's email address and phone number in plain text for the whole
  life of the session. They now hold a salted, full-length HMAC-SHA-256 digest
  instead: the lists only ever need to *compare*, never to read a value back. The
  salt is generated per session, never persisted or configured, and is replaced
  whenever the logged-in customer changes. Values written by earlier releases are
  discarded the first time the list is written to. This covers the email address and
  the phone number only.

  **Four things in the session are still readable, and only one of them has to be.**
  The pending checkout email address described above is read back and sent to Loqate
  to be verified, and a digest cannot be verified, so it stays raw. The other three
  are addresses: the `captured_addresses` store, which holds in full the addresses
  picked from the Capture lookup, and the two verify caches
  (`loqate_verified_addresses`, `loqate_verified_batch_addresses`), whose entries hold
  only a verdict and a schema version but whose **keys** carry the address in plain
  text — the two street lines, the city, the postcode, the country, the full joined
  street and the region, normalised. Those three are unhashed because they were
  **outside LOQ-17149's scope**, which was the two contact lists. They are tracked as
  LOQ-17196.

  **All three can be hashed**, on LOQ-17149's own argument for hashing the contact
  lists — a store that only has to compare does not need to hold the value. Every
  reader of all three reduces an address to one normalised signature string and
  compares strings: `Validator::checkForCapturedAddress()`,
  `Helper\Controller::isSameCapturedAddress()` and both cache-key builders. No field
  value is ever read back for any other purpose, and a key being a key is no obstacle
  either — `buildBatchVerifyCacheKey()` already hashes the threshold segment of that
  same key. The one real cost is named so that it is weighed rather than rediscovered
  as a blocker: `isSameCapturedAddress()` would lose its byte-equality fast path,
  which is the only thing that can collapse a duplicate whose signature is empty.
  Until LOQ-17196 is done the exposure is exactly what this module's own ACCEPTED
  LIMITS block states — a second admin at a shared browser can read every address the
  first one verified, imported or looked up (LOQ-17149).
- **An admin order create no longer copies the submitted order into the session.**
  When a check failed, `Plugin\Admin\OrderSave` handed the whole POST — the account
  email address, every address's telephone, the names and the streets — to Magento's
  `customer_form_data` session attribute, raw, in the same request that stored the
  digest above; and because nothing in the admin panel reads that attribute back off
  the customer session, nothing ever cleared it. It stayed for the rest of the
  admin's browser session. The call is removed. Nothing re-populated the order-create
  form from it (the form is rebuilt from the backend quote session), so there is no
  behaviour change; the storefront account-create and account-edit paths, where core
  really does re-render the form from it and clears it on read, are untouched
  (LOQ-17149).

### Changed — for developers extending this module

- **`Plugin\AbstractPlugin::$session` is now `private`.** It was `protected` on the
  base class of ten plugins, which meant any of them — or any third-party subclass —
  could read or write the module's session stores directly and bypass the bounding
  and the shopper-change flush described above. Subclasses now use the narrow named
  accessors on the base class (`shouldVerify()`, `pendingEmailAddress()`,
  `rememberPendingEmailAddress()`, `clearPendingEmailAddress()`,
  `recordBillingAddressErrors()`, `rememberCustomerFormData()`,
  `rememberAddressFormData()`). A subclass that used `$this->session` will no longer
  compile; that is the intended effect, because such a subclass was reaching the
  stores without the guard. The constructor signature is unchanged, so no
  `di.xml` and no subclass constructor needs editing (LOQ-17149).
- **`Helper\ShopperScopedAddressStores` is now `Helper\ShopperScopedSessionStores`.**
  It guards eight session stores rather than the three address ones it started with,
  so the old name had become inaccurate. The class has never appeared in a tagged
  release, is never registered in DI and is held only in private properties, so no
  alias is kept. Every session attribute *value* is unchanged, so open sessions keep
  their stores across the upgrade (LOQ-17149).

## Known limitations

- **Dedupe across import RUNS is not delivered for a file with more than 200 distinct
  addresses** (LOQ-17148 delivers dedupe *within* one run, above). The session cache
  holds 200 entries and evicts oldest-first, so a second Check Data click or a second
  import of a larger file re-bills essentially every row: a sequential scan through a
  smaller FIFO cache has a ~0% hit rate. For a programmatic, CLI or cron-driven import
  it is 0% — each process starts a fresh session, so nothing one run writes is ever
  found by the next, and no cache *size* changes that.

  Four apparent fixes were considered and rejected, so they are not quietly retried.
  *Caching failures in the session store* is a regression on the target workload: at a
  5% pass rate it takes a second run from 950 billed addresses to 1000 (1000 distinct
  rows) and from 475 to 500 (500 rows), because failures crowd out the sparse passes
  the bounded FIFO store manages to keep. *Freezing the store when full* would let one
  Check Data click on a 200-row file fill it for the rest of that admin's browser
  session, destroying the admin-order-create saving above. *Sizing the store to the
  file* costs ~147 bytes per entry (~141 KB at 1000 entries, ~712 KB at 5000), and
  Magento reads and writes the whole session on every request — ~1.4 MB of session I/O
  on every unrelated admin page load, ~278 MB over 200 page loads, for a re-run that
  may never happen. Those three are all session-store variants and none of them moves
  the CLI/cron figure at all; the one option that would is a **non-session store**
  (`Magento\Framework\App\CacheInterface`, keyed on the threshold- and
  store-view-namespaced hash the module already builds). It is rejected here rather
  than overlooked, and the risk being declined is stated plainly: a verdict held
  outside the session is not shopper-specific, so it can be read and replayed for
  another shopper, another admin user, or — on shared infrastructure — another install
  using the same cache backend, which is the bypass-across-identities leak LOQ-16978
  was raised to close. Doing it safely needs an identity in the key, a cache tag and
  lifetime policy, and a decision on whether an address verdict may live outside the
  session at all. That is its own ticket, raised separately as **LOQ-17197**.
- The same address repeated **within the chunk in which it first appears** is billed
  once per copy: nothing is remembered until the response returns, so every copy in
  that chunk misses and every copy is sent. The bound is therefore the **chunk size**
  — 100 identical rows in one chunk cost 100 billed addresses — not one duplicate
  charge per address. Every appearance in a LATER chunk is free. Tracked as LOQ-17015,
  separately from LOQ-17148 and **not resolved by it**, because collapsing duplicates
  changes the payload row arithmetic and has to be done together with the
  response-row-count guard.
- A row whose **quality index could not be read** is rejected and remembered nowhere,
  so it is billed again on every occurrence, for the whole run and every run after it.
  `'Matches' => []` is Loqate's ordinary answer for an address it cannot match, so on a
  poorly-matching file that is most of the file. Deliberate: remembering it would let
  one connector fault or one bad credential mark every matching row invalid for the
  rest of the run, sending the merchant to correct rows Loqate never rejected.
- Rows whose address columns are **empty or unusable** produce no identifiable
  signature, and are deduplicated by neither memory — every occurrence is sent and
  billed, with no bound. The alternative is worse: one shared key would file every
  unidentifiable address under the same entry and replay one row's verdict for all of
  them.
- The shopper-change flush scopes by **customer** identity, so an **admin-user swap
  within one browser session is not covered**. LOQ-17149 decided to leave it there
  rather than read the backend session as well; the ACCEPTED LIMITS block on
  `Helper\ShopperScopedSessionStores` states the mechanism and points back here for
  what follows from it, which is quantified in the rest of this entry. The admin
  panel runs its own PHP session, separate from the storefront's, so this is
  admin-to-admin on one shared browser and never admin-to-shopper. What a second
  admin can inherit is a verification the first already paid for — an identical admin
  order create, customer-email re-check, address re-check or Capture lookup — so the
  merchant pays nothing extra and only the attribution between two admin users is
  imprecise. Since v2.1.0 the two contact lists hold salted digests rather than
  values, so no email address or phone number is inherited with it. The **addresses
  are still readable**, and all three address stores are: `captured_addresses` holds
  the addresses the first admin looked up, and the keys of the two verify caches carry
  the addresses the first admin verified or imported, in plain text. That is unchanged
  by v2.1.0 in either direction. Closing the swap itself would mean either a
  backend dependency in a helper built on every storefront checkout request or a
  second bill for a verdict that is identical by construction.
- For the same reason, **two successive guests on one browser are not covered
  either**: the flush needs an identity change to see, and two people who never sign
  in are the same identity. On a shared terminal or a click-and-collect kiosk the
  second person can therefore inherit the first's "already warned about" entries — so
  a value of theirs goes through unverified and unwarned — and can inherit an email
  address the first left pending at the checkout email step, which is the one store
  that has to hold the value rather than a digest. There is no signal to close this
  with: nothing tells the application that a different person is at the browser when
  neither of them authenticates. What bounds it is the **session lifetime**, which a
  merchant running a shared terminal should shorten (LOQ-17149).
- **A shopper who trips the billing-address block themselves cannot clear it, on any
  checkout flow that submits the billing address together with the place-order call**
  (LOQ-17195). This is a live defect and is NOT fixed by the flush described under
  Fixed above; the flush fixes only the block *inherited* across a shopper change. The
  mechanism is the ordering: the flag is written by one method only,
  `BillingAddressManagement::assign()`, while the two readers are `before` plugins on
  `savePaymentInformationAndPlaceOrder()`, which assigns the billing address further
  down the same call. The first attempt therefore passes the gate, fails validation,
  sets the flag and throws; every attempt after it is refused by the gate *before*
  `assign()` runs, so the only thing that could clear the flag never runs again.
  Correcting the address does not help and the message names no field. The flush
  cannot reach it either — it needs an identity change to see, and this is one shopper
  in one session. **Exposed:** the flows that submit the billing address with the
  place-order call, which for this module are the Hyvä and GraphQL place-order path
  named under Changed above. **Not exposed:** default Luma checkout, which calls
  `assign()` in a request of its own, so a corrected resubmission clears the flag
  before the place-order call reads it. What clears it today is signing in or out, or
  the session ending. The reading is pinned as behaviour by
  `Test/Unit/Plugin/Frontend/BillingErrorGateTest.php`, whose assertions have to be
  INVERTED when LOQ-17195 is fixed.
