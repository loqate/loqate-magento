/**
 * Test harness for the vendored Loqate capture SDK.
 *
 * The single rule of this file: it must never model, re-implement or stand in for
 * anything `view/base/web/capture.js` does. It only
 *
 *   1. serves stub `find`/`retrieve` HTTP responses,
 *   2. builds a Magento-shaped address form,
 *   3. injects the *real, unmodified* `capture.js` source into a jsdom document, and
 *   4. records DOM events and drives the SDK's own UI.
 *
 * Every assertion in the suite is therefore an observation of the real script
 * running. If `capture.js` stopped dispatching an event, the tests would fail.
 */

import assert from "node:assert/strict";
import http from "node:http";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { inspect } from "node:util";
import { JSDOM, VirtualConsole } from "jsdom";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const REPO_ROOT = path.resolve(HERE, "..", "..", "..");

const CAPTURE_JS_PATH = path.join(
  REPO_ROOT,
  "view",
  "base",
  "web",
  "capture.js"
);
const ALPINE_JS_PATH = path.join(
  REPO_ROOT,
  "node_modules",
  "alpinejs",
  "dist",
  "cdn.js"
);

/** The real SDK source, read once. Never edited, never patched. */
const CAPTURE_JS_SOURCE = fs.readFileSync(CAPTURE_JS_PATH, "utf8");

/**
 * The SDK is injected verbatim into an inline `<script>` in the harness document
 * (see `createHarness`), so its text must not contain anything that takes the
 * HTML parser out of script context: `</script` closes the element early and
 * `<!--` opens an HTML comment inside it. Neither appears in the SDK today, but a
 * re-vendor could introduce either, and the failure would surface as "pca is not
 * defined" in every test rather than as anything pointing at the injection. Fail
 * loudly at load instead.
 */
assert.ok(
  !/<\/script|<!--/i.test(CAPTURE_JS_SOURCE),
  `${CAPTURE_JS_PATH} contains "</script" or "<!--", which the harness cannot ` +
    "inline: it injects the SDK verbatim into an inline <script> element, and " +
    "either sequence ends or comments out script context in the HTML parser. " +
    "Serve the SDK from the stub HTTP server (or a Blob/data URL) instead of " +
    "inlining it."
);

/**
 * Real Alpine, the browser build, as shipped on npm - read lazily, on the first
 * `alpine: true` harness. Reading it at module load would make every test that
 * imports this harness depend on the `alpinejs` package being installed, even
 * `capture-events.test.mjs`, which never uses Alpine.
 */
let alpineJsSource;
function alpineSource() {
  if (alpineJsSource === undefined) {
    alpineJsSource = fs.readFileSync(ALPINE_JS_PATH, "utf8");
  }
  return alpineJsSource;
}

/** Renders console arguments roughly the way a devtools line would read. */
function formatConsoleArgs(args) {
  return args
    .map((arg) => (typeof arg === "string" ? arg : inspect(arg, { depth: 2 })))
    .join(" ");
}

/**
 * A `find` response item. `Type: "Address"` makes `address.select` retrieve it;
 * any other type sends it down the drill-down / `filterSearch` path.
 */
export function findItem(overrides = {}) {
  return {
    Id: "GB|RM|A|52509479",
    Type: "Address",
    Text: "10 Downing Street",
    Description: "London, SW1A 2AA",
    Highlight: "0-2",
    ...overrides,
  };
}

/** A `retrieve` response item, shaped like the real Loqate retrieve payload. */
export function retrieveItem(overrides = {}) {
  return {
    Id: "GB|RM|A|52509479",
    Line1: "10 Downing Street",
    Line2: "Westminster",
    Company: "Loqate Ltd",
    City: "London",
    ProvinceName: "Kent",
    PostalCode: "SW1A 2AA",
    CountryIso2: "GB",
    CountryName: "United Kingdom",
    Label: "10 Downing Street\nWestminster\nLondon\nSW1A 2AA",
    ...overrides,
  };
}

/**
 * A tiny stub of the two Magento controller endpoints the module points the SDK
 * at. Runs on an ephemeral port; the jsdom document is given the same origin so
 * that jsdom's XMLHttpRequest treats the calls as same-origin.
 *
 * `capture.js` is configured with `endpoint.literal: true`, so the request URL is
 * the configured URL verbatim plus `?<query>` - we therefore match on pathname
 * and ignore the query string. `endpoint.unwrapped: true` means the body must be
 * a bare JSON array, not `{"Items": [...]}`.
 */
