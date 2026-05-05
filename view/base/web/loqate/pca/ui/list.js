/**
 * @fileoverview PCA SDK – pca.List: scrollable HTML list.
 *
 * Manages a `<div class="pca pcalist">` container that renders items from a
 * {@link pca.Collection}.  Handles auto-sizing (min/max item constraints),
 * touch/mouse scroll gestures, keyboard navigation (arrow keys, Page Up/Down),
 * and optional pinned header/footer items.  Consumed by {@link pca.AutoComplete}.
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/utils/dom},
 * {@link module:pca/ui/collection}.
 *
 * @module pca/ui/list
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

    pca.List =
      pca.List ||
      function (options) {
        /** @lends pca.List.prototype */
        var list = new pca.Eventable(this);

        list.options = options || {};
        /** The HTML parent element of the list */
        list.element = pca.create("div", { className: "pca pcalist" });
        /** The collection of items in the list
         * @type {pca.Collection} */
        list.collection = new pca.Collection();
        list.visible = true;
        list.scroll = {
          held: false,
          moved: false,
          origin: 0,
          position: 0,
          x: 0,
          y: 0,
          dx: 0,
          dy: 0,
        };
        list.highlightedItem = null;
        /** An item that will always be displayed first in the list.
         * @type {pca.Item} */
        list.headerItem = null;
        /** An item that will always be displayed last in the list.
         * @type {pca.Item} */
        list.footerItem = null;
        list.firstItem = null;
        list.lastItem = null;
        list.firstItemClass = "pcafirstitem";
        list.lastItemClass = "pcalastitem";

        list.options.minItems = list.options.minItems || 0;
        list.options.maxItems = list.options.maxItems || 10;
        list.options.allowTab = list.options.allowTab || false;

        /** Shows the list.
         * @fires show */
        list.show = function () {
          list.visible = true;
          list.element.style.display = "";
          list.fire("show");
          list.resize();

          return list;
        };

        /** Hides the list.
         * @fires hide */
        list.hide = function () {
          list.visible = false;
          list.element.style.display = "none";
          list.fire("hide");

          return list;
        };

        /** Redraws the list by removing all children and adding them again.
         * @fires draw */
        list.draw = function () {
          list.destroy();

          if (list.headerItem)
            list.element.appendChild(list.headerItem.element);

          list.collection.all(function (item) {
            list.element.appendChild(item.element);
          });

          if (list.footerItem)
            list.element.appendChild(list.footerItem.element);

          list.resize();
          list.fire("draw");

          return list;
        };

        /** Marks the first and last items in the list with a CSS class */
        list.markItems = function () {
          if (list.firstItem)
            pca.removeClass(list.firstItem.element, list.firstItemClass);
          if (list.lastItem)
            pca.removeClass(list.lastItem.element, list.lastItemClass);

          if (list.collection.count) {
            list.firstItem =
              list.headerItem || list.collection.firstVisibleItem;
            list.lastItem = list.footerItem || list.collection.lastVisibleItem;
            pca.addClass(list.firstItem.element, list.firstItemClass);
            pca.addClass(list.lastItem.element, list.lastItemClass);
          }
        };

        /** Adds items to the list collection.
         * @param {Array.<Object>} array - An array of data objects to add e.g. a response array from a service.
         * @param {string} format - A template string to format the label of the item.
         * @param {pca.Collection~itemCallback} callback - A callback function when the item is selected.
         * @fires add */
        list.add = function (array, format, callback) {
          list.collection.add(array, format, callback);
          list.draw();

          return list;
        };

        /** Destroys all items in the list. */
        list.destroy = function () {
          while (list.element.childNodes && list.element.childNodes.length)
            list.element.removeChild(list.element.childNodes[0]);

          return list;
        };

        /** Clears all items from the list
         * @fires clear */
        list.clear = function () {
          list.collection.clear();
          list.destroy();
          list.fire("clear");

          return list;
        };

        /** Sets the scroll position of the list.
         * @param {number} position - The top scroll position in pixels.
         * @fires scroll */
        list.setScroll = function (position) {
          list.element.scrollTop = position;
          list.fire("scroll");

          return list;
        };

        /** Enables touch input for list scrolling.
         * Most mobile browsers will handle scrolling without this. */
        list.enableTouch = function () {
          //touch events
          function touchStart(event) {
            event = event || window.event;
            list.scroll.held = true;
            list.scroll.moved = false;
            list.scroll.origin = parseInt(list.scrollTop);
            list.scroll.y = parseInt(event.touches[0].pageY);
          }

          function touchEnd() {
            list.scroll.held = false;
          }

          function touchCancel() {
            list.scroll.held = false;
          }

          function touchMove(event) {
            if (list.scroll.held) {
              event = event || window.event;

              //Disable Gecko and Webkit image drag
              pca.smash(event);

              list.scroll.dy = list.scroll.y - parseInt(event.touches[0].pageY);
              list.scroll.position = list.scroll.origin + list.scroll.dy;
              list.setScroll(list.scroll.position);
              list.scroll.moved = true;
            }
          }

          pca.listen(list.element, "touchstart", touchStart);
          pca.listen(list.element, "touchmove", touchMove);
          pca.listen(list.element, "touchend", touchEnd);
          pca.listen(list.element, "touchcancel", touchCancel);

          return list;
        };

        /** Moves to an item in the list */
        list.move = function (item) {
          if (item) {
            list.collection.highlight(item);

            if (item === list.headerItem || item === list.footerItem)
              item.highlight();

            list.scrollToItem(item);
          }

          return list;
        };

        /** Moves to the next item in the list. */
        list.next = function () {
          return list.move(list.nextItem());
        };

        /** Moves to the previous item in the list */
        list.previous = function () {
          return list.move(list.previousItem());
        };

        /** Moves to the first item in the list. */
        list.first = function () {
          return list.move(list.firstItem);
        };

        /** Moves to the last item in the list. */
        list.last = function () {
          return list.move(list.lastItem);
        };

        /** Returns the next item.
         * @returns {pca.Item} The next item. */
        list.nextItem = function () {
          if (!list.highlightedItem) return list.firstItem;

          if (
            list.highlightedItem === list.collection.lastVisibleItem &&
            (list.footerItem || list.headerItem)
          )
            return list.footerItem || list.headerItem;

          if (
            list.footerItem &&
            list.headerItem &&
            list.highlightedItem === list.footerItem
          )
            return list.headerItem;

          return list.collection.next();
        };

        /** Returns the previous item.
         * @returns {pca.Item} The previous item. */
        list.previousItem = function () {
          if (!list.highlightedItem) return list.lastItem;

          if (
            list.highlightedItem === list.collection.firstVisibleItem &&
            (list.footerItem || list.headerItem)
          )
            return list.headerItem || list.footerItem;

          if (
            list.footerItem &&
            list.headerItem &&
            list.highlightedItem === list.headerItem
          )
            return list.footerItem;

          return list.collection.previous();
        };

        /** Returns the current item.
         * @returns {pca.Item} The current item. */
        list.currentItem = function () {
          return list.highlightedItem;
        };

        /** Returns true if the current item is selectable.
         * @returns {boolean} True if the current item is selectable. */
        list.selectable = function () {
          return list.visible && !!list.currentItem();
        };

        /** Calls the select function for the current item */
        list.select = function () {
          if (list.selectable()) list.currentItem().select();

          return list;
        };

        /** Handles list navigation based upon a key code
         * @param {number} key - The keyboard key code.
         * @param {boolean} firstIfDown - Go to the first element if down key is pressed.
         * @returns {boolean} True if the list handled the key code. */
        list.navigate = function (key, firstIfDown) {
          switch (key) {
            case 40: //down
              if (firstIfDown) {
                list.first();
              } else {
                list.next();
              }
              return true;
            case 38: //up
              list.previous();
              return true;
            case 13: //enter/return
              if (list.selectable()) {
                list.select();
                return true;
              }
            case 9: //tab
              if (list.options.allowTab) {
                list.next();
                return true;
              }
          }

          return false;
        };

        /** Scrolls the list to show an item.
         * @param {pca.Item} item - The item to scroll to. */
        list.scrollToItem = function (item) {
          list.scroll.position = list.element.scrollTop;

          if (item.element.offsetTop < list.scroll.position) {
            list.scroll.position = item.element.offsetTop;
            list.setScroll(list.scroll.position);
          } else {
            if (
              item.element.offsetTop + item.element.offsetHeight >
              list.scroll.position + list.element.offsetHeight
            ) {
              list.scroll.position =
                item.element.offsetTop +
                item.element.offsetHeight -
                list.element.offsetHeight;
              list.setScroll(list.scroll.position);
            }
          }

          return list;
        };

        /** Filters the list item collection.
         * @param {string} term - The term to filter the items on.
         * @fires filter */
        list.filter = function (term) {
          var current = list.collection.count;

          list.collection.filter(term);
          list.markItems();

          if (current !== list.collection.count) list.fire("filter", term);

          return list;
        };

        /** Calculates the height of the based on minItems, maxItems and item size.
         * @returns {number} The height required in pixels. */
        list.getHeight = function () {
          var visibleItems = list.collection.visibleItems(),
            headerItemHeight = list.headerItem
              ? pca.getSize(list.headerItem.element).height
              : 0,
            footerItemHeight = list.footerItem
              ? pca.getSize(list.footerItem.element).height
              : 0,
            lastItemHeight = 0,
            itemsHeight = 0;

          //count the height of items in the list
          for (
            var i = 0;
            i < visibleItems.length && i < list.options.maxItems;
            i++
          ) {
            lastItemHeight = pca.getSize(visibleItems[i].element).height;
            itemsHeight += lastItemHeight;
          }

          //calculate the height of blank space required to keep the list height - assumes the last item has no bottom border
          if (visibleItems.length < list.options.minItems)
            itemsHeight +=
              (lastItemHeight + 1) *
              (list.options.minItems - visibleItems.length);

          return itemsHeight + headerItemHeight + footerItemHeight;
        };

        /** Sizes the list based upon the maximum number of items. */
        list.resize = function () {
          var height = list.getHeight();

          if (height > 0) list.element.style.height = height + "px";
        };

        //Create an item for the list which is not in the main collection
        function createListItem(data, format, callback) {
          var item = new pca.Item(data, format);

          item.listen("mouseover", function () {
            list.collection.highlight(item);
            item.highlight();
          });

          list.collection.listen("highlight", item.lowlight);

          item.listen("select", function (selectedItem) {
            list.collection.fire("select", selectedItem);
            callback(selectedItem);
          });

          return item;
        }

        /** Adds an item to the list which will always appear at the bottom. */
        list.setHeaderItem = function (data, format, callback) {
          list.headerItem = createListItem(data, format, callback);
          pca.addClass(list.footerItem.element, "pcaheaderitem");
          list.markItems();
          return list;
        };

        /** Adds an item to the list which will always appear at the bottom. */
        list.setFooterItem = function (data, format, callback) {
          list.footerItem = createListItem(data, format, callback);
          pca.addClass(list.footerItem.element, "pcafooteritem");
          list.markItems();
          return list;
        };

        //store the current highlighted item
        list.collection.listen("highlight", function (item) {
          list.highlightedItem = item;
        });

        //Map collection events
        list.collection.listen("add", function (additions) {
          list.markItems();
          list.fire("add", additions);
        });

        //ARIA support
        pca.setAttributes(list.element, {
          id: list.options.name,
          role: "listbox",
          "aria-activedescendant": "",
        });
        if (list.options.ariaLabel) {
          pca.setAttributes(list.element, {
            "aria-label": list.options.ariaLabel,
          });
        }

        list.collection.listen("add", function (additions) {
          function listenHighlightChange(item) {
            item.listen("highlight", function () {
              pca.setAttributes(list.element, {
                "aria-activedescendant": item.id,
              });
            });
          }

          for (var i = 0; i < additions.length; i++)
            listenHighlightChange(additions[i]);

          list.collection.all(function (item, index) {
            item.element.id = item.id = list.options.name + "_item" + index;
            pca.setAttributes(item.element, { role: "option" });
          });
        });

        return list;
      };

    /**
     * Autocomplete list options.
     * @typedef {Object} pca.AutoComplete.Options
     * @property {string} [name] - A reference for the list used an Id for ARIA.
     * @property {string} [className] - An additional class to add to the autocomplete.
     * @property {boolean} [force] - Forces the list to bind to the fields.
     * @property {boolean} [onlyDown] - Force the list to only open downwards.
     * @property {number|string} [width] - Fixes the width to the specified number of pixels.
     * @property {number|string} [height] - Fixes the height to the specified number of pixels.
     * @property {number|string} [left] - Shifts the list left by the specified number of pixels.
     * @property {number|string} [top] - Shifts the list left by the specified number of pixels.
     * @property {string} [emptyMessage] - When set an empty list will show this message rather than hiding after a filter.
     */

    /** Creates an autocomplete list which is bound to a field.
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {Array.<HTMLElement>} fields - A list of input elements to bind to.
     * @param {pca.AutoComplete.Options} [options] - Additional options to apply to the autocomplete list. */

})(window);
