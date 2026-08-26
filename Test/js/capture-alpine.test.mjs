/**
 * LOQ-17502 - Hyva Checkout survival test.
 *
 * Hyva Checkout binds its address fields with Magewire's `wire:model.defer`, which
 * Alpine turns into `x-model`. Alpine syncs a text input into component state on
 * `input` only (it uses `change` for select/checkbox/radio). Before LOQ-17502 the
 * SDK dispatched `change` alone, so a Loqate-populated field was never committed
 * and the next Magewire re-render morphed the blank server value back over it.
 *
 * This test uses REAL Alpine (`alpinejs` from npm, the browser build) and the REAL,
 * unmodified `view/base/web/capture.js`. The only thing simulated is Magewire's
 * commit + morph, which is clearly marked below.
 */

import { test, describe } from "node:test";
import assert from "node:assert/strict";

import {
  createHarness,
  retrieveItem,
  CODE_MATCHED_REGION_OPTIONS,
} from "./support/harness.mjs";

/** form field name -> the x-model expression bound to it in the harness form. */
const MODEL_KEYS = {
  "street[0]": "street0",
  "street[1]": "street1",
  company: "company",
  city: "city",
  region: "region",
  region_id: "regionId",
  postcode: "postcode",
};

/**
 * Simulates one Magewire round trip: read the Alpine component state (what
 * `wire:model.defer` would commit), serialise it over the wire, and morph the
 * returned values back over the DOM.
 *
 * This is the only simulated part of this test. It is deliberately dumb: it just
 * writes the committed state back. If the SDK failed to notify Alpine, the
 * committed state is blank and the morph blanks the field - which is exactly the
 * LOQ-17502 bug.
 */
function commitAndMorph(harness, component) {
  // Commit: Magewire serialises the component state to JSON and posts it.
  // (Alpine's `$data` is a merge proxy - JSON.stringify goes through its own
  // `toJSON` trap, which is how Magewire reads a component's state too.)
  const committed = JSON.parse(JSON.stringify(component));

  // Morph: the server response carries those values back as the new markup.
  for (const [name, key] of Object.entries(MODEL_KEYS)) {
    const element = harness.field(name);
    const value = committed[key] ?? "";
    if (element.tagName === "SELECT") {
      element.value = value;
    } else {
      element.setAttribute("value", value);
      element.value = value;
    }
  }

  return committed;
}

