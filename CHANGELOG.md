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

### Known limitations

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
  session at all. That is its own ticket, raised separately — record its id here.
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
- `loqate_email`, `loqate_phone`, `loqate_email_to_validate` and
  `loqate_billing_errors` are still unbounded session stores and are not covered by
  the shopper-change flush (LOQ-17149).
- The shopper-change flush scopes by **customer** identity, so an admin-user swap
  within one browser session is not covered. Decided as part of LOQ-17149.