async function startStubServer({ find, retrieve }) {
  /** Per-path request counters, used by the "no billable request" assertion. */
  const requests = { find: 0, retrieve: 0, other: 0 };
  /** Every request URL seen, for diagnostics. */
  const urls = [];

  const server = http.createServer((req, res) => {
    const url = new URL(req.url, "http://127.0.0.1");
    urls.push(req.url);

    let body;
    if (url.pathname === "/find") {
      requests.find++;
      body = typeof find === "function" ? find(url.searchParams) : find;
    } else if (url.pathname === "/retrieve") {
      requests.retrieve++;
      body = typeof retrieve === "function" ? retrieve(url.searchParams) : retrieve;
    } else {
      requests.other++;
      res.writeHead(404, { "content-type": "text/plain" });
      res.end("not found");
      return;
    }

    const payload = JSON.stringify(body);
    res.writeHead(200, {
      "content-type": "application/json",
      "content-length": Buffer.byteLength(payload),
    });
    res.end(payload);
  });

  await new Promise((resolve) => server.listen(0, "127.0.0.1", resolve));
  const { port } = server.address();

  return {
    requests,
    urls,
    origin: `http://127.0.0.1:${port}`,
    async close() {
      // Drop sockets before closing: `server.close()` stops accepting but waits
      // for every open connection to end, and jsdom's XMLHttpRequest leaves a
      // keep-alive socket behind, so the close callback can stall indefinitely.
      // Multiplied by the ~9 harnesses the suite creates, that is a CI hang.
      server.closeAllConnections();
      await new Promise((resolve) => server.close(resolve));
    },
  };
}

/**
 * `region_id` options in which the region name is in BOTH the option text and
 * `data-title`. This is the shape Magento renders for GB, and it is matched by
 * `pca.setValue` itself: its SELECT loop compares `option.text` and
 * `option.value` (capture.js:3364-3372), finds "Kent", sets `selectedIndex` and
 * returns early - which is exactly why the select never gets an event from
 * `pca.reactTriggerChange` and needs `mapRegionSelectValue` to dispatch one.
 */
export const TEXT_MATCHED_REGION_OPTIONS = `
      <option value="1" data-title="Kent">Kent</option>
      <option value="2" data-title="Surrey">Surrey</option>`;

/**
 * `region_id` options in which the option TEXT is a display label that does not
 * equal the Loqate ProvinceName, and the region code lives in a data attribute.
 * This is the other shape Magento renders in the wild, and it is the one
 * `pca.setValue` cannot match at all - only `mapRegionSelectValue`'s
 * `data-code` / `data-region-code` candidates do (capture.js:7946-7952).
 *
 * Retrieve `ProvinceName: "KEN"` matches option 1 on `data-code`;
 * `ProvinceName: "SRY"` matches option 2 on `data-region-code`. Neither matches
 * on text or on `data-title`, so the data-attribute path really is the matcher.
 */
export const CODE_MATCHED_REGION_OPTIONS = `
      <option value="43" data-title="Kent" data-code="KEN">Kent County</option>
      <option value="44" data-title="Surrey" data-region-code="SRY">Surrey County</option>`;

/**
 * The `default` field mapping from capture.js:7787-7817 expects exactly these
 * `name` attributes. The anchor is `input[name="street[0]"]` and the lookup
 * context is `anchorElement.closest("form")`, hence the `<form>` wrapper.
 *
 * `region` (text input) and `region_id` (select) are BOTH mapped to
 * ProvinceName, which is why both are present.
 */
function addressFormHtml({
  alpine = false,
  regionOptions = TEXT_MATCHED_REGION_OPTIONS,
} = {}) {
  const x = (name) => (alpine ? ` x-model="${name}"` : "");

  return `
    <form id="address-form"${
      alpine
        ? ` x-data="{ street0: '', street1: '', company: '', city: '', region: '', regionId: '', postcode: '' }"`
        : ""
    }>
      <input type="text" name="street[0]" id="street_1"${x("street0")}>
      <input type="text" name="street[1]" id="street_2"${x("street1")}>
      <input type="text" name="company" id="company"${x("company")}>
      <input type="text" name="city" id="city"${x("city")}>
      <input type="text" name="region" id="region"${x("region")}>
      <select name="region_id" id="region_id"${x("regionId")}>
        <option value="">Please select a region, state or province.</option>${regionOptions}
      </select>
      <input type="text" name="postcode" id="postcode"${x("postcode")}>
      <select name="country_id" id="country_id">
        <option value="GB">United Kingdom</option>
        <option value="US">United States</option>
      </select>
    </form>`;
}