describe("(b) Alpine x-model survives a Loqate populate, a commit and a morph", () => {
  test("jsdom provides the browser APIs Alpine requires", async () => {
    const harness = await createHarness({ alpine: true });
    try {
      const w = harness.window;
      for (const api of [
        "MutationObserver",
        "Proxy",
        "queueMicrotask",
        "requestAnimationFrame",
      ]) {
        assert.equal(
          typeof w[api],
          "function",
          `jsdom must provide window.${api} for Alpine`
        );
      }
      assert.equal(typeof w.Alpine, "object", "real Alpine booted in the document");
      assert.ok(
        harness.document.getElementById("address-form")._x_dataStack,
        "Alpine initialised the address form component"
      );
    } finally {
      await harness.close();
    }
  });

  test("populate reaches Alpine state, and the state survives commit + morph", async () => {
    // Arrange.
    const harness = await createHarness({ alpine: true });

    try {
      const form = harness.document.getElementById("address-form");
      const component = harness.window.Alpine.$data(form);
      const recorder = harness.recordEvents(["city", "postcode"]);

      assert.equal(component.city, "", "starts blank");
      assert.equal(component.postcode, "", "starts blank");

      // Act: drive the real SDK - type, then click the suggestion it renders.
      await harness.searchAndPick();
      // Wait on Alpine having actually synced, rather than sleeping for a
      // scheduler turn: Alpine batches x-model writes through its own reactivity
      // queue, and how many turns that takes is not ours to assume.
      await harness.waitFor(
        () => component.city === "London" && component.postcode === "SW1A 2AA",
        { label: "Alpine to sync the populated values into component state" }
      );

      // Assert 1: the populate reached Alpine's component state. This is only
      // possible because the SDK dispatched `input`.
      assert.deepEqual(recorder.types("city"), ["input", "change"]);
      assert.deepEqual(recorder.types("postcode"), ["input", "change"]);
      assert.equal(component.city, "London");
      assert.equal(component.postcode, "SW1A 2AA");

      // Act: Magewire commits the component state and morphs the response back.
      const committed = commitAndMorph(harness, component);
      // Fixed wait on purpose: the morph is synchronous, so the values are
      // already in place; what this window is for is letting any late SDK timer
      // or Alpine effect blank them again, which is the failure being excluded.
      await harness.tick(50);

      // Assert 2: the committed payload carried the retrieved address...
      assert.equal(committed.city, "London");
      assert.equal(committed.postcode, "SW1A 2AA");
      // ...so the morph re-wrote the same values, not blanks.
      assert.equal(harness.field("city").value, "London");
      assert.equal(harness.field("postcode").value, "SW1A 2AA");
      assert.equal(harness.field("city").getAttribute("value"), "London");
      assert.equal(harness.field("street[0]").value, "10 Downing Street");
      // The region select is committed too, off the back of its `change`.
      assert.equal(committed.regionId, "1");
      assert.equal(harness.field("region_id").value, "1");

      // And Alpine's state is still consistent after the morph.
      assert.equal(harness.window.Alpine.$data(form).city, "London");
    } finally {
      await harness.close();
    }
  });

  test("negative control: a value written with no event is lost by the same morph", async () => {
    // This proves the commit + morph simulation above has teeth rather than
    // passing vacuously. It uses the real SDK's own `pca.setValue` with the
    // `simulateReactTrigger` flag off - the pre-LOQ-17502 situation, where the
    // field is written but no event is dispatched - and shows that the identical
    // morph blanks the field.
    const harness = await createHarness({ alpine: true });

    try {
      const form = harness.document.getElementById("address-form");
      const component = harness.window.Alpine.$data(form);
      const recorder = harness.recordEvents(["city"]);

      // Act: the SDK writes the value silently.
      harness.window.pca.setValue(harness.field("city"), "London", false);
      // Fixed wait: the assertion is that NO event and NO Alpine sync happen, so
      // there is no condition to poll for.
      await harness.tick(50);

      // Assert: DOM has it, no event was dispatched, Alpine never heard about it.
      assert.equal(harness.field("city").value, "London");
      assert.deepEqual(recorder.types("city"), []);
      assert.equal(component.city, "", "Alpine state was not updated");

      // Act: the very same commit + morph.
      const committed = commitAndMorph(harness, component);
      await harness.tick(50);

      // Assert: the value is gone - the LOQ-17502 bug, reproduced.
      assert.equal(committed.city, "");
      assert.equal(
        harness.field("city").value,
        "",
        "the morph must blank a field that never reached Alpine state"
      );
    } finally {
      await harness.close();
    }
  });

  test("the pre-existing double change on an unmatched select converges in Alpine state", async () => {
    /*
     * The companion to the "PRE-EXISTING: ... two change events" test in
     * capture-events.test.mjs. There the double-fire is pinned at the DOM level;
     * here it is checked against a real Alpine `x-model` on the select, because
     * "it converges, so it is not a defect" is only true if the LAST event wins.
     * Alpine syncs a select on `change`, so it sees change("") then change("43")
     * and must end on "43".
     */
    const harness = await createHarness({
      alpine: true,
      regionOptions: CODE_MATCHED_REGION_OPTIONS,
      retrieve: [retrieveItem({ ProvinceName: "KEN" })],
    });

    try {
      const form = harness.document.getElementById("address-form");
      const component = harness.window.Alpine.$data(form);
      const recorder = harness.recordEvents(["region_id"]);

      // Act.
      await harness.searchAndPick();
      await harness.waitFor(() => component.regionId === "43", {
        label: "Alpine to settle on the correctly matched region option",
      });

      // Assert: two change events reached Alpine, the stale one first...
      assert.deepEqual(recorder.entries("region_id"), [
        { type: "change", value: "" },
        { type: "change", value: "43" },
      ]);
      // ...and the state converged on the second, both before and after a commit.
      assert.equal(component.regionId, "43");
      const committed = commitAndMorph(harness, component);
      assert.equal(committed.regionId, "43");
      assert.equal(harness.field("region_id").value, "43");
    } finally {
      await harness.close();
    }
  });
});
