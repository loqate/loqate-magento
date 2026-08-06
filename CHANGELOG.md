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

- **No change to which import rows are rejected.** `checkQualityIndex()` now
  distinguishes "readable quality index that misses the threshold" from "quality
  index or threshold that could not be read", because the first is a verdict worth
  remembering and the second is a fault report. Both still reject the row, an
  unreadable threshold still rejects every row including grade `E`, and it is still
  logged every time.

- **A customer import can now fail where it previously continued silently.** The
  import plugin used to absorb every exception and hand back an untouched result,
  which meant a misconfiguration inside the module surfaced as an import reporting
  no address errors at all — indistinguishable from a clean file. It now lets
  `\InvalidArgumentException` through. In practice that is the module reporting a
  programming error, but a core or third-party `\InvalidArgumentException` raised
  during verification will now fail the import rather than pass it silently. Genuine
  runtime faults — transport, connector, unreadable source file — are still absorbed
  as before.

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
  are now remembered for the length of the run, so every repeated address costs one
  billed address however often it appears and however large the file: **29% fewer
  billed addresses on a 1000-row file holding 700 distinct addresses, 58.8% on 1000
  rows holding 400.** The run-scoped memory is never persisted — a rejection never
  outlives the request, so correcting the file or loosening the threshold always
  re-verifies — and a quality index or threshold that could not be READ is remembered
  nowhere, so one connector fault cannot mark every matching row invalid.
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

- **Dedupe across import RUNS is not delivered for a file with more than 200 distinct
  addresses** (LOQ-17148 delivers dedupe *within* one run, above). The session cache
  holds 200 entries and evicts oldest-first, so a second Check Data click or a second
  import of a larger file re-bills essentially every row: a sequential scan through a
  smaller FIFO cache has a ~0% hit rate. For a programmatic, CLI or cron-driven import
  it is 0% **by construction** — each process starts a fresh session, so nothing one
  run writes is ever found by the next.

  Three apparent fixes were measured and rejected, so they are not quietly retried.
  *Caching failures in the session store* is a regression on the target workload: at a
  5% pass rate it takes a second run from 950 billed addresses to 1000 (1000 distinct
  rows) and from 475 to 500 (500 rows), because failures crowd out the sparse passes
  the bounded FIFO store manages to keep. *Freezing the store when full* would let one
  Check Data click on a 200-row file fill it for the rest of that admin's browser
  session, destroying the admin-order-create saving above. *Sizing the store to the
  file* costs ~147 bytes per entry (~141 KB at 1000 entries, ~712 KB at 5000), and
  Magento reads and writes the whole session on every request — ~1.4 MB of session I/O
  on every unrelated admin page load, ~278 MB over 200 page loads, for a re-run that
  may never happen.
- The same address repeated **within one 100-row chunk** is still billed twice on that
  address's first appearance in the run: nothing is remembered until the response
  returns, so both copies miss. Every later appearance — in that chunk or any other —
  is answered from memory, so the cost is bounded at one duplicate charge per distinct
  address per run rather than one per occurrence. Tracked as LOQ-17015, separately from
  LOQ-17148 and **not resolved by it**, because collapsing duplicates changes the
  payload row arithmetic and has to be done together with the response-row-count guard.
- `loqate_email`, `loqate_phone`, `loqate_email_to_validate` and
  `loqate_billing_errors` are still unbounded session stores and are not covered by
  the shopper-change flush (LOQ-17149).
- The shopper-change flush scopes by **customer** identity, so an admin-user swap
  within one browser session is not covered. Decided as part of LOQ-17149.
