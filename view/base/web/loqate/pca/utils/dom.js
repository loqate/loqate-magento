/**
 * @fileoverview PCA SDK – DOM utilities.
 *
 * Provides the full suite of DOM helper functions used across the SDK:
 * - **Element lookup** (`pca.getElement`, `pca.getElementByRegex`,
 *   `pca.matches`, `pca.closestElement`) with fuzzy ID/name/regex matching.
 * - **Value read/write** (`pca.getValue`, `pca.setValue`, `pca.clear`,
 *   `pca.selected`, `pca.checkBox`).
 * - **React event simulation** (`pca.reactTriggerChange`) — dispatches
 *   synthetic events compatible with React’s intercepted onChange system,
 *   ensuring KnockoutJS / Alpine.js bindings update after field population.
 * - **Element creation** (`pca.create`).
 * - **Event wiring** (`pca.listen`, `pca.ignore`, `pca.fire`,
 *   `pca.smash`, `pca.moveCursorToEnd`).
 * - **Geometry** (`pca.getPosition`, `pca.getSize`, `pca.getParentScroll`,
 *   `pca.getTopOffsetParent`, `pca.getStyle`).
 * - **CSS class manipulation** (`pca.addClass`, `pca.removeClass`,
 *   `pca.applyStyleFixes`, `pca.reapplyStyleFixes`).
 * - **Attribute helpers** (`pca.setAttribute`, `pca.setAttributes`,
 *   `pca.isPage`).
 * - **Session ID** (`pca.guid`, `pca.sessionId`).
 *
 * This file **finalises document-ready detection** by calling
 * `pca._initDocumentReady()` at its end, after `pca.listen` and `pca.ignore`
 * are defined (they are required by the DOM-ready listener added in core.js).
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/utils/format}.
 *
 * @module pca/utils/dom
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

    pca.getElement =
      pca.getElement ||
      function (reference, base) {
        if (!reference) return null;

        if (typeof reference.nodeType == "number")
          //Is a HTML DOM Node
          return reference;

        if (typeof reference == "string") {
          base = pca.getElement(base) || document;

          var byId = base.getElementById
            ? base.getElementById(reference)
            : null;
          if (byId) return byId;

          var byName = base.getElementsByName
            ? base.getElementsByName(reference)
            : null;
          if (byName.length) return byName[0];
        }

        //try a regex match if allowed
        return pca.fuzzyMatch ? pca.getElementByRegex(reference, base) : null;
      };

    /** Cross-browser wrapper for `Element.matches()`.  Falls back to a manual
     * `querySelectorAll` scan on browsers that do not implement the native method.
     * @memberof pca
     * @param {HTMLElement} element - The element to test.
     * @param {string} selector - A CSS selector string.
     * @returns {boolean} `true` if the element matches the selector.
     */
    pca.matches =
      function (element, selector) {
        if (typeof window.Element.prototype.matches === "function") {
          return element.matches(selector);
        }
        var elements = (
          element.document || element.ownerDocument
        ).querySelectorAll(selector);
        var index = 0;

        while (elements[index] && elements[index] !== element) {
          ++index;
        }

        return Boolean(elements[index]);
      };

    /** Cross-browser wrapper for `Element.closest()`.  Walks the ancestor chain
     * using {@link pca.matches} for environments that do not implement the native
     * method (present in all modern browsers but absent in IE11 without a polyfill).
     * @memberof pca
     * @param {HTMLElement} element - The element to start traversal from.
     * @param {string} selector - A CSS selector to test each ancestor against.
     * @returns {HTMLElement|null} The nearest matching ancestor, or `null`.
     */
    pca.closestElement =
      function (element, selector) {
        if (typeof window.Element.prototype.closest === "function") {
          return element.closest(selector);
        }
        while (element && element.nodeType === 1) {
          if (pca.matches(element, selector)) {
            return element;
          }

          element = element.parentNode;
        }

        return null;
      };

    /** Retrieves a DOM element using RegEx matching on the id.
     * @memberof pca
     * @param {Regex|string} regex - The RegExp to test search element id for.
     * @param {string|HTMLElement} base - The id, name or parent element to search from.
     * @returns {HTMLElement} The first element found or null. */
    pca.getElementByRegex =
      pca.getElementByRegex ||
      function (regex, base) {
        //compile and check regex strings
        if (typeof regex == "string") {
          try {
            regex = new RegExp(regex);
          } catch (e) {
            return null;
          }
        }

        //make sure its a RegExp
        if (regex && typeof regex == "object" && regex.constructor === RegExp) {
          base = pca.getElement(base) || document;

          for (var t = 0; t < pca.fuzzyTags.length; t++) {
            var elements = base.getElementsByTagName(pca.fuzzyTags[t]);

            for (var i = 0; i < elements.length; i++) {
              var elem = elements[i];
              if (elem.id && regex.test(elem.id)) return elem;
            }
          }
        }

        return null;
      };

    /** Get the value of a DOM element.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to get the value of.
     * @returns {string} The value of the element. */
    pca.getValue =
      pca.getValue ||
      function (element) {
        if ((element = pca.getElement(element))) {
          if (element.tagName === "INPUT" || element.tagName === "TEXTAREA") {
            if (element.type === "checkbox") return element.checked;
            else if (element.type === "radio") {
              var group = document.getElementsByName(element.name);
              for (var r = 0; r < group.length; r++) {
                if (group[r].checked) return group[r].value;
              }
            } else return element.value;
          }
          if (element.tagName === "SELECT") {
            if (element.selectedIndex < 0) return "";
            var selectedOption = element.options[element.selectedIndex];
            return selectedOption.value || selectedOption.text || "";
          }
          if (
            element.tagName === "DIV" ||
            element.tagName === "SPAN" ||
            element.tagName === "TD" ||
            element.tagName === "LABEL"
          )
            return element.innerHTML;
        }

        return "";
      };

    /** Simulates a React-compatible synthetic `change` event on a form input.
     * React overrides the native `change` event with its own reconciliation
     * system, so a plain `element.dispatchEvent(new Event('change'))` is silently
     * ignored.  This function temporarily removes React’s internal value property
     * descriptor, dispatches the low-level DOM events React listens for
     * (`focus`, `propertychange`, `input`, `change`, `click`), then restores the
     * descriptor — causing React to reconcile the new value.
     *
     * Handles text/email/tel/search/url inputs, `<select>`, `<textarea>`, file
     * inputs, checkboxes, radio buttons, and range sliders across React 0.14–16
     * and IE9–11.
     *
     * Based on react-trigger-change © 2017 Vitaly Kuznetsov (MIT licence).
     * @memberof pca
     * @param {HTMLElement} node - The form element to trigger the change event on.
     */
    pca.reactTriggerChange =
      function reactTriggerChange(node) {
        //Copyright (c) 2017 Vitaly Kuznetsov
        var supportedInputTypes = {
          color: true,
          date: true,
          datetime: true,
          "datetime-local": true,
          email: true,
          month: true,
          number: true,
          password: true,
          range: true,
          search: true,
          tel: true,
          text: true,
          time: true,
          url: true,
          week: true,
        };
        var nodeName = node.nodeName.toLowerCase();
        var type = node.type;
        var event;
        var descriptor;
        var initialValue;
        var initialChecked;
        var initialCheckedRadio;

        // Do not try to delete non-configurable properties.
        // Value and checked properties on DOM elements are non-configurable in PhantomJS.
        function deletePropertySafe(elem, prop) {
          var desc = Object.getOwnPropertyDescriptor(elem, prop);
          if (desc && desc.configurable) {
            delete elem[prop];
          }
        }

        // In IE10 propertychange is not dispatched on range input if invalid
        // value is set.
        function changeRangeValue(range) {
          var initMin = range.min;
          var initMax = range.max;
          var initStep = range.step;
          var initVal = Number(range.value);

          range.min = initVal;
          range.max = initVal + 1;
          range.step = 1;
          range.value = initVal + 1;
          deletePropertySafe(range, "value");
          range.min = initMin;
          range.max = initMax;
          range.step = initStep;
          range.value = initVal;
        }

        function getCheckedRadio(radio) {
          var name = radio.name;
          var radios;
          var i;
          if (name) {
            radios = document.querySelectorAll(
              'input[type="radio"][name="' + name + '"]'
            );
            for (i = 0; i < radios.length; i += 1) {
              if (radios[i].checked) {
                return radios[i] !== radio ? radios[i] : null;
              }
            }
          }
          return null;
        }

        function preventChecking(e) {
          e.preventDefault();
          if (!initialChecked) {
            e.target.checked = false;
          }
          if (initialCheckedRadio) {
            initialCheckedRadio.checked = true;
          }
        }

        if (
          nodeName === "select" ||
          (nodeName === "input" && type === "file") ||
          (nodeName === "input" && type === "text")
        ) {
          // IE9-IE11, non-IE
          // Dispatch change.
          event = document.createEvent("HTMLEvents");
          event.initEvent("change", true, false);
          node.dispatchEvent(event);
        } else if (
          (nodeName === "input" && supportedInputTypes[type]) ||
          nodeName === "textarea"
        ) {
          // React 16
          // Cache artificial value property descriptor.
          // Property doesn't exist in React <16, descriptor is undefined.
          descriptor = Object.getOwnPropertyDescriptor(node, "value");

          // React 0.14: IE9
          // React 15: IE9-IE11
          // React 16: IE9
          // Dispatch focus.
          event = document.createEvent("UIEvents");
          event.initEvent("focus", false, false);
          node.dispatchEvent(event);

          // React 0.14: IE9
          // React 15: IE9-IE11
          // React 16
          // In IE9-10 imperative change of node value triggers propertychange event.
          // Update inputValueTracking cached value.
          // Remove artificial value property.
          // Restore initial value to trigger event with it.
          if (type === "range") {
            changeRangeValue(node);
          } else {
            initialValue = node.value;
            node.value = initialValue + "#";
            deletePropertySafe(node, "value");
            node.value = initialValue;
          }

          // React 15: IE11
          // For unknown reason React 15 added listener for propertychange with addEventListener.
          // This doesn't work, propertychange events are deprecated in IE11,
          // but allows us to dispatch fake propertychange which is handled by IE11.
          event = document.createEvent("HTMLEvents");
          event.initEvent("propertychange", false, false);
          event.propertyName = "value";
          node.dispatchEvent(event);

          // React 0.14: IE10-IE11, non-IE
          // React 15: non-IE
          // React 16: IE10-IE11, non-IE
          event = document.createEvent("HTMLEvents");
          event.initEvent("input", true, false);
          node.dispatchEvent(event);

          // React 16
          // Restore artificial value property descriptor.
          if (descriptor) {
            Object.defineProperty(node, "value", descriptor);
          }
        } else if (nodeName === "input" && type === "checkbox") {
          // Invert inputValueTracking cached value.
          node.checked = !node.checked;

          // Dispatch click.
          // Click event inverts checked value.
          event = document.createEvent("MouseEvents");
          event.initEvent("click", true, true);
          node.dispatchEvent(event);
        } else if (nodeName === "input" && type === "radio") {
          // Cache initial checked value.
          initialChecked = node.checked;

          // Find and cache initially checked radio in the group.
          initialCheckedRadio = getCheckedRadio(node);

          // React 16
          // Cache property descriptor.
          // Invert inputValueTracking cached value.
          // Remove artificial checked property.
          // Restore initial value, otherwise preventDefault will eventually revert the value.
          descriptor = Object.getOwnPropertyDescriptor(node, "checked");
          node.checked = !initialChecked;
          deletePropertySafe(node, "checked");
          node.checked = initialChecked;

          // Prevent toggling during event capturing phase.
          // Set checked value to false if initialChecked is false,
          // otherwise next listeners will see true.
          // Restore initially checked radio in the group.
          node.addEventListener("click", preventChecking, true);

          // Dispatch click.
          // Click event inverts checked value.
          event = document.createEvent("MouseEvents");
          event.initEvent("click", true, true);
          node.dispatchEvent(event);

          // Remove listener to stop further change prevention.
          node.removeEventListener("click", preventChecking, true);

          // React 16
          // Restore artificial checked property descriptor.
          if (descriptor) {
            Object.defineProperty(node, "checked", descriptor);
          }
        }
      };

    /** Set the value of a DOM element.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to set the value of.
     * @param {*} value - The value to set. */
    pca.setValue =
      pca.setValue ||
      function (element, value, simulateReactTrigger) {
        if ((value || value === "") && (element = pca.getElement(element))) {
          var valueText = value.toString(),
            valueTextMatch = pca.formatTag(valueText);

          if (element.tagName === "INPUT") {
            if (element.type === "checkbox") {
              element.checked =
                (typeof value == "boolean" && value) ||
                valueTextMatch === "TRUE";
            } else if (element.type === "radio") {
              var group = document.getElementsByName(element.name);
              for (var r = 0; r < group.length; r++) {
                if (pca.formatTag(group[r].value) === valueTextMatch) {
                  group[r].checked = true;
                  return;
                }
              }
            } else {
              element.value = pca.tidy(
                valueText.replace(/\\n|\n/gi, ", "),
                ", "
              );
            }
          } else if (element.tagName === "TEXTAREA") {
            element.value = valueText.replace(/\\n|\n/gi, "\n");
          } else if (element.tagName === "SELECT") {
            for (var s = 0; s < element.options.length; s++) {
              if (
                pca.formatTag(element.options[s].value) === valueTextMatch ||
                pca.formatTag(element.options[s].text) === valueTextMatch
              ) {
                element.selectedIndex = s;
                return;
              }
            }
          } else if (
            element.tagName === "DIV" ||
            element.tagName === "SPAN" ||
            element.tagName === "TD" ||
            element.tagName === "LABEL"
          ) {
            element.innerHTML = valueText.replace(/\\n|\n/gi, "<br/>");
          }
          if (
            simulateReactTrigger &&
            ["SELECT", "TEXTAREA", "INPUT"].indexOf(element.tagName) > -1
          ) {
            pca.reactTriggerChange(element);
          }
        }
      };

    /** Returns true if the element is a text input field.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to check.
     * @returns {boolean} True if the element supports text input. */
    pca.inputField =
      pca.inputField ||
      function (element) {
        if ((element = pca.getElement(element)))
          return (
            element.tagName &&
            (element.tagName === "INPUT" || element.tagName === "TEXTAREA") &&
            element.type &&
            (element.type === "text" ||
              element.type === "search" ||
              element.type === "email" ||
              element.type === "textarea" ||
              element.type === "number" ||
              element.type === "tel")
          );

        return false;
      };

    /** Returns true if the element is a select list field.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to check.
     * @returns {boolean} True if the element in a select list field. */
    pca.selectList =
      pca.selectList ||
      function (element) {
        if ((element = pca.getElement(element)))
          return element.tagName && element.tagName === "SELECT";

        return false;
      };

    pca.moveCursorToEnd =
      pca.moveCursorToEnd ||
      function (el) {
        if (typeof el.selectionStart == "number") {
          el.selectionStart = el.selectionEnd = el.value.length;
        } else if (typeof el.createTextRange != "undefined") {
          el.focus();
          var range = el.createTextRange();
          range.collapse(false);
          range.select();
        }
      };

    /** Returns the current selected item of a select list field.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to check.
     * @returns {HTMLOptionElement} The current selected item. */
    pca.getSelectedItem =
      pca.getSelectedItem ||
      function (element) {
        if (
          (element = pca.getElement(element)) &&
          element.tagName === "SELECT" &&
          element.selectedIndex >= 0
        )
          return element.options[element.selectedIndex];

        return null;
      };

    /** Returns true if the element is a checkbox.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to check.
     * @returns {boolean} True if the element in a checkbox. */
    pca.checkBox =
      pca.checkBox ||
      function (element) {
        if ((element = pca.getElement(element)))
          return (
            element.tagName &&
            element.tagName === "INPUT" &&
            element.type &&
            element.type === "checkbox"
          );

        return false;
      };

    /** Shortcut to clear the value of a DOM element.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to clear. */
    pca.clear =
      pca.clear ||
      function (element) {
        pca.setValue(element, "");
        return pca;
      };

    /** Get the position of a DOM element.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to get the position of.
     * @returns {Object} The top and left of the position. */
    pca.getPosition =
      pca.getPosition ||
      function (element) {
        var empty = { left: 0, top: 0 };

        if ((element = pca.getElement(element))) {
          if (!element.tagName) return empty;

          if (typeof element.getBoundingClientRect != "undefined") {
            var bb = element.getBoundingClientRect(),
              fixed = !pca.isPage(pca.getTopOffsetParent(element)),
              pageScroll = pca.getScroll(window),
              parentScroll = pca.getParentScroll(element);
            return {
              left: bb.left + parentScroll.left + (fixed ? 0 : pageScroll.left),
              top: bb.top + parentScroll.top + (fixed ? 0 : pageScroll.top),
            };
          }

          var x = 0,
            y = 0;

          do {
            x += element.offsetLeft;
            y += element.offsetTop;
          } while ((element = element.offsetParent));

          return { left: x, top: y };
        }

        return empty;
      };

    //Is the element the document or window.
    pca.isPage =
      pca.isPage ||
      function (element) {
        return (
          element === window ||
          element === document ||
          element === document.body
        );
      };

    /** Gets the scroll values from an elements top offset parent.
     * @memberof pca
     * @param {HTMLElement} element - The element to get the scroll of.
     * @returns {Object} The top and left of the scroll. */
    pca.getScroll =
      pca.getScroll ||
      function (element) {
        return {
          left:
            parseInt(element.scrollX || element.scrollLeft, 10) ||
            (pca.isPage(element)
              ? parseInt(document.documentElement.scrollLeft) || 0
              : 0),
          top:
            parseInt(element.scrollY || element.scrollTop, 10) ||
            (pca.isPage(element)
              ? parseInt(document.documentElement.scrollTop) || 0
              : 0),
        };
      };

    /** Get the height and width of a DOM element.
     * @memberof pca
     * @param {HTMLElement} element - The element to get the size of.
     * @returns {Object} The height and width of the element. */
    pca.getSize =
      pca.getSize ||
      function (element) {
        return {
          height:
            element.offsetHeight ||
            element.innerHeight ||
            (pca.isPage(element)
              ? document.documentElement.clientHeight ||
                document.body.clientHeight
              : 0),
          width:
            element.offsetWidth ||
            element.innerWidth ||
            (pca.isPage(element)
              ? document.documentElement.clientWidth ||
                document.body.clientWidth
              : 0),
        };
      };

    /** Get the scroll value for all parent elements.
     * @memberof pca
     * @param {HTMLElement|string} element - The child element to begin from.
     * @returns {Object} The top and left of the scroll. */
    pca.getParentScroll =
      pca.getParentScroll ||
      function (element) {
        var empty = { left: 0, top: 0 };

        if ((element = pca.getElement(element))) {
          if (!element.tagName) return empty;
          if (!(element = element.parentNode)) return empty;

          var x = 0,
            y = 0;

          do {
            if (pca.isPage(element)) break;
            x += parseInt(element.scrollLeft) || 0;
            y += parseInt(element.scrollTop) || 0;
          } while ((element = element.parentNode));

          return { left: x, top: y };
        }

        return empty;
      };

    /** Get the element which an element is positioned relative to.
     * @memberof pca
     * @param {HTMLElement} element - The child element to begin from.
     * @returns {HTMLElement} The element controlling the relative position. */
    pca.getTopOffsetParent =
      pca.getTopOffsetParent ||
      function (element) {
        while (element.offsetParent) {
          element = element.offsetParent;

          //fix for Firefox
          if (pca.getStyle(element, "position") === "fixed") break;
        }

        return element;
      };

    /** Gets the current value of a style property of an element.
     * @memberof pca
     * @param {HTMLElement} element - The element to get the style property of.
     * @param {string} property - The name of the style property to query.
     * @returns {string} The value of the style property. */
    pca.getStyle =
      pca.getStyle ||
      function (element, property) {
        return (
          ((window.getComputedStyle
            ? window.getComputedStyle(element)
            : element.currentStyle) || {})[property] || ""
        );
      };

    /** Adds a CSS class to an element.
     * @memberof pca
     * @param {HTMLElement|string} element - The element to add the style class to.
     * @param {string} className - The name of the style class to add. */
    pca.addClass =
      pca.addClass ||
      function (element, className) {
        if ((element = pca.getElement(element))) {
          if (!pca.containsWord(element.className || "", className))
            element.className += (element.className ? " " : "") + className;
        }
      };

    /** Removes a CSS class from an element.
     * @memberof pca
     * @param {HTMLElement|string} element - The element to remove the style class from.
     * @param {string} className - The name of the style class to remove. */
    pca.removeClass =
      pca.removeClass ||
      function (element, className) {
        if ((element = pca.getElement(element)))
          element.className = pca.removeWord(element.className, className);
      };

    /** Sets an attribute of an element.
     * @memberof pca
     * @param {HTMLElement|string} element - The element to set the attribute of.
     * @param {string} attribute - The element attribute to set.
     * @param {Object} attribute - The value to set. */
    pca.setAttribute =
      pca.setAttribute ||
      function (element, attribute, value) {
        if ((element = pca.getElement(element)))
          element.setAttribute(attribute, value);
      };

    /** Sets multiple attributes of an element.
     * @memberof pca
     * @param {HTMLElement|string} element - The element to set the attributes of.
     * @param {Object} attributes - The element attributes and values to set. */
    pca.setAttributes =
      pca.setAttributes ||
      function (element, attributes) {
        if ((element = pca.getElement(element))) {
          for (var i in attributes) element.setAttribute(i, attributes[i]);
        }
      };

    /** Applies fixes to a style sheet.
     * This will add them to the fixes list for pca.reapplyStyleFixes.
     * @memberof pca
     * @param {string} selectorText - The full CSS selector text for the rule as it appears in the style sheet.
     * @param {Object} fixes - An object with JavaScript style property name and value. */
    pca.applyStyleFixes =
      pca.applyStyleFixes ||
      function (selectorText, fixes) {
        for (var s = 0; s < document.styleSheets.length; s++) {
          var sheet = document.styleSheets[s],
            rules = [];

          try {
            rules = sheet.rules || sheet.cssRules || []; //possible denial of access if script and css are hosted separately
          } catch (e) {}

          for (var r = 0; r < rules.length; r++) {
            var rule = rules[r];

            if (rule.selectorText.toLowerCase() === selectorText) {
              for (var f in fixes) rule.style[f] = fixes[f];
            }
          }
        }

        pca.styleFixes.push({ selectorText: selectorText, fixes: fixes });
      };

    /** Reapplies all fixes to style sheets added by pca.applyStyleFixes.
     * @memberof pca */
    pca.reapplyStyleFixes =
      pca.reapplyStyleFixes ||
      function () {
        var fixesList = pca.styleFixes;

        pca.styleFixes = [];

        for (var i = 0; i < fixesList.length; i++)
          pca.applyStyleFixes(fixesList[i].selectorText, fixesList[i].fixes);
      };

    /** Creates a style sheet from cssText.
     * @memberof pca
     * @param {string} cssText - The CSS text for the body of the style sheet. */
    pca.createStyleSheet =
      pca.createStyleSheet ||
      function (cssText) {
        if (document.createStyleSheet)
          document.createStyleSheet().cssText = cssText;
        else
          document.head.appendChild(
            pca.create("style", { type: "text/css", innerHTML: cssText })
          );
      };

    /** Simple short function to create an element.
     * @memberof pca
     * @param {string} tag - The HTML tag for the element.
     * @param {Object} properties - The properties to set in JavaScript form.
     * @param {string} cssText - Any CSS to add the style property.
     * @returns {HTMLElement} The created element. */
    pca.create =
      pca.create ||
      function (tag, properties, cssText) {
        var elem = document.createElement(tag);
        for (var i in properties || {}) elem[i] = properties[i];
        if (cssText) elem.style.cssText = cssText;
        return elem;
      };

    /** Adds an element to the pca container on the page.
     * If the container does not exist it is created.
     * @memberof pca
     * @param {HTMLElement} element - The element to add to the container. */
    pca.append =
      pca.append ||
      function (element) {
        if (!pca.container) {
          pca.container = pca.create("div", { className: "pca" });
          document.body.appendChild(pca.container);
          pca.liveA11y = pca.create("div", {
            className: "pca_live_a11y pca-visually-hidden",
          });
          pca.liveA11y.setAttribute("aria-live", "polite");
          pca.liveA11y.setAttribute("aria-atomic", "true");
          pca.liveA11y.setAttribute("role", "status");
          pca.container.appendChild(pca.liveA11y);
        }

        pca.container.appendChild(element);
      };

    pca.read = function (text) {
      pca.liveA11y.innerText = "";
      setTimeout(function () {
        pca.liveA11y.innerText = text;
      }, 1000);
    };

    /** Removes an element from the container on the page.
     * @memberof pca
     * @param {HTMLElement} element - The element to remove from the container. */
    pca.remove =
      pca.remove ||
      function (element) {
        if (
          element &&
          element.parentNode &&
          element.parentNode === pca.container
        )
          pca.container.removeChild(element);
      };

    /** Listens to an event with standard DOM event handling.
     * @memberof pca
     * @param {HTMLElement} target - The element to listen to.
     * @param {string} event - The name of the event to listen for, e.g. "click".
     * @param {pca.Eventable~eventHandler} action - The callback for this event.
     * @param {boolean} capture - Use event capturing. */
    pca.listen =
      pca.listen ||
      function (target, event, action, capture) {
        target.addEventListener(event, action, capture);
      };

    /** Creates and fires a standard DOM event.
     * @memberof pca
     * @param {HTMLElement} target - The element to trigger the event for.
     * @param {string} event - The name of the event, e.g. "click".
     * @returns {boolean} False is the event was stopped by any of its handlers. */
    pca.fire =
      pca.fire ||
      function (target, event) {
        if (document.createEvent) {
          var e = document.createEvent("HTMLEvents");
          e.initEvent(event, true, true);
          return !target.dispatchEvent(e);
        } else
          return target.fireEvent("on" + event, document.createEventObject());
      };

    /** Removes listeners for an event with standard DOM event handling.
     * @memberof pca
     * @param {HTMLElement} target - The element.
     * @param {string} event - The name of the event, e.g. "click".
     * @param {pca.Eventable~eventHandler} action - The callback to remove for this event. */
    pca.ignore =
      pca.ignore ||
      function (target, event, action) {
        if (window.removeEventListener)
          target.removeEventListener(event, action);
        else target.detachEvent("on" + event, action);
      };

    /** Stops other actions of an event.
     * @memberof pca
     * @param {Event} event - The event to stop. */
    pca.smash =
      pca.smash ||
      function (event) {
        var e = event || window.event;
        e.stopPropagation ? e.stopPropagation() : (e.cancelBubble = true);
        e.preventDefault ? e.preventDefault() : (e.returnValue = false);
      };

    /** Debug messages to the console.
     * @memberof pca
     * @param {string} message - The debug message text. */
    pca.debug =
      pca.debug ||
      function (message) {
        if (typeof console != "undefined" && console.debug)
          console.debug(message);
      };

    /** Creates and returns are new debounced version of the passed function, which will postpone
     * its execution until after the 'delay' milliseconds have elapsed since this last time the function was
     * invoked. (-- PORT FROM underscore.js with some tweaks to support IE8 events--)
     * @memberof pca
     * @param {function} func - The funcion to call when the timeout has elapsed.
     * @param {integer} wait - The number of milliseconds to wait between calling the function.
     * @param {integer} immediate - An ovveride to call the function immediately. */
    pca.debounce =
      pca.debounce ||
      function (func, wait, immediate) {
        var timeout;
        return function () {
          var context = this;

          var args = arguments;

          if (arguments && arguments.length > 0) {
            args = [{ target: arguments[0].target || arguments[0].srcElement }];
          }

          var later = function () {
            timeout = null;
            if (!immediate) func.apply(context, args);
          };
          var callNow = immediate && !timeout;
          clearTimeout(timeout);
          timeout = setTimeout(later, wait);
          if (callNow) func.apply(context, args);
        };
      };

    /** Returns whether or not a particular function is defined.
     * @memberof pca
     * @param {function} func - The function to check */
    pca.defined =
      pca.defined ||
      function (func) {
        return typeof func == "function";
      };

    /** Returns whether or not a particular function is undefined.
     * @memberof pca
     * @param {function} fn - The function to check */
    pca.fnDefined = pca.defined;

    /** Returns the label element for a given DOM element.
     * @memberof pca
     * @param {string} elementNameOrId - The name or ID of the DOM element. */
    pca.getLabel =
      pca.getLabel ||
      function (elementNameOrId) {
        var labels = document.getElementsByTagName("LABEL");
        for (var i = 0; i < labels.length; i++) {
          if (labels[i].htmlFor !== "") {
            var elem = pca.getElement(labels[i].htmlFor);

            if (
              (elem && elem.name === elementNameOrId) ||
              elem.id === elementNameOrId
            )
              return labels[i];
          }
        }
        return null;
      };

    //get some reference to an element that we can use later in getElement
    pca.getReferenceToElement =
      pca.getReferenceToElement ||
      function (element) {
        return typeof element == "string"
          ? element
          : element
          ? element.id || element.name || ""
          : "";
      };

    /**
     * Extends one object into another, any number of objects can be supplied
     * To create a new object supply an empty object as the first argument
     * @param {Object} obj - The object to add properties to.
     * @param {Object} [sources] - One or more objects to take properties from.
     * @returns {Object} The destination object.
     */
    pca.extend =
      pca.extend ||
      function (obj /*...*/) {
        for (var i = 1; i < arguments.length; i++) {
          for (var key in arguments[i]) {
            if (arguments[i].hasOwnProperty(key)) obj[key] = arguments[i][key];
          }
        }

        return obj;
      };

    /**
     * Gets even inherited styles from element
     * @param {} element - The element to get the style for
     * @param {} styleProperty - The style property to be got, in the original css form
     * @returns {}
     */
    pca.getStyle =
      pca.getStyle ||
      function (element, styleProperty) {
        var camelize = function (str) {
          return str.replace(/\-(\w)/g, function (str, letter) {
            return letter.toUpperCase();
          });
        };

        if (element.currentStyle) {
          return element.currentStyle[camelize(styleProperty)];
        } else if (
          document.defaultView &&
          document.defaultView.getComputedStyle
        ) {
          return document.defaultView
            .getComputedStyle(element, null)
            .getPropertyValue(styleProperty);
        } else {
          return element.style[camelize(styleProperty)];
        }
      };

    /**
     * Detects browser support for a predefined list of capabilities
     * @param {string} checkType - The check to perform
     * @returns {boolean} Wether the given type is supported.
     */
    pca.supports =
      pca.supports ||
      function (checkType) {
        switch (checkType) {
          case "reverseGeo":
            return (
              document.location.protocol == "https:" &&
              window.navigator &&
              window.navigator.geolocation
            );
        }
        return false;
      };

    pca.guid =
      pca.guid ||
      (function () {
        /**
         * Generates a new guid
         * @method s4
         * @return CallExpression
         */
        function s4() {
          return Math.floor((1 + Math.random()) * 0x10000)
            .toString(16)
            .substring(1);
        }
        return function () {
          return (
            s4() +
            s4() +
            "-" +
            s4() +
            "-" +
            s4() +
            "-" +
            s4() +
            "-" +
            s4() +
            s4() +
            s4()
          );
        };
      })();

    pca.sessionId = pca.sessionId || pca.guid();

    //load when the document is ready
    pca._initDocumentReady();
  })(window);

})(window);