/**
 * Wraps `pca.Address` so the tests can reach the control instance the module's
 * own bootstrap creates, and can count how many times it is constructed.
 *
 * This is safe to do from a separate <script> because capture.js resolves
 * `pca.Address` as a property lookup at call time (`new pca.Address(...)`,
 * capture.js:8097), and `loqateInit` is deferred to DOMContentLoaded - i.e. it
 * runs after this script has installed the wrapper.
 */
const ADDRESS_SPY_SOURCE = `
  window.__pcaAddressCalls = 0;
  window.__pcaControls = [];
  (function () {
    var real = window.pca.Address;
    window.pca.Address = function (fields, options) {
      window.__pcaAddressCalls++;
      var inst = new real(fields, options);
      window.__pcaControls.push(inst);
      return inst;
    };
  })();
`;

/** Resolves after one macrotask, letting queued timers/microtasks drain. */
function tick(window, ms = 0) {
  return new Promise((resolve) => window.setTimeout(resolve, ms));
}

/** Polls `predicate` until it is truthy, or throws once `timeout` elapses. */
async function waitFor(window, predicate, { timeout = 2000, label = "condition" } = {}) {
  const deadline = Date.now() + timeout;
  for (;;) {
    let value;
    try {
      value = predicate();
    } catch (error) {
      value = false;
      if (Date.now() > deadline) throw error;
    }
    if (value) return value;
    if (Date.now() > deadline) {
      throw new Error(`Timed out after ${timeout}ms waiting for ${label}`);
    }
    await tick(window, 5);
  }
}

/**
 * Boots a jsdom document containing the address form and the real capture.js.
 *
 * Script order in the document matters and is deliberate:
 *   1. (optional) Alpine, so it owns the form before the SDK reshuffles it;
 *   2. capture.js - the code under test, injected verbatim;
 *   3. the `pca.Address` spy, which must be installed before DOMContentLoaded.
 * All three run while `document.readyState === "loading"`, so capture.js's
 * `loqateInit` is deferred to DOMContentLoaded and sees the spy.
 */
