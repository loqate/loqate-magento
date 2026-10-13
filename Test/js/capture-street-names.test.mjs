// LOQ-18103 - Hyva street inputs (`street`, repeated `street[]`, forms inserted after load)
// and forms missing mapped fields, driven through the real capture.js like the other suites.

import { test, describe } from "node:test";
import assert from "node:assert/strict";

import { createHarness } from "./support/harness.mjs";

/** The non-street fields every Hyva form below shares. */
const OTHER_FIELDS = `
      <input type="text" name="city" id="city">
      <input type="text" name="postcode" id="postcode">
      <select name="country_id" id="country_id">
        <option value="GB">United Kingdom</option>
      </select>`;

const SINGLE_STREET_FORM = `
    <form id="address-form">
      <input type="text" name="street" id="street_1">${OTHER_FIELDS}
    </form>`;

const STREET_ARRAY_FORM = `
    <form id="address-form">
      <input type="text" name="street[]" id="street_1">
      <input type="text" name="street[]" id="street_2">${OTHER_FIELDS}
    </form>`;

describe("LOQ-18103 (1) single street line: name=\"street\"", () => {
  test("Capture binds to the single street input and writes Line1 and Line2 into it", async () => {
    // Arrange: no company, region or region_id either, as Hyva can render it.
    const harness = await createHarness({ formHtml: SINGLE_STREET_FORM });

    try {
      assert.equal(
        harness.addressConstructions,
        1,
        'a pca.Address control must be attached to input[name="street"]'
      );

      // Act.
      await harness.searchAndPick("10 Downing", { fieldName: "street" });

      // Assert: both lines land in the one field, Line1 first; nothing is dropped.
      assert.equal(harness.field("street").value, "10 Downing Street, Westminster");
      assert.equal(harness.field("city").value, "London");
      assert.equal(harness.field("postcode").value, "SW1A 2AA");
      assert.deepEqual(harness.consoleErrors, []);
    } finally {
      await harness.close();
    }
  });
});

describe("LOQ-18103 (2) repeated street inputs: name=\"street[]\"", () => {
  test("Capture binds to the first street[] input and puts Line2 in the second", async () => {
    // Arrange.
    const harness = await createHarness({ formHtml: STREET_ARRAY_FORM });

    try {
      assert.equal(
        harness.addressConstructions,
        1,
        'a pca.Address control must be attached to the first input[name="street[]"]'
      );

      // Act: harness.field("street[]") is the first match, i.e. #street_1.
      await harness.searchAndPick("10 Downing", { fieldName: "street[]" });

      // Assert.
      assert.equal(harness.document.getElementById("street_1").value, "10 Downing Street");
      assert.equal(harness.document.getElementById("street_2").value, "Westminster");
      assert.equal(harness.field("city").value, "London");
      assert.deepEqual(harness.consoleErrors, []);
    } finally {
      await harness.close();
    }
  });

  test("a street[] form inserted after page load (Hyva 'Add new address' modal) gets Capture attached", async () => {
    // Arrange: no address form at load time.
    const harness = await createHarness({ formHtml: "" });

    try {
      assert.equal(harness.addressConstructions, 0);

      // Act: the modal renders its form into the page.
      harness.document.body.insertAdjacentHTML("beforeend", STREET_ARRAY_FORM);
      await harness.waitFor(() => harness.addressConstructions === 1, {
        label: "the MutationObserver rescan to attach a control to the inserted form",
      });
      await harness.searchAndPick("10 Downing", { fieldName: "street[]" });

      // Assert.
      assert.equal(harness.document.getElementById("street_1").value, "10 Downing Street");
      assert.equal(harness.document.getElementById("street_2").value, "Westminster");
      assert.equal(harness.addressConstructions, 1, "attached exactly once");
    } finally {
      await harness.close();
    }
  });

  test("two street[] forms reusing the same input ids each get their own control", async () => {
    // Arrange / Act: both forms render #street_1 / #street_2.
    const harness = await createHarness({ formHtml: STREET_ARRAY_FORM + STREET_ARRAY_FORM });

    try {
      // Assert.
      assert.equal(harness.addressConstructions, 2, "one control per form, despite the shared ids");
      assert.deepEqual(harness.consoleErrors, []);
    } finally {
      await harness.close();
    }
  });

  test("closing and reopening the modal releases the old control and binds one live control to the new form", async () => {
    // Arrange: the modal opens once and gets a control.
    const harness = await createHarness({ formHtml: "" });
    const rescan = (count) =>
      harness.waitFor(() => harness.addressConstructions === count, {
        label: `the MutationObserver rescan to create control #${count}`,
      });

    try {
      harness.document.body.insertAdjacentHTML("beforeend", STREET_ARRAY_FORM);
      await rescan(1);

      // Act: close (form removed) and reopen (fresh form inserted), then pick.
      harness.document.getElementById("address-form").remove();
      harness.document.body.insertAdjacentHTML("beforeend", STREET_ARRAY_FORM);
      await rescan(2);
      await harness.searchAndPick("10 Downing", { fieldName: "street[]" });

      // Assert: destroy() on the detached control raised nothing, and only the new one is live.
      assert.deepEqual(harness.consoleErrors, []);
      const [old] = harness.window.__pcaControls;
      assert.equal(old.autocomplete.element.parentNode, null, "destroy() removed the old control's UI");
      assert.ok(
        harness.window.__pcaControls[1].autocomplete.element.isConnected,
        "the cleanup did not destroy the new form's control"
      );
      const live = harness.window.__pcaControls.filter((c) => c.autocomplete.element.isConnected);
      assert.equal(live.length, 1, "exactly one live control");
      assert.equal(live[0], harness.window.__pcaControls[1], "the live control is the new form's");
      assert.equal(harness.document.getElementById("street_1").value, "10 Downing Street");
      assert.equal(harness.document.getElementById("street_2").value, "Westminster");
    } finally {
      await harness.close();
    }
  });
});

