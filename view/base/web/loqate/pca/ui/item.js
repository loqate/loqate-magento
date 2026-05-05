/**
 * @fileoverview PCA SDK – pca.Item: single selectable result row.
 *
 * Wraps one service-response result object in a rendered `<div class="pcaitem">`
 * element with highlight/lowlight visual state and forwarded mouse/keyboard
 * interaction events.  Items are collected by {@link pca.Collection} and rendered
 * inside a {@link pca.List} dropdown.
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/utils/format},
 * {@link module:pca/utils/dom}.
 *
 * @module pca/ui/item
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

    pca.Item =
      pca.Item ||
      function (data, format) {
        /** @lends pca.Item.prototype */
        var item = new pca.Eventable(this),
          highlightClass = "pcaselected";

        /** The original data for the item. */
        item.data = data;
        /** The original formatter for the item. */
        item.format = format;
        item.html = pca.formatLine(data, format);
        item.tag = pca.formatTag(data.tag || item.html);
        /** The HTML element for the item. */

        item.element = pca.create("div", {
          className: "pcaitem",
          innerHTML: item.html,
          role: "option",
        });

        item.element.setAttribute("tabindex", -1);

        //ARIA
        item.element.addEventListener("keydown", function (evt) {
          //get correct control
          if (pca.operatingNamespace || pca.activeAddress) {
            var control =
              pca.activeAddress ||
              pca[pca.operatingNamespace].controls.find(function (control) {
                return (
                  control.autocomplete.list.collection.items.indexOf(item) > -1
                );
              });
            if (control) {
              var currentAutocomplete = control.inCountryListMode
                ? control.countrylist.autocomplete
                : control.autocomplete;
              //delegate to autocomplete control
              if (
                currentAutocomplete.list.collection.items.indexOf(item) == 0 &&
                evt.keyCode == 38
              ) {
                //is first and going up - refocus to input
                if (currentAutocomplete.field) {
                  currentAutocomplete.field.focus();
                  setTimeout(function () {
                    pca.moveCursorToEnd(currentAutocomplete.field);
                  }, 0);
                }
              } else if (evt.keyCode == 9 || evt.keyCode == 27) {
                //tab
                if (
                  !control.inCountryListMode &&
                  control.options.bar.visible &&
                  control.options.bar.showCountry
                ) {
                  //focus on the country button
                } else if (currentAutocomplete.field) {
                  currentAutocomplete.field.focus();
                  pca.moveCursorToEnd(currentAutocomplete.field);
                  currentAutocomplete.hide();
                  currentAutocomplete.field.dispatchEvent(evt);
                  pca.smash(evt);
                }
              } else {
                currentAutocomplete.list.navigate(evt.keyCode);
              }
            }
          }
        });

        item.visible = true;

        /** Applies the highlight style.
         * @fires highlight */
        item.highlight = function () {
          pca.addClass(item.element, highlightClass);
          item.fire("highlight");
          item.element.focus();
          return item;
        };

        /** Removes the highlight style.
         * @fires lowlight */
        item.lowlight = function () {
          pca.removeClass(item.element, highlightClass);
          item.fire("lowlight");

          return item;
        };

        /** The user is hovering over the item.
         * @fires mouseover */
        item.mouseover = function () {
          item.fire("mouseover");
        };

        /** The user has left the item.
         * @fires mouseout */
        item.mouseout = function () {
          item.fire("mouseout");
        };

        /** The user is pressed down on the item.
         * @fires mousedown */
        item.mousedown = function () {
          item.fire("mousedown");
        };

        /** The user released the item.
         * @fires mouseup */
        item.mouseup = function () {
          item.fire("mouseup");

          if (pca.galaxyFix) item.select();
        };

        /** The user has clicked the item.
         * @fires click */
        item.click = function () {
          item.fire("click");

          if (pca.galaxyFix) return;

          item.select();
        };

        /** Selects the item.
         * @fires select */
        item.select = function () {
          item.fire("select", item.data);

          return item;
        };

        /** Makes the item invisible.
         * @fires hide */
        item.hide = function () {
          item.visible = false;
          item.element.style.display = "none";
          item.fire("hide");

          return item;
        };

        /** Makes the item visible.
         * @fires show */
        item.show = function () {
          item.visible = true;
          item.element.style.display = "";
          item.fire("show");

          return item;
        };

        pca.listen(item.element, "mouseout", item.mouseout);
        pca.listen(item.element, "mousedown", item.mousedown);
        pca.listen(item.element, "mouseup", item.mouseup);
        pca.listen(item.element, "click", item.click);

        return item;
      };

    /** Represents a collection of items.
     * @memberof pca
     * @constructor
     * @mixes Eventable */

})(window);
