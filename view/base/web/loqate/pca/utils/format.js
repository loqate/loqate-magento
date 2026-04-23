/**
 * @fileoverview PCA SDK – string and format utilities.
 *
 * A collection of pure-function helpers used across the SDK for:
 * - **Template substitution** (`pca.formatLine`) — fills `{Property}` placeholders
 *   in a format string from a data object, with optional uppercasing and
 *   conditional block suppression.
 * - **Tag normalisation** (`pca.formatTag`, `pca.formatTagWords`) — strips HTML,
 *   applies diacritic replacements and synonym expansion, and upper-cases text to
 *   produce a canonical string used for substring filtering.
 * - **Text utilities** (`pca.replaceList`, `pca.removeHtml`, `pca.escapeHtml`,
 *   `pca.trimSpaces`, `pca.tidy`, `pca.validId`, `pca.getText`, `pca.getNumber`).
 * - **Data utilities** (`pca.parseJSON`, `pca.parseJSONDate`, `pca.containsWord`,
 *   `pca.removeWord`, `pca.merge`).
 *
 * Depends on: {@link module:pca/core} (for `pca.diacritics`, `pca.synonyms`,
 * `pca.hypertext`).
 *
 * @module pca/utils/format
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

    /** Formats a data object into a display string by substituting property values
     * into a template.  Placeholder syntax:
     * - `{PropertyName}` — inserts the value; append `!` to uppercase it.
     * - `{ prefix {Name} suffix }` — the entire outer block is omitted when the
     *   inner property is empty or falsy, preventing stray separators.
     * @memberof pca
     * @param {Object} item - The data object whose properties are substituted.
     * @param {string} format - The template string, e.g. `"{Line1}, {City}"`.
     * @returns {string} The formatted string with all placeholders replaced.
     */
    pca.formatLine =
      pca.formatLine ||
      function (item, format) {
        function property(c, t) {
          var val = (typeof item[c] == "function" ? item[c]() : item[c]) || "";
          return t === "!" ? val.toUpperCase() : val;
        }

        //replace properties with conditional formatting e.g. hello{ {name}!}
        format = format.replace(
          /\{([^\}]*\{(\w+)([^\}\w])?\}[^\}]*)\}/g,
          function (m, f, c, t) {
            var val = property(c, t);
            return val ? f.replace(/\{(\w+)([^\}\w])?\}/g, val) : "";
          }
        );

        return format.replace(/\{(\w+)([^\}\w])?\}/g, function (m, c, t) {
          return property(c, t);
        });
      };

    /** Formats a line into a simplified tag for filtering.
     * @memberof pca
     * @param {string} line - The text to format.
     * @returns {string} The formatted tag. */
    pca.formatTag =
      pca.formatTag ||
      function (line) {
        return line
          ? pca.replaceList(
              pca.replaceList(
                pca.removeHtml(line.toUpperCase()),
                pca.diacritics
              ),
              pca.synonyms
            )
          : "";
      };

    /** Formats a line into a tag and then separate words.
     * @memberof pca
     * @param {string} line - The text to format.
     * @returns {Array.<string>} The formatted tag words. */
    pca.formatTagWords =
      pca.formatTagWords ||
      function (line) {
        return pca.formatTag(line).split(" ");
      };

    /** Formats camaelcase text by inserting a separator string.
     * @memberof pca
     * @param {string} line - The text to format.
     * @param {string} [separator= ] - A string used to join the parts.
     * @returns {string} The formatted text. */
    pca.formatCamel =
      pca.formatCamel ||
      function (line, separator) {
        separator = separator || " ";

        function separate(m, b, a) {
          return b + separator + a;
        }

        line = line.replace(/([a-z])([A-Z0-9])/g, separate); //before an upperase letter or number
        line = line.replace(/([0-9])([A-Z])/g, separate); //before an uppercase letter after a number
        line = line.replace(/([A-Z])([A-Z][a-z])/g, separate); //after multiple capital letters

        return line;
      };

    /** Performs all replacements in a list.
     * @memberof pca
     * @param {string} line - The text to format.
     * @param {Array.<Object>} list - The list of replacements.
     * @returns {string} The formatted text. */
    pca.replaceList =
      pca.replaceList ||
      function (line, list) {
        for (var i = 0; i < list.length; i++)
          line = line.toString().replace(list[i].r, list[i].w);
        return line;
      };

    /** Removes HTML tags from a string.
     * @memberof pca
     * @param {string} line - The text to format.
     * @returns {string} The formatted text. */
    pca.removeHtml =
      pca.removeHtml ||
      function (line) {
        return line.replace(/<(?:.|\s)*?>+/g, "");
      };

    /** Converts a html string for display.
     * @memberof pca
     * @param {string} line - The text to format.
     * @returns {string} The formatted text. */
    pca.escapeHtml =
      pca.escapeHtml ||
      function (line) {
        return pca.replaceList(line, pca.hypertext);
      };

    /** Returns only the valid characters for a DOM id.
     * @memberof pca
     * @param {string} line - The text to format.
     * @returns {string} The formatted text. */
    pca.validId =
      pca.validId ||
      function (line) {
        return /[a-z0-9\-_:\.\[\]]+/gi.exec(line);
      };

    /** Removes unnecessary spaces.
     * @memberof pca
     * @param {string} line - The text to format.
     * @returns {string} The formatted text. */
    pca.trimSpaces =
      pca.trimSpaces ||
      function (line) {
        return line.replace(/^\s+|\s(?=\s)|\s$/g, "");
      };

    /** Removes unnecessary duplicated characters.
     * @memberof pca
     * @param {string} line - The text to format.
     * @param {string} symbol - The text to remove duplicates of.
     * @returns {string} The formatted text. */
    pca.tidy =
      pca.tidy ||
      function (line, symbol) {
        symbol = symbol.replace("\\", "\\\\");
        var rx = new RegExp(
          "^" + symbol + "+|" + symbol + "(?=" + symbol + ")|" + symbol + "$",
          "gi"
        );
        return line.replace(rx, "");
      };

    /** Gets the first words from a string.
     * @memberof pca
     * @param {string} line - The text to format.
     * @returns {string} The text. */
    pca.getText =
      pca.getText ||
      function (line) {
        return /[a-zA-Z][a-zA-Z\s]+[a-zA-Z]/.exec(line);
      };

    /** Gets the first number from a string.
     * @memberof pca
     * @param {string} line - The text to format.
     * @returns {string} The number. */
    pca.getNumber =
      pca.getNumber ||
      function (line) {
        return /\d+/.exec(line);
      };

    /** parse a JSON string if it's safe and return an object. This has a preference for the native parser.
     * @memberof pca
     * @param {string} text - The JSON text to parse.
     * @returns {Object} The object based on the JSON. */
    pca.parseJSON =
      pca.parseJSON ||
      function (text) {
        if (
          text &&
          /^[\],:{}\s]*$/.test(
            text
              .replace(/\\(?:["\\\/bfnrt]|u[0-9a-fA-F]{4})/g, "@")
              .replace(
                /"[^"\\\n\r]*"|true|false|null|-?\d+(?:\.\d*)?(?:[eE][+\-]?\d+)?/g,
                "]"
              )
              .replace(/(?:^|:|,)(?:\s*\[)+/g, "")
          )
        )
          return typeof JSON != "undefined" ? JSON.parse(text) : eval(text);

        return {};
      };

    /** Parse a formatted JSON date.
     * @memberof pca
     * @param {string|number} text - The date in milliseconds.
     * @returns {Date} The date object. */
    pca.parseJSONDate =
      pca.parseJSONDate ||
      function (text) {
        return new Date(parseInt(pca.getNumber(text)));
      };

    /** Checks if a string contains a word.
     * @memberof pca
     * @param {string} text - The text to test.
     * @param {string} word - The word to test for.
     * @returns {boolean} True if the text contains the word. */
    pca.containsWord =
      pca.containsWord ||
      function (text, word) {
        var rx = new RegExp("\\b" + word + "\\b", "gi");
        return rx.test(text);
      };

    /** Removes a word from a string.
     * @memberof pca
     * @param {string} text - The text to format.
     * @param {string} word - The word to replace.
     * @returns {string} The text with the word replaced. */
    pca.removeWord =
      pca.removeWord ||
      function (text, word) {
        var rx = new RegExp("\\s?\\b" + word + "\\b", "gi");
        return text.replace(rx, "");
      };

    /** Merges one objects properties into another
     * @memberof pca
     * @param {Object} source - The object to take properties from.
     * @param {Object} destination - The object to add properties to.
     * @returns {Object} The destination object. */
    pca.merge =
      pca.merge ||
      function (source, destination) {
        for (var i in source) if (!destination[i]) destination[i] = source[i];

        return destination;
      };

    /** Find a DOM element by id, name, or partial id.
     * @memberof pca
     * @param {string|HTMLElement} reference - The id, name or element to find.
     * @param {string|HTMLElement} [base=document] - The id, name or parent element to search from.
     * @returns {?HTMLElement} The first element found or null. */

})(window);
