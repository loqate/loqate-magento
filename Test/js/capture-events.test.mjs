/**
 * LOQ-17502 - event contract of the vendored Loqate capture SDK.
 *
 * These tests load the REAL, unmodified `view/base/web/capture.js` into a jsdom
 * document and drive the SDK's own UI. Nothing about the SDK's event behaviour is
 * re-implemented here: every assertion is an observation of the real script. If
 * `pca.reactTriggerChange` stopped dispatching `input` (Hyva/Alpine) or `change`
 * (Luma/Knockout and the admin UI components), these tests fail.
 */

import { test, describe, before, after } from "node:test";
import assert from "node:assert/strict";

import {
  createHarness,
  findItem,
  retrieveItem,
  CODE_MATCHED_REGION_OPTIONS,
} from "./support/harness.mjs";

/** The text fields the `default` mapping populates (capture.js:7787-7817). */
const POPULATED_TEXT_FIELDS = [
  "street[0]",
  "street[1]",
  "company",
  "city",
  "postcode",
  "region",
];

describe("(a) populate event contract", () => {
  let harness;
  let recorder;

  before(async () => {
    // Arrange: real SDK, Magento-shaped form, stubbed find/retrieve endpoints.
    harness = await createHarness();
    recorder = harness.recordEvents([...POPULATED_TEXT_FIELDS, "region_id"]);

    // Act: type into the search field and click the suggestion the SDK renders.
    await harness.searchAndPick();
  });

  after(async () => {
    await harness.close();
  });

  test("the SDK actually reached its endpoints and populated the form", () => {
    // Non-vacuity guard: if the stub server or the UI drive silently did nothing,
    // every "exactly one event" assertion below would pass for the wrong reason.
    assert.equal(harness.server.requests.find, 1, "one find request");
    assert.equal(harness.server.requests.retrieve, 1, "one retrieve request");
    assert.equal(harness.field("city").value, "London");
    assert.equal(harness.field("postcode").value, "SW1A 2AA");
    assert.equal(harness.field("street[0]").value, "10 Downing Street");
    assert.deepEqual(harness.consoleErrors, []);
  });

  for (const name of POPULATED_TEXT_FIELDS) {
    test(`text field ${name} receives exactly one input then exactly one change`, () => {
      assert.deepEqual(
        recorder.types(name),
        ["input", "change"],
        `${name} event sequence`
      );
    });
  }

  test("the input event carries the retrieved value, not an intermediate one", () => {
    // Alpine reads event.target.value when it handles `input`, so the value must
    // already be the retrieved one by the time the event is dispatched.
    const [input, change] = recorder.entries("city");
    assert.equal(input.value, "London");
    assert.equal(change.value, "London");
  });

  test("the region_id select still receives its change event", () => {
    // Load-bearing: pca.setValue matches the "Kent" option on its TEXT and returns
    // early for a SELECT (capture.js:3364-3372), so the change comes from
    // mapRegionSelectValue's own dispatch (capture.js:7961, via
    // mapRegionSelectValueWithRetry). Knockout, the admin UI components and
    // Alpine all need it. A select must NOT get an `input`.
    assert.deepEqual(recorder.types("region_id"), ["change"]);
    assert.equal(harness.field("region_id").value, "1", "matched the Kent option");
  });
});