export async function createHarness({
  find = [findItem()],
  retrieve = [retrieveItem()],
  alpine = false,
  regionOptions = TEXT_MATCHED_REGION_OPTIONS,
} = {}) {
  const server = await startStubServer({ find, retrieve });

  // In-page diagnostics. `jsdomError` alone is not enough: the SDK reports a
  // misconfigured harness through `console.error` - capture.js:7999,
  // 'Element with ID "loqate-urls" not found', after which it returns and never
  // initialises - and swallowing that turns a configuration mistake into a
  // mysterious assertion failure several lines later. `console.warn` is captured
  // for the same reason. Both are exposed on the harness so a test can assert on
  // them (see the non-vacuity guard in capture-events.test.mjs).
  const consoleErrors = [];
  const consoleWarnings = [];
  const virtualConsole = new VirtualConsole();
  virtualConsole.on("jsdomError", (error) => consoleErrors.push(error));
  virtualConsole.on("error", (...args) =>
    consoleErrors.push(formatConsoleArgs(args))
  );
  virtualConsole.on("warn", (...args) =>
    consoleWarnings.push(formatConsoleArgs(args))
  );

  const html = `<!DOCTYPE html>
<html>
  <head><meta charset="utf-8"><title>Loqate capture harness</title></head>
  <body>
    <!-- capture.js refuses to initialise without this element. -->
    <div id="loqate-urls" data-find-url="/find" data-retrieve-url="/retrieve"></div>
    ${addressFormHtml({ alpine, regionOptions })}
    ${alpine ? `<script>${alpineSource()}<\/script>` : ""}
    <script>${CAPTURE_JS_SOURCE}<\/script>
    <script>${ADDRESS_SPY_SOURCE}<\/script>
  </body>
</html>`;

  /*
   * CI stays hermetic, and it is the ABSENCE of a `resources` option that keeps
   * it that way - this is load-bearing, so do not add `resources: "usable"`:
   *
   *   - jsdom's default resource loader fetches nothing external, so the SDK's
   *     four `preloadImage` calls (capture.js:7771-7778), which point at
   *     `//services.postcodeanywhere.co.uk/images/...`, never leave the process:
   *     `new Image(); img.src = url` is inert without a usable resource loader.
   *   - the only other outbound call the SDK can make on its own is IP
   *     geolocation, and `options.setCountryByIP` defaults to `false`
   *     (documented capture.js:6150, defaulted capture.js:6224-6227) and is not
   *     enabled here, so `address.setCountryByIP` (capture.js:6700-6701) is
   *     never reached.
   *
   * Every request the suite makes therefore goes to the local stub server, and
   * `server.requests.other` would catch it if one did not.
   */
  const dom = new JSDOM(html, {
    url: `${server.origin}/checkout/`,
    runScripts: "dangerously",
    pretendToBeVisual: true,
    virtualConsole,
  });

  const { window } = dom;

  // Wait for the document to finish loading, so DOMContentLoaded (and therefore
  // loqateInit and pca.ready) has run.
  if (window.document.readyState !== "complete") {
    await new Promise((resolve) => window.addEventListener("load", resolve));
  }
  await tick(window);

  const field = (name) => window.document.querySelector(`[name="${name}"]`);

  const harness = {
    dom,
    window,
    document: window.document,
    server,
    consoleErrors,
    consoleWarnings,
    field,
    /** The control instance created by the module's own bootstrap. */
    get control() {
      return window.__pcaControls[0];
    },
    get addressConstructions() {
      return window.__pcaAddressCalls;
    },
    tick: (ms) => tick(window, ms),
    waitFor: (predicate, options) => waitFor(window, predicate, options),

    /**
     * Attaches capturing `input`/`change` listeners to the named fields and
     * returns a recorder. Listeners are attached before any interaction, so the
     * recorded sequence is exactly what the SDK dispatched.
     */
    recordEvents(names) {
      const log = new Map(names.map((name) => [name, []]));

      for (const name of names) {
        const element = field(name);
        if (!element) throw new Error(`No field named ${name} in the form`);
        for (const type of ["input", "change"]) {
          element.addEventListener(
            type,
            (event) => {
              log.get(name).push({ type: event.type, value: event.target.value });
            },
            true // capture phase
          );
        }
      }

      return {
        /** Event types recorded on a field, in dispatch order. */
        types: (name) => log.get(name).map((entry) => entry.type),
        entries: (name) => log.get(name).slice(),
        reset: () => {
          for (const name of names) log.set(name, []);
        },
      };
    },

    /**
     * Drives the SDK's own search UI: puts text in the search field and fires
     * the keyup that capture.js binds (capture.js:1762-1788 - it binds
     * keyup/keydown/keypress/paste/click/dblclick/change and nothing else).
     * Returns once the SDK has rendered suggestion items.
     */
    async search(text, { fieldName = "street[0]" } = {}) {
      const element = field(fieldName);
      element.focus();
      // keydown first: that is the handler which tells the SDK's autocomplete
      // which field is being typed into (capture.js:2138-2143 -> autocomplete.focus).
      element.dispatchEvent(
        new window.KeyboardEvent("keydown", { bubbles: true, key: "g" })
      );
      element.value = text;
      element.dispatchEvent(
        new window.KeyboardEvent("keyup", { bubbles: true, key: "g" })
      );
      await this.waitFor(
        () => this.suggestionElements().length > 0,
        { label: "the SDK to render suggestion items" }
      );
    },

    /** The suggestion elements the SDK rendered in its own autocomplete list. */
    suggestionElements() {
      const list = this.control?.autocomplete?.list?.element;
      return list ? Array.from(list.querySelectorAll(".pcaitem")) : [];
    },

    /** Clicks a rendered suggestion, exactly as a shopper would. */
    clickSuggestion(index = 0) {
      const items = this.suggestionElements();
      if (!items[index]) throw new Error(`No suggestion at index ${index}`);
      items[index].dispatchEvent(
        new window.MouseEvent("click", { bubbles: true, cancelable: true })
      );
    },

    /** Search, click the first suggestion, and wait for the populate to land. */
    async searchAndPick(text = "10 Downing") {
      await this.search(text);
      this.clickSuggestion(0);
      await this.waitFor(() => field("city").value !== "", {
        label: "the retrieved address to be written to the form",
      });
      // Let the region-select retry timer and Alpine's queue drain.
      await this.tick(120);
    },

    async close() {
      window.close();
      await server.close();
    },
  };

  return harness;
}
