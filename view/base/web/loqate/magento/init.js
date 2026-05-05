/**
 * @fileoverview Loqate Magento – PCA Address widget initialisation.
 *
 * Entry point for the Loqate address capture integration.  Reads the API
 * endpoint URLs injected by `config.phtml` into the `#loqate-urls` element,
 * then attaches a {@link pca.Address} capture widget to every address form
 * found on the page.
 *
 * **Design decisions vs. the original `capture.js`:**
 * - Uses `MutationObserver` (50 ms debounce) instead of `setInterval` to react
 *   to dynamically rendered checkout steps, eliminating the CPU/GC lag that
 *   affected Hyvä Checkout.
 * - No RequireJS or jQuery required: plain IIFE, compatible with Luma
 *   (Knockout + RequireJS) and Hyvä (Alpine.js) checkout.
 * - Multiple independent form contexts tracked in `pcaInstances` map, keyed by
 *   `{mappingKey}_{fieldId}`, so shipping and billing widgets coexist without
 *   interfering with each other.
 *
 * **Dependencies (must be loaded before this file):**
 * - `loqate/pca/core.js` … `loqate/pca/address.js`   (full PCA SDK)
 * - `loqate/magento/field-mappings.js`  (`window.Loqate.fieldMappings`)
 * - `loqate/magento/region-mapper.js`   (`window.Loqate.getElementByName`,
 *                                        `window.Loqate.mapRegionSelectValueWithRetry`)
 *
 * @module loqate/magento/init
 */