describe("(c) F2 regression paths that bypass the populate event", () => {
  test("drill-down (filterSearch) emits input then change on the search field", async () => {
    // Arrange: a non-Address suggestion, which sends address.select down the
    // filterSearch branch (capture.js:7181-7187 -> 7164-7169).
    const harness = await createHarness({
      find: [
        findItem({
          Id: "GB|RM|ENG|LONDON",
          Type: "Container",
          Text: "Downing Street, London",
          Description: "12 addresses",
        }),
      ],
    });

    try {
      const recorder = harness.recordEvents(["street[0]"]);
      await harness.search("Downing");
      const findsBefore = harness.server.requests.find;
      recorder.reset(); // scope the recording to the drill-down itself

      // Act: click the container suggestion, exactly as a shopper would.
      harness.clickSuggestion(0);
      // filterSearch rewrites the field and THEN searches (capture.js:7165-7178),
      // so waiting on the follow-up find also guarantees the rewrite has landed.
      // A fixed sleep here would be a race on a loaded runner.
      await harness.waitFor(
        () => harness.server.requests.find > findsBefore,
        { label: "the drill-down's follow-up find request to arrive" }
      );

      // Assert: the search field was rewritten by
      // pca.setValue(field, searchText + " ", simulateReactEvents) and therefore
      // got both events; and the drill-down did issue its follow-up search.
      assert.deepEqual(recorder.types("street[0]"), ["input", "change"]);
      assert.equal(harness.field("street[0]").value, "Downing ");
      assert.ok(
        harness.server.requests.find > findsBefore,
        "the drill-down issues a follow-up find (this one is expected and correct)"
      );
    } finally {
      await harness.close();
    }
  });

  test("country-list cancel restores the stored search with input then change", async () => {
    // NOTE ON HOW THIS PATH IS DRIVEN: the flag button / country list is not
    // clicked here. The country list is opened and closed by calling the SDK's own
    // `switchToCountrySelect()` and `countrylist.autocomplete.hide()`. `hide()` is
    // the method the real UI calls, and it fires the `hide` event whose listener
    // (capture.js:6548-6552) is the code under test. So the handler under test is
    // the real one, but the route into it is an SDK API call rather than a click.
    const harness = await createHarness();

    try {
      const recorder = harness.recordEvents(["street[0]"]);
      await harness.search("10 Downing");

      harness.control.switchToCountrySelect();
      assert.equal(
        harness.control.storedSearch,
        "10 Downing",
        "the SDK stored the in-progress search"
      );
      assert.equal(
        harness.field("street[0]").value,
        "",
        "the SDK cleared the search field when switching to the country list"
      );
      recorder.reset(); // scope the recording to the cancel

      // Act: close the country list, which restores the stored search.
      harness.control.countrylist.autocomplete.hide();
      await harness.waitFor(
        () => harness.field("street[0]").value === "10 Downing",
        { label: "the stored search to be restored into the search field" }
      );

      // Assert.
      assert.equal(harness.field("street[0]").value, "10 Downing");
      assert.deepEqual(recorder.types("street[0]"), ["input", "change"]);
    } finally {
      await harness.close();
    }
  });
});

describe("(d) a synthetic input event is never billable", () => {
  test("dispatching input on the mapped fields issues no find or retrieve request", async () => {
    const harness = await createHarness();

    try {
      // Arrange.
      assert.deepEqual(harness.server.requests, {
        find: 0,
        retrieve: 0,
        other: 0,
      });

      // Act: a bubbling `input` on the search field and on a populated field,
      // which is what Alpine/Magewire re-renders produce.
      for (const name of ["street[0]", "city"]) {
        const element = harness.field(name);
        element.value = "SW1A 2AA";
        element.dispatchEvent(
          new harness.window.Event("input", { bubbles: true })
        );
      }
      // This one stays a fixed wait on purpose: the assertion is that NOTHING
      // happens, and there is no condition to wait for. 200ms is comfortably more
      // than the SDK's own search delay.
      await harness.tick(200);

      // Assert: capture.js binds keyup/keydown/keypress/paste/click/dblclick/
      // change (capture.js:1762-1788, 1812-1837) and never `input`, so nothing
      // was requested.
      assert.equal(harness.server.requests.find, 0, "no find request");
      assert.equal(harness.server.requests.retrieve, 0, "no retrieve request");

      // Non-vacuity guard: the counters DO move when the SDK is really driven.
      // `city` is reset first because the arrange step above left "SW1A 2AA" in
      // it, and `searchAndPick` waits for `city` to become non-empty - with the
      // stale value still there that wait would return instantly and the guard
      // would prove nothing about whether the populate landed.
      harness.field("city").value = "";
      await harness.searchAndPick("10 Downing");
      await harness.waitFor(
        () => harness.server.requests.retrieve === 1,
        { label: "the retrieve request to arrive" }
      );
      assert.equal(harness.server.requests.find, 1);
      assert.equal(harness.server.requests.retrieve, 1);
      // ...and the populate really landed, which is what makes the counters above
      // meaningful rather than incidental.
      assert.equal(harness.field("city").value, "London");
      assert.equal(harness.field("postcode").value, "SW1A 2AA");
    } finally {
      await harness.close();
    }
  });
});

