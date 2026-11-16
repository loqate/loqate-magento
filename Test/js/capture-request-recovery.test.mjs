/**
 * LOQ-20320 - the capture spinner hung forever after a failed or slow search.
 *
 * A blocking search (`$block`, which `address.search` sends) parks every search
 * typed while it is pending in `pca.requestQueue`. Before the fix, a non-200 or
 * timed-out request never released that block, so the parked search was never
 * sent and the spinner never stopped.
 *
 * These tests load the real capture.js through the harness and swap only
 * `pca.XMLHttpRequest` (which the SDK resolves at call time) for a fake the test
 * drives by hand, so every response, error and timeout is deterministic.
 */

import { test, describe, beforeEach, afterEach } from "node:test";
import assert from "node:assert/strict";

import { createHarness } from "./support/harness.mjs";

/** Records every XHR the SDK opens; the test settles each one explicitly. */
function installFakeXhr(pca) {
  const sent = [];

  class FakeXMLHttpRequest {
    readyState = 0;
    status = 0;
    statusText = "";
    responseText = "";
    timeout = 0;
    open(method, url) {
      Object.assign(this, { method, url, readyState: 1 });
    }
    setRequestHeader() {}
    send(body) {
      this.body = body;
      sent.push(this);
    }
    respond(status, body = "", statusText = "") {
      Object.assign(this, { readyState: 4, status, statusText, responseText: body });
      this.onreadystatechange?.();
    }
    /** Browsers report a timeout as readyState 4 with status 0, then fire ontimeout. */
    expire() {
      Object.assign(this, { readyState: 4, status: 0 });
      this.onreadystatechange?.();
      this.ontimeout?.();
    }
  }

  pca.XMLHttpRequest = FakeXMLHttpRequest;
  return sent;
}

/** Issues a request shaped like `address.search`'s find call. */
function search(pca, text, { post = false, onSuccess, onError } = {}) {
  pca.fetch(
    "/find",
    { Text: text, $block: true, $unwrapped: true, $post: post },
    onSuccess,
    onError,
    { endpoint: { literal: true } }
  );
}

/** The `Text` parameter an XHR carried, whether it was a GET or a POST. */
function textOf(xhr) {
  const query = xhr.method === "POST" ? xhr.body : xhr.url.split("?")[1];
  return new URLSearchParams(query).get("Text");
}

describe("LOQ-20320 request recovery after a failed search", () => {
  let harness;
  let pca;
  let sent;

  beforeEach(async () => {
    harness = await createHarness();
    pca = harness.window.pca;
    sent = installFakeXhr(pca);
  });

  afterEach(async () => {
    await harness.close();
  });

  for (const post of [false, true]) {
    const method = post ? "POST" : "GET";

    test(`${method}: a blocking search answered with HTTP 504 reports the error and sends the search queued behind it`, () => {
      // Arrange
      const errors = [];
      search(pca, "10 Dow", { post, onError: (message) => errors.push(message) });
      search(pca, "10 Downing", { post });
      assert.equal(sent.length, 1, "the second search is parked behind the blocking one");

      // Act
      sent[0].respond(504, "", "Gateway Timeout");

      // Assert
      assert.deepEqual(errors, ["Webservice request error: Gateway Timeout"]);
      assert.equal(sent.length, 2, "the queued search was sent after the failure");
      assert.equal(sent[1].method, method);
      assert.equal(textOf(sent[1]), "10 Downing");
      assert.equal(pca.waitingRequest, true, "the queued search is now the one in flight");
    });

    test(`${method}: a 504 with no status text (HTTP/2) reports the status code and releases the block`, () => {
      // Arrange
      const errors = [];
      search(pca, "10 Dow", { post, onError: (message) => errors.push(message) });

      // Act
      sent[0].respond(504);

      // Assert
      assert.deepEqual(errors, ["Webservice request error: 504"]);
      assert.equal(pca.waitingRequest, false);
      assert.equal(pca.blockRequests, false);
    });

    test(`${method}: the XHR timeout is set to pca.timeout, 10000 ms by default`, () => {
      // Act
      search(pca, "10 Dow", { post });

      // Assert
      assert.equal(pca.timeout, 10000);
      assert.equal(sent[0].timeout, 10000);
    });
  }

  test("a blocking search that times out reports the timeout once and sends the queued search", () => {
    // Arrange
    const errors = [];
    search(pca, "10 Dow", { onError: (message) => errors.push(message) });
    search(pca, "10 Downing");

    // Act
    sent[0].expire();

    // Assert: status 0 is left to ontimeout, so the error is not reported twice.
    assert.deepEqual(errors, ["Webservice request timed out."]);
    assert.equal(sent.length, 2);
    assert.equal(textOf(sent[1]), "10 Downing");
  });

  test("a network error (status 0, onerror) reports the failure once and sends the queued search", () => {
    // Arrange
    const errors = [];
    search(pca, "10 Dow", { onError: (message) => errors.push(message) });
    search(pca, "10 Downing");

    // Act
    Object.assign(sent[0], { readyState: 4, status: 0 });
    sent[0].onreadystatechange();
    sent[0].onerror({ currentTarget: sent[0] });

    // Assert
    assert.deepEqual(errors, ["Webservice request failed."]);
    assert.equal(sent.length, 2);
  });

  test("an HTTP 200 still calls success exactly once and never the error callback", () => {
    // Arrange
    const successes = [];
    const errors = [];
    search(pca, "10 Dow", {
      onSuccess: (items) => successes.push(items),
      onError: (message) => errors.push(message),
    });

    // Act
    sent[0].respond(200, JSON.stringify([{ Id: "1", Text: "10 Downing Street" }]));

    // Assert
    // JSON round-trip: the items were parsed in the jsdom realm, with its own prototypes.
    assert.deepEqual(JSON.parse(JSON.stringify(successes)), [[{ Id: "1", Text: "10 Downing Street" }]]);
    assert.deepEqual(errors, []);
    assert.equal(pca.waitingRequest, false);
    assert.equal(pca.blockRequests, false);
  });

  test("the error callback runs before the queued search is sent, so clearing the block cannot unblock it", () => {
    // Arrange: the callback does what address.error does on every failure.
    search(pca, "10 Dow", { onError: () => pca.clearBlockingRequests() });
    search(pca, "10 Downing");

    // Act
    sent[0].respond(504, "", "Gateway Timeout");

    // Assert: the queued search is in flight and still blocking later searches.
    assert.equal(sent.length, 2);
    assert.equal(textOf(sent[1]), "10 Downing");
    assert.equal(pca.waitingRequest, true);
    assert.equal(pca.blockRequests, true);
  });

  test("with an empty queue, a failed search is followed by a fresh search being sent", () => {
    // Arrange
    search(pca, "10 Dow");
    sent[0].respond(504, "", "Gateway Timeout");
    assert.equal(sent.length, 1, "nothing was queued, so nothing is resent");

    // Act
    search(pca, "10 Downing");

    // Assert
    assert.equal(sent.length, 2, "the new search is not blocked by the failed one");
    assert.equal(textOf(sent[1]), "10 Downing");
  });
});
