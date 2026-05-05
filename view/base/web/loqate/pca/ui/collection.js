/**
 * @fileoverview PCA SDK – pca.Collection: ordered, filterable set of pca.Item objects.
 *
 * Maintains an ordered array of {@link pca.Item} instances and provides bulk
 * operations: `add`, `sort`, `reverse`, `filter` (by tag substring), `match`,
 * `clear`, `highlight`, and cursor-movement helpers (`next`, `previous`, `first`,
 * `last`).  Consumed by {@link pca.List} to back its scrollable dropdown.
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/ui/item}.
 *
 * @module pca/ui/collection
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

    pca.Collection =
      pca.Collection ||
      function () {
        /** @lends pca.Collection.prototype */
        var collection = new pca.Eventable(this);

        /** The list of items.
         * @type {Array.<pca.Item>} */
        collection.items = [];
        /** The index of the current highlighted item.
         * @type {number} */
        collection.highlighted = -1;
        /** The number of visible items.
         * @type {number} */
        collection.count = 0;
        collection.firstItem = null;
        collection.lastItem = null;
        collection.firstVisibleItem = null;
        collection.lastVisibleItem = null;

        /** Populates the collection with new items.
         * @param {Array.<Object>|Object} data - Data objects to add e.g. a response array from a service.
         * @param {string} format - A template string to format the label of the item.
         * @param {pca.Collection~itemCallback} callback - A callback function when the item is selected.
         * @fires add */
        collection.add = function (data, format, callback) {
          var additions = [];

          callback = callback || function () {};

          function createItem(attributes) {
            var item = new pca.Item(attributes, format);
            item.listen("mouseover", function () {
              collection.highlight(item);
            });

            item.listen("select", function (selectedItem) {
              collection.fire("select", selectedItem);
              callback(selectedItem);
            });

            collection.items.push(item);
            additions.push(item);
            return item;
          }

          if (data.length) {
            for (var i = 0; i < data.length; i++) createItem(data[i]);
          } else createItem(data);

          collection.count += data.length;
          collection.firstVisibleItem = collection.firstItem =
            collection.items[0];
          collection.lastVisibleItem = collection.lastItem =
            collection.items[collection.items.length - 1];
          collection.fire("add", additions);

          return collection;
        };

        /** Sort the items in the collection.
         * @param {string} [field] - The name of the property of the item to compare.
         * @fires sort */
        collection.sort = function (field) {
          collection.items.sort(function (a, b) {
            return field
              ? a.data[field] > b.data[field]
                ? 1
                : -1
              : a.tag > b.tag
              ? 1
              : -1;
          });

          collection.fire("sort");

          return collection;
        };

        /** Reverse the order of the items.
         * @fires reverse */
        collection.reverse = function () {
          collection.items.reverse();

          collection.fire("reverse");

          return collection;
        };

        /** Filters the items in the collection and hides all items that do not contain the term.
         * @param {string} term - The term which each item should contain.
         * @fires filter */
        collection.filter = function (term) {
          var tag = pca.formatTag(term),
            count = collection.count;

          collection.count = 0;
          collection.firstVisibleItem = null;
          collection.lastVisibleItem = null;

          collection.all(function (item) {
            if (~item.tag.indexOf(tag)) {
              item.show();
              collection.count++;

              collection.firstVisibleItem = collection.firstVisibleItem || item;
              collection.lastVisibleItem = item;
            } else item.hide();
          });

          if (count !== collection.count) collection.fire("filter");

          return collection;
        };

        /** Returns the items which match the search term.
         * @param {string} term - The term which each item should contain.
         * @returns {Array.<pca.Item>} The items matching the search term. */
        collection.match = function (term) {
          var tag = pca.formatTag(term),
            matches = [];

          collection.all(function (item) {
            if (~item.tag.indexOf(tag)) matches.push(item);
          });

          return matches;
        };

        /** Remove all items from the collection.
         * @fires clear */
        collection.clear = function () {
          collection.items = [];
          collection.count = 0;
          collection.highlighted = -1;
          collection.firstItem = null;
          collection.lastItem = null;
          collection.firstVisibleItem = null;
          collection.lastVisibleItem = null;

          collection.fire("clear");

          return collection;
        };

        /** Runs a function for every item in the list or until false is returned.
         * @param {pca.Collection~itemDelegate} delegate - The delegate function to handle each item. */
        collection.all = function (delegate) {
          for (var i = 0; i < collection.items.length; i++) {
            if (delegate(collection.items[i], i) === false) break;
          }

          return collection;
        };

        /** Sets the current highlighted item.
         * @param {pca.Item} item - The item to highlight.
         * @fires highlight */
        collection.highlight = function (item) {
          if (~collection.highlighted)
            collection.items[collection.highlighted].lowlight();
          collection.highlighted = collection.index(item);
          if (~collection.highlighted)
            collection.items[collection.highlighted].highlight();

          collection.fire("highlight", item);

          return collection;
        };

        /** Gets the index of an item.
         * @param {pca.Item} item - The item search for.
         * @returns {number} The index of the item or -1.*/
        collection.index = function (item) {
          for (var i = 0; i < collection.items.length; i++) {
            if (collection.items[i] === item) return i;
          }

          return -1;
        };

        /** Returns the first matching item.
         * @param {pca.Collection~itemMatcher} [matcher] - The matcher function to handle each item.
         * @returns {pca.Item} The item found or null. */
        collection.first = function (matcher) {
          for (var i = 0; i < collection.items.length; i++) {
            if (
              !matcher
                ? collection.items[i].visible
                : matcher(collection.items[i])
            )
              return collection.items[i];
          }

          return null;
        };

        /** Returns the last matching item.
         * @param {pca.Collection~itemMatcher} [matcher] - The matcher function to handle each item.
         * @returns {pca.Item} The item found or null. */
        collection.last = function (matcher) {
          for (var i = collection.items.length - 1; i >= 0; i--) {
            if (
              !matcher
                ? collection.items[i].visible
                : matcher(collection.items[i])
            )
              return collection.items[i];
          }

          return null;
        };

        /** Returns the next matching item from the current selection.
         * @param {pca.Collection~itemMatcher} [matcher] - The matcher function to handle each item.
         * @returns {pca.Item} The item found or the first item. */
        collection.next = function (matcher) {
          for (
            var i = collection.highlighted + 1;
            i < collection.items.length;
            i++
          ) {
            if (
              !matcher
                ? collection.items[i].visible
                : matcher(collection.items[i])
            )
              return collection.items[i];
          }
        };

        /** Returns the previous matching item to the current selection.
         * @param {pca.Collection~itemMatcher} [matcher] - The matcher function to handle each item.
         * @returns {pca.Item} The item found or the last item. */
        collection.previous = function (matcher) {
          for (var i = collection.highlighted - 1; i >= 0; i--) {
            if (
              !matcher
                ? collection.items[i].visible
                : matcher(collection.items[i])
            )
              return collection.items[i];
          }
        };

        /** Returns all items that are visible in the list.
         * @returns {Array.<pca.Item>} The items that are visible. */
        collection.visibleItems = function () {
          var visible = [];

          collection.all(function (item) {
            if (item.visible) visible.push(item);
          });

          return visible;
        };

        return collection;

        /** Callback function for item selection.
         * @callback pca.Collection~itemCallback
         * @param {Object} data - The original data of the item. */

        /** Delegate function to handle an item.
         * @callback pca.Collection~itemDelegate
         * @param {pca.Item} item - The current item.
         * @param {number} index - The index of the current item in the collection.
         * @returns {boolean} Returns a response of false to stop the operation. */

        /** Delegate function to compare an item.
         * @callback pca.Collection~itemMatcher
         * @param {pca.Item} item - The current item.
         * @returns {boolean} Returns a response of true for a matching item. */
      };

    /**
     * List options.
     * @typedef {Object} pca.List.Options
     * @property {string} [name] - A reference for the list used an Id for ARIA.
     * @property {number} [minItems] - The minimum number of items to show in the list.
     * @property {number} [maxItems] - The maximum number of items to show in the list.
     * @property {boolean} [allowTab] - Allow the tab key to cycle through items in the list.
     */

    /** A HTML list to display items.
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {pca.List.Options} [options] - Additional options to apply to the list. */

})(window);