(function () {
  var pca = window.pca;


  let loqateFindUrl;
  let loqateRetrieveUrl;

  /**
   * Bootstraps the Loqate address capture integration.
   *
   * Reads `data-find-url` and `data-retrieve-url` from the `#loqate-urls`
   * element rendered by `config.phtml`, then calls {@link scanForFields}
   * immediately and sets up a `MutationObserver` to re-scan whenever the DOM
   * changes (e.g. when a checkout step is rendered by Knockout or Alpine.js).
   *
   * Called automatically on `DOMContentLoaded`, or synchronously if the DOM is
   * already interactive when this script is executed.
   */
  function loqateInit() {
    const loqateElement = document.getElementById("loqate-urls");
    const pcaInstances = {};

    if (loqateElement) {
      loqateFindUrl = loqateElement.getAttribute("data-find-url");
      loqateRetrieveUrl = loqateElement.getAttribute("data-retrieve-url");
    } else {
      console.error('Element with ID "loqate-urls" not found');
      return;
    }

    /**
     * Iterates over `window.Loqate.fieldMappings` and creates a `pca.Address`
     * widget for each address form found in the current DOM.
     *
     * For the `"default"` mapping all `input[name="street[0]"]` elements are
     * processed independently, which handles pages with separate shipping and
     * billing sections.  For named mappings (`billingFields`, `shippingFields`)
     * the first matching anchor element is used.
     *
     * Already-registered widgets whose anchor element is still in the DOM are
     * skipped to prevent duplicate initialisation.
     * @private
     */
    function scanForFields() {
      for (const [key, value] of Object.entries(window.Loqate.fieldMappings || {})) {
        let anchorElement;
        
        if (key === "default") {
          // For default mapping, find ALL street[0] fields and initialize each separately
          const allStreetFields = document.querySelectorAll('input[name="street[0]"]');
          
          allStreetFields.forEach((streetField) => {
            // Check if this field is already registered
            const fieldId = streetField.id || streetField.name + '_' + Array.from(allStreetFields).indexOf(streetField);
            const instanceKey = key + '_' + fieldId;
            
            if (pcaInstances[instanceKey]?.anchorElement && 
                document.body.contains(pcaInstances[instanceKey].anchorElement)) {
              return; // Skip if already registered
            }

            anchorElement = streetField;
            
            // Find the best context for this specific field
            const context =
              anchorElement.closest("form") ||
              anchorElement.closest(".fieldset") ||
              anchorElement.closest(".admin__fieldset") ||
              anchorElement.closest(".checkout-billing-address") ||
              anchorElement.closest(".billing-address-form") ||
              anchorElement.closest(".checkout-shipping-address") ||
              anchorElement.closest(".shipping-address-form") ||
              anchorElement.closest('[data-bind]')?.parentElement ||
              document;
            
            // Initialize for this specific field
            initializeLoqateForField(key, value, anchorElement, context, instanceKey);
          });
          
          // Skip the rest of the loop for default mapping since we handled it above
          continue;
        } else {
          anchorElement = document.querySelector(
            `input[name="${value[0].element}"]`
          );
        }

        // if line1 doesn't exist or is already registered, skip
        if (
          anchorElement === null ||
          document.body.contains(pcaInstances[key]?.anchorElement)
        ) {
          continue;
        }

        // Determine context for non-default mappings
        const context =
          anchorElement.closest(".admin__fieldset") ||
          anchorElement.closest(".fieldset") ||
          anchorElement.closest(".checkout-billing-address") ||
          anchorElement.closest(".billing-address-form") ||
          anchorElement.closest("form") ||
          document;
        
        initializeLoqateForField(key, value, anchorElement, context, key);
      }
    }

    scanForFields();
    if (typeof MutationObserver !== 'undefined') {
      var scanDebounceTimer = null;
      var loqateObserver = new MutationObserver(function () {
        clearTimeout(scanDebounceTimer);
        scanDebounceTimer = setTimeout(scanForFields, 50);
      });
      loqateObserver.observe(document.body, { childList: true, subtree: true });
    }

    /**
     * Creates a single `pca.Address` widget bound to the given field mapping.
     *
     * Resolves each binding’s `element` string to a live DOM node via
     * {@link window.Loqate.getElementByName} scoped to `context`.  If the
     * primary `Line1` field cannot be resolved the widget is not created.
     *
     * Any pre-existing widget for the same `instanceKey` is destroyed first to
     * avoid orphaned event listeners.
     *
     * Province `<select>` fields receive a `populate` listener that calls
     * {@link window.Loqate.mapRegionSelectValueWithRetry} to handle Magento’s
     * asynchronously rendered region dropdowns.
     *
     * @private
     * @param {string} mappingKey
     *   The key from `window.Loqate.fieldMappings` (e.g. `"default"`).
     * @param {Array<{element:string, field:string, mode:number}>} fieldMapping
     *   The raw field binding array for this context.
     * @param {HTMLElement} anchorElement
     *   The `street[0]` input used to identify this instance.
     * @param {Element|Document} context
     *   The DOM subtree to search for sibling fields.
     * @param {string} instanceKey
     *   Unique key for tracking the widget in the `pcaInstances` map.
     */
    function initializeLoqateForField(mappingKey, fieldMapping, anchorElement, context, instanceKey) {
      const addressFieldsWithElements = fieldMapping.map((field) => {
        return {
          ...field,
          element: window.Loqate.getElementByName(field.element, context),
        };
      });

      // Check if all required fields are found
      if (!addressFieldsWithElements[0]?.element) {
        return; // Skip if the primary field (Line1) wasn't found
      }

      // clean-up old instances
      if (pcaInstances[instanceKey]?.control) {
        pcaInstances[instanceKey].control.destroy();
      }

      const control = new pca.Address(addressFieldsWithElements, {
        key: " ",
        simulateReactEvents: true,
        endpoint: {
          literal: true,
          find: loqateFindUrl,
          retrieve: loqateRetrieveUrl,
          unwrapped: true,
        },
      });

      const regionSelectFields = addressFieldsWithElements.filter(
        (field) =>
          field.field === "ProvinceName" &&
          field.element &&
          field.element.tagName === "SELECT"
      );

      if (regionSelectFields.length) {
        control.listen("populate", function (details) {
          regionSelectFields.forEach((regionField) => {
            window.Loqate.mapRegionSelectValueWithRetry(regionField.element, details);
          });
        });
      }

      pcaInstances[instanceKey] = { anchorElement, control };
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', loqateInit);
  } else {
    loqateInit();
  }
})();