describe("(e) the control is constructed once", () => {
  test("a single street[0] yields one pca.Address, and DOM churn does not add more", async () => {
    const harness = await createHarness();

    try {
      // Arrange / Assert: one instance after the module's bootstrap.
      assert.equal(harness.addressConstructions, 1);

      // Act: wake the MutationObserver (capture.js:8071-8079, debounced 50ms)
      // with unrelated DOM churn, twice, and wait well past the debounce. These
      // waits stay time-based deliberately: the assertion is that a debounced
      // callback did NOT construct a second control, so there is no condition to
      // poll for - only a window in which the wrong thing could have happened.
      for (let i = 0; i < 2; i++) {
        const noise = harness.document.createElement("div");
        noise.textContent = `magewire morph ${i}`;
        harness.document.body.appendChild(noise);
        await harness.tick(10);
        noise.remove();
        await harness.tick(120);
      }
      await harness.tick(200);

      // Assert: no re-init loop.
      assert.equal(
        harness.addressConstructions,
        1,
        "pca.Address must not be reconstructed on unrelated DOM mutations"
      );

      // And the single instance still works end to end.
      await harness.searchAndPick();
      assert.equal(harness.field("city").value, "London");
      assert.equal(harness.addressConstructions, 1);
    } finally {
      await harness.close();
    }
  });
});

