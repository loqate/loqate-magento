/**
 * @fileoverview PCA SDK – pca.Tooltip: inline hover tooltip.
 *
 * Renders a floating `<div class="pcatooltip">` positioned relative to a target
 * element.  Used by {@link pca.Address} to display ARIA guidance messages and
 * helper text alongside address capture fields.
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/utils/dom}.
 *
 * @module pca/ui/tooltip
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

    pca.Tooltip =
      pca.Tooltip ||
      function (element, message) {
        /** @lends pca.Tooltip.prototype */
        var tooltip = new pca.Eventable(this);

        /** The parent HTML element for the tooltip. */
        tooltip.element = pca.create("div", { className: "pcatooltip" });
        tooltip.background = pca.create("div", { className: "pcabackground" });
        tooltip.message = pca.create("div", {
          className: "pcamessage",
          innerText: message,
        });

        /** Shows the tooltip.
         * @fires show */
        tooltip.show = function () {
          tooltip.element.style.display = "";
          tooltip.position();
          tooltip.fire("show");
          return tooltip;
        };

        /** Hides the tooltip.
         * @fires hide */
        tooltip.hide = function () {
          tooltip.element.style.display = "none";
          tooltip.fire("hide");
          return tooltip;
        };

        /** Sets the text for the tooltip.
         * @param {string} text - The text to set. */
        tooltip.setMessage = function (text) {
          pca.setValue(tooltip.message, text);
        };

        /** Positions the tooltip centrally above the element. */
        tooltip.position = function () {
          var parentPosition = pca.getPosition(element),
            parentSize = pca.getSize(element),
            topParent = pca.getTopOffsetParent(element),
            messageSize = pca.getSize(tooltip.message),
            windowSize = pca.getSize(window),
            windowScroll = pca.getScroll(window),
            fixed = !pca.isPage(topParent);

          var top =
              parentPosition.top -
              messageSize.height -
              5 +
              (fixed ? windowScroll.top : 0),
            left =
              parentPosition.left +
              parentSize.width / 2 -
              messageSize.width / 2 +
              (fixed ? windowScroll.left : 0);

          top = Math.min(
            top,
            windowSize.height + windowScroll.top - messageSize.height
          );
          top = Math.max(top, 0);

          left = Math.min(
            left,
            windowSize.width + windowScroll.left - messageSize.width
          );
          left = Math.max(left, 0);

          tooltip.element.style.top = top + "px";
          tooltip.element.style.left = left + "px";
        };

        if ((element = pca.getElement(element))) {
          pca.listen(element, "mouseover", tooltip.show);
          pca.listen(element, "mouseout", tooltip.hide);
        }

        tooltip.element.appendChild(tooltip.background);
        tooltip.element.appendChild(tooltip.message);
        tooltip.setMessage(message);

        pca.append(tooltip.element);

        tooltip.hide();

        return tooltip;
      };

    /** Formats a line by replacing tags in the form {Property} with the corresponding property value or method result from the item object.
     * @memberof pca
     * @param {Object} item - An object to format the parameters of.
     * @param {string} format - A template format string.
     * @returns {string} The formatted text.
     * @example pca.formatLine({"line1": "Line One", "line2": "Line Two"}, "{line1}{, {line2}}");
     * @returns "Line One, Line Two" */

})(window);
