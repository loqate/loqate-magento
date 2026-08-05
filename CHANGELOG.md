# Changelog

All notable changes to this module are documented here.

This file starts at the LOQ-16969 series; releases up to and including v2.0.17
predate it and are described by their git tags and commit history.

## [Unreleased]

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
  values are `A` to `E`; anything else is refused and logged. The setting has no
  admin field, so this only affects installs that wrote it via a data patch,
  `config:set`, or direct SQL.

### Fixed

- Repeated billable `/Cleansing/International/Batch` requests for the same address
  in one session, at checkout and on admin order create (LOQ-16969, LOQ-16976).
  Verify verdicts are cached per shopper for the session, keyed on the region value
  actually sent to Loqate.
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

### Known limitations

- Repeat-request dedupe is near-nil on the **customer import** path: the batch cache
  holds 200 entries, and only passing verdicts are cached, so re-running a large
  file yields few hits (LOQ-17148).
- `loqate_email`, `loqate_phone`, `loqate_email_to_validate` and
  `loqate_billing_errors` are still unbounded session stores and are not covered by
  the shopper-change flush (LOQ-17149).
- The shopper-change flush scopes by **customer** identity, so an admin-user swap
  within one browser session is not covered.
