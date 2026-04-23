/**
 * @fileoverview Loqate Magento – province/region `<select>` synchronisation.
 *
 * After Loqate populates address fields the province value from the API
 * (e.g. `"Worcestershire"`, `"WR"`) must be written into Magento’s dynamically
 * rendered region `<select>` element.  This module provides:
 *
 * 1. `getElementByName(name, context)` — scoped DOM lookup by `name` attribute.
 * 2. `normalizeRegionValue(value)` — lower-cases and trims for comparison.
 * 3. `dispatchChange(element)` — fires a native `change` event plus a jQuery
 *    Validate re-validation trigger when jQuery is present.
 * 4. `mapRegionSelectValue(selectElement, details)` — walks `<option>` values
 *    matching against `ProvinceName`, `Province`, and `ProvinceCode`.
 * 5. `mapRegionSelectValueWithRetry(selectElement, details)` — wraps the above
 *    with exponential-backoff retry (75 ms × 2ⁿ, up to 4 attempts) for region
 *    dropdowns rendered asynchronously by KnockoutJS or Alpine.js.
 *
 * Exposes on the shared `window.Loqate` namespace:
 * - `window.Loqate.getElementByName`
 * - `window.Loqate.mapRegionSelectValueWithRetry`
 *
 * Depends on: nothing (vanilla DOM only).
 *
 * @module loqate/magento/region-mapper
 */
(function () {

  /**
   * Finds the first element with the given `name` attribute within a DOM subtree.
   * @param {string} name - The `name` attribute value to query for.
   * @param {Element|Document} [context=document] - The root element to scope the search.
   * @returns {Element|null} The first matching element, or `null` if not found.
   */
  function getElementByName(name, context) {
    context = context || document;
    return context.querySelector(`[name="${name}"]`);
  }

  /**
   * Normalises a region string for case-insensitive, whitespace-insensitive
   * comparison by trimming and lower-casing.
   * @private
   * @param {*} value - The value to normalise (any type; falsy values yield `""`).
   * @returns {string} Lower-cased, trimmed string.
   */
  function normalizeRegionValue(value) {
    return (value || "").toString().trim().toLowerCase();
  }

  /**
   * Dispatches a native bubbling `change` event on an element.  When jQuery is
   * present on the page, also calls `$el.valid()` to trigger jQuery Validate
   * re-validation so the checkout form does not stay in an error state.
   * @private
   * @param {HTMLElement} element - The element to dispatch the event on.
   */
  function dispatchChange(element) {
    const event = new Event("change", { bubbles: true, cancelable: true });
    element.dispatchEvent(event);

    if (window.jQuery && typeof window.jQuery === "function") {
      const $el = window.jQuery(element);
      if ($el.valid) {
        $el.valid();
      }
    }
  }

  /**
   * Attempts to select the `<option>` in a region `<select>` that matches the
   * province data returned by the Loqate API.  Tries candidates in priority order:
   * `details.ProvinceName`, `details.Province`, `details.ProvinceCode`.  For each
   * candidate, compares (after normalisation) against the option’s `text`,
   * `data-title`, `data-code`, `data-region-code`, and `value` attributes.
   * @private
   * @param {HTMLSelectElement} selectElement - The `<select>` to update.
   * @param {{ ProvinceName?: string, Province?: string, ProvinceCode?: string }} details
   *   Address detail object from the Loqate retrieve response.
   * @returns {boolean} `true` if a matching option was found and selected.
   */
  function mapRegionSelectValue(selectElement, details) {
    if (!selectElement || selectElement.tagName !== "SELECT" || !details) {
      return false;
    }

    const candidates = [];
    if (details.ProvinceName) candidates.push(details.ProvinceName);
    if (details.Province) candidates.push(details.Province);
    if (details.ProvinceCode) candidates.push(details.ProvinceCode);

    if (!candidates.length) {
      return false;
    }

    for (let i = 0; i < candidates.length; i++) {
      const candidate = normalizeRegionValue(candidates[i]);
      if (!candidate) {
        continue;
      }

      for (let optionIndex = 0; optionIndex < selectElement.options.length; optionIndex++) {
        const option = selectElement.options[optionIndex];
        const optionValues = [
          option.text,
          option.getAttribute("data-title"),
          option.getAttribute("data-code"),
          option.getAttribute("data-region-code"),
          option.value,
        ];

        for (let valueIndex = 0; valueIndex < optionValues.length; valueIndex++) {
          const optionValue = normalizeRegionValue(optionValues[valueIndex]);
          if (optionValue && optionValue === candidate) {
            selectElement.value = option.value;
            selectElement.selectedIndex = optionIndex;
            selectElement.setAttribute("value", option.value);
            dispatchChange(selectElement);
            return true;
          }
        }
      }
    }

    return false;
  }

  /**
   * Wraps {@link mapRegionSelectValue} with exponential-backoff retry for region
   * `<select>` elements that are rendered asynchronously by KnockoutJS or Alpine.js
   * after the address is populated.  On each failure the next attempt is scheduled
   * after `75 ms × 2^attempt` (75 ms, 150 ms, 300 ms, 600 ms).
   *
   * Exposed as `window.Loqate.mapRegionSelectValueWithRetry` for use by
   * {@link module:loqate/magento/init}.
   *
   * @param {HTMLSelectElement} selectElement - The `<select>` to update.
   * @param {{ ProvinceName?: string, Province?: string, ProvinceCode?: string }} details
   *   Address detail object from the Loqate retrieve response.
   * @param {number} [attempt=0]    Current retry count (managed internally).
   * @param {number} [maxAttempts=4] Maximum number of retries before giving up.
   */
  function mapRegionSelectValueWithRetry(
    selectElement,
    details,
    attempt = 0,
    maxAttempts = 4
  ) {
    if (mapRegionSelectValue(selectElement, details)) {
      return;
    }

    if (attempt >= maxAttempts) {
      return;
    }

    const delay = 75 * Math.pow(2, attempt);
    window.setTimeout(function () {
      mapRegionSelectValueWithRetry(selectElement, details, attempt + 1, maxAttempts);
    }, delay);
  }


  window.Loqate = window.Loqate || {};
  window.Loqate.getElementByName = getElementByName;
  window.Loqate.mapRegionSelectValueWithRetry = mapRegionSelectValueWithRetry;
})();