describe("LOQ-18103 (3) form lacks fields the mapping wants to fill", () => {
  test("populating a form without region, region_id or company raises no error and fills what is present", async () => {
    // Arrange: a Luma-named form (so only defect 3 is under test) missing the
    // optional mapped fields.
    const harness = await createHarness({
      formHtml: `
    <form id="address-form">
      <input type="text" name="street[0]" id="street_1">
      <input type="text" name="street[1]" id="street_2">${OTHER_FIELDS}
    </form>`,
    });

    try {
      // Act.
      await harness.searchAndPick();
      // Let the region-select retry ladder (75..600ms) run out.
      await harness.tick(1400);

      // Assert.
      assert.equal(harness.field("street[0]").value, "10 Downing Street");
      assert.equal(harness.field("street[1]").value, "Westminster");
      assert.equal(harness.field("city").value, "London");
      assert.equal(harness.field("postcode").value, "SW1A 2AA");
      assert.deepEqual(harness.consoleErrors, [], "no console error or uncaught JS error");
    } finally {
      await harness.close();
    }
  });
});

describe("LOQ-18103 (4) regression guards", () => {
  test("admin order form names (order[billing_address][street][N]) still bind and populate", async () => {
    // Arrange.
    const harness = await createHarness({
      formHtml: `
    <form id="edit_form">
      <input type="text" name="order[billing_address][street][0]">
      <input type="text" name="order[billing_address][street][1]">
      <input type="text" name="order[billing_address][city]">
      <input type="text" name="order[billing_address][postcode]">
      <select name="order[billing_address][country_id]"><option value="GB">United Kingdom</option></select>
    </form>`,
    });
    const byName = harness.field;

    try {
      assert.equal(harness.addressConstructions, 1, "one control for the admin billing form");

      // Act.
      await harness.search("10 Downing", { fieldName: "order[billing_address][street][0]" });
      harness.clickSuggestion();
      await harness.waitFor(() => byName("order[billing_address][city]").value !== "", {
        label: "the retrieved address to be written to the admin form",
      });

      // Assert.
      assert.equal(byName("order[billing_address][street][0]").value, "10 Downing Street");
      assert.equal(byName("order[billing_address][street][1]").value, "Westminster");
      assert.equal(byName("order[billing_address][postcode]").value, "SW1A 2AA");
      assert.deepEqual(harness.consoleErrors, []);
    } finally {
      await harness.close();
    }
  });

  test("Hyva checkout with shipping and billing street[] forms: one control each, a pick fills only its own form", async () => {
    // Arrange: two forms, same input names, different ids.
    const form = (prefix) => `
    <form id="${prefix}">
      <input type="text" name="street[]" id="${prefix}-street-0">
      <input type="text" name="street[]" id="${prefix}-street-1">
      <input type="text" name="city" id="${prefix}-city">
      <input type="text" name="postcode" id="${prefix}-postcode">
      <select name="country_id" id="${prefix}-country"><option value="GB">United Kingdom</option></select>
    </form>`;
    const harness = await createHarness({ formHtml: form("shipping") + form("billing") });
    const byId = (id) => harness.document.getElementById(id);

    try {
      assert.equal(harness.addressConstructions, 2, "one control per form, none for the Line2 inputs");

      // Act: pick in the second (billing) form, which the first control does not own.
      await harness.search("10 Downing", { element: byId("billing-street-0") });
      harness.clickSuggestion();
      await harness.waitFor(() => byId("billing-city").value !== "", {
        label: "the retrieved address to be written to the billing form",
      });
      await harness.tick(120);

      // Assert.
      assert.equal(byId("billing-street-0").value, "10 Downing Street");
      assert.equal(byId("billing-street-1").value, "Westminster");
      assert.equal(byId("billing-postcode").value, "SW1A 2AA");
      assert.equal(byId("shipping-street-0").value, "", "shipping Line1 untouched");
      assert.equal(byId("shipping-street-1").value, "", "shipping Line2 untouched");
      assert.equal(byId("shipping-city").value, "", "shipping city untouched");
      assert.deepEqual(harness.consoleErrors, []);
    } finally {
      await harness.close();
    }
  });
});