describe("(f) region select matched by data attribute, not by option text", () => {
  // The form used here is the OTHER shape Magento renders in the wild: the option
  // text is a display label ("Kent County") that does not equal the Loqate
  // ProvinceName, and the region code lives in a data attribute. `pca.setValue`
  // compares only `option.value` and `option.text` (capture.js:3364-3372), so it
  // matches nothing at all; `mapRegionSelectValue` also tries `data-title`,
  // `data-code` and `data-region-code` (capture.js:7946-7952), and it is those
  // attributes - not the text - that resolve the option here. The text-matching
  // shape stays covered by suite (a), which uses the default form.

  test("data-code resolves the option when the option text does not match", async () => {
    // Arrange: ProvinceName "KEN" matches option 1 only on data-code.
    const harness = await createHarness({
      regionOptions: CODE_MATCHED_REGION_OPTIONS,
      retrieve: [retrieveItem({ ProvinceName: "KEN" })],
    });

    try {
      const recorder = harness.recordEvents(["region_id", "region"]);

      // Act.
      await harness.searchAndPick();

      // Assert: the right option is selected, by value and by index.
      const select = harness.field("region_id");
      assert.equal(select.value, "43", "the Kent County option is selected");
      assert.equal(select.selectedIndex, 1);
      // capture.js:7960 also mirrors the value onto the attribute, which is what
      // survives a Magewire morph that re-reads attributes.
      assert.equal(select.getAttribute("value"), "43");

      // Assert the matcher really was the data attribute and not the text: the
      // option's text differs from the retrieved ProvinceName, so a text-only
      // matcher (i.e. pca.setValue on its own) could not have selected it.
      const option = select.options[select.selectedIndex];
      assert.equal(option.text, "Kent County");
      assert.equal(option.getAttribute("data-code"), "KEN");
      assert.notEqual(
        option.text.trim().toLowerCase(),
        "ken",
        "if the text matched, this test would not be exercising data-code"
      );

      // And the select still ends up notifying its listeners.
      assert.ok(
        recorder.types("region_id").includes("change"),
        "the select must still get a change event"
      );
      assert.ok(
        !recorder.types("region_id").includes("input"),
        "a select must never get an input event"
      );

      // The `region` TEXT input is mapped to ProvinceName too, and pca.setValue
      // writes it verbatim - so it holds the raw code, not the display label.
      // Pinned as observed behaviour of the current mapping, not as an ideal.
      assert.equal(harness.field("region").value, "KEN");
      assert.deepEqual(recorder.types("region"), ["input", "change"]);
      assert.deepEqual(harness.consoleErrors, []);
    } finally {
      await harness.close();
    }
  });

  test("data-region-code resolves the option when data-code is absent", async () => {
    // Arrange: ProvinceName "SRY" matches option 2 only on data-region-code -
    // the last candidate before `option.value` (capture.js:7946-7952).
    const harness = await createHarness({
      regionOptions: CODE_MATCHED_REGION_OPTIONS,
      retrieve: [retrieveItem({ ProvinceName: "SRY" })],
    });

    try {
      // Act.
      await harness.searchAndPick();

      // Assert.
      const select = harness.field("region_id");
      assert.equal(select.value, "44", "the Surrey County option is selected");
      const option = select.options[select.selectedIndex];
      assert.equal(option.text, "Surrey County");
      assert.equal(option.getAttribute("data-code"), null);
      assert.equal(option.getAttribute("data-region-code"), "SRY");
    } finally {
      await harness.close();
    }
  });

  test("an unmatchable ProvinceName leaves the select on its placeholder", async () => {
    // The complement of the two tests above, and the reason the "stale first
    // change" below is a real risk rather than a theoretical one: when nothing
    // matches, the ONLY change dispatched is the stale one, and the select is
    // left empty. This is pre-existing SDK behaviour and correct - there is no
    // option to select - but it means a consumer must not treat the first change
    // as final.
    const harness = await createHarness({
      regionOptions: CODE_MATCHED_REGION_OPTIONS,
      retrieve: [retrieveItem({ ProvinceName: "Nowhereshire" })],
    });

    try {
      const recorder = harness.recordEvents(["region_id"]);
      await harness.searchAndPick();
      // mapRegionSelectValueWithRetry retries at 75/150/300/600ms and then gives
      // up (capture.js:7971-7989); wait past the whole ladder. Time-based on
      // purpose: the assertion is that nothing further happens.
      await harness.tick(1400);

      assert.equal(harness.field("region_id").value, "");
      assert.deepEqual(
        recorder.entries("region_id"),
        [{ type: "change", value: "" }],
        "one stale change and nothing else; the retry ladder never matches"
      );
    } finally {
      await harness.close();
    }
  });

  test("PRE-EXISTING: a select pca.setValue cannot match gets two change events, stale then correct", async () => {
    /*
     * DOCUMENTED, NOT A DEFECT TO FIX. capture.js is the vendored SDK and is not
     * to be changed for this.
     *
     * When `pca.setValue`'s SELECT loop matches an option it sets `selectedIndex`
     * and RETURNS (capture.js:3364-3372) - that is the text-matching case covered
     * by suite (a), where the select gets exactly one change. When it matches
     * NOTHING it does not return: it falls through to capture.js:3382-3387 and
     * calls `pca.reactTriggerChange(element)`, which for a select dispatches a
     * `change` (capture.js:3202-3217) while the select still holds its OLD value.
     * `mapRegionSelectValue` then resolves the option properly and dispatches a
     * second `change` with the correct value (capture.js:7961).
     *
     * So a consumer sees change("") then change("43"). Both Knockout and Alpine
     * re-read `event.target.value` on every change, so they converge on the
     * correct value; the first event is redundant, not wrong. This double-fire is
     * exactly what Test/manual/LOQ-17502-manual-qa.md asks a human tester to hunt
     * for, and it predates LOQ-17502: `reactTriggerChange` has always been called
     * on a no-match fall-through. It is pinned here so the next person who sees
     * two events knows it is expected and where each one comes from.
     */
    const harness = await createHarness({
      regionOptions: CODE_MATCHED_REGION_OPTIONS,
      retrieve: [retrieveItem({ ProvinceName: "KEN" })],
    });

    try {
      const recorder = harness.recordEvents(["region_id"]);

      // Act.
      await harness.searchAndPick();

      // Assert the exact observed sequence, values included.
      assert.deepEqual(recorder.entries("region_id"), [
        // 1. pca.setValue matched no option, fell through, and
        //    reactTriggerChange fired with the select's stale (placeholder) value.
        { type: "change", value: "" },
        // 2. mapRegionSelectValue resolved the option via data-code and fired
        //    again, this time with the correct value.
        { type: "change", value: "43" },
      ]);

      // It converges: the last event and the DOM agree.
      assert.equal(harness.field("region_id").value, "43");
    } finally {
      await harness.close();
    }
  });
});

describe("retrieve payload shape", () => {
  test("an empty retrieve response leaves the form untouched", async () => {
    // address.retrieve's success does `response.length ? populate : fail`.
    const harness = await createHarness({ retrieve: [] });

    try {
      await harness.search("10 Downing");
      harness.clickSuggestion(0);
      // Wait on the request itself rather than sleeping for it...
      await harness.waitFor(
        () => harness.server.requests.retrieve === 1,
        { label: "the retrieve request for the picked suggestion to arrive" }
      );
      // ...and only then sleep, because the remaining assertion is that no
      // populate follows, for which there is no condition to wait on.
      await harness.tick(100);

      assert.equal(harness.field("city").value, "");
      assert.equal(harness.server.requests.retrieve, 1);
    } finally {
      await harness.close();
    }
  });

  test("a second retrieve item is ignored - only the first is populated", async () => {
    const harness = await createHarness({
      retrieve: [
        retrieveItem({ City: "London" }),
        retrieveItem({ City: "Manchester" }),
      ],
    });

    try {
      await harness.searchAndPick();
      assert.equal(harness.field("city").value, "London");
    } finally {
      await harness.close();
    }
  });
});
