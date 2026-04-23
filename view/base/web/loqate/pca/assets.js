/**
 * @fileoverview PCA SDK – dynamic asset loader helpers.
 *
 * Provides two utilities for lazily injecting additional resources into the page:
 * - `pca.loadScript(name, callback, doc)` — injects a `<script>` tag into
 *   `<head>`.  Bare names are resolved relative to the default PCA host;
 *   paths containing `/` are used verbatim.
 * - `pca.loadStyle(name, callback, doc)` — injects a `<link rel="stylesheet">`
 *   tag into `<head>` using the same name-resolution logic.
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/utils/dom}
 * (`pca.create` must be available when these functions are called).
 *
 * @module pca/assets
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

    /** Dynamically load an additional script.
     * @memberof pca
     * @param {string} name - The name of the script to load.  Absolute paths or
     *   paths containing `/` are used verbatim; bare names are resolved against
     *   the default PCA host (`pca.protocol + "//" + pca.host + "/js/"`).
     * @param {function} [callback] - A function to call once the script has loaded.
     * @param {HTMLDocument} [doc=document] - The document to inject the tag into. */
    pca.loadScript =
      pca.loadScript ||
      function (name, callback, doc) {
        var script = pca.create("script", { type: "text/javascript" }),
          head = (doc || document).getElementsByTagName("head")[0];

        script.onload = script.onreadystatechange = function () {
          if (
            !this.readyState ||
            this.readyState === "loaded" ||
            this.readyState === "complete"
          ) {
            script.onload = script.onreadystatechange = null;
            (callback || function () {})();
          }
        };

        script.src =
          (~name.indexOf("/") ? "" : pca.protocol + "//" + pca.host + "/js/") +
          name;
        head.insertBefore(script, head.firstChild);
      };

    /** Dynamically load an additional style sheet.
     * @memberof pca
     * @param {string} name - the name of the style sheet to load.
     * @param {function} [callback] - a function to call once the style sheet has loaded.
     * @param {HTMLDocument} [doc=document] - The document element in which to append the script. */
    pca.loadStyle =
      pca.loadStyle ||
      function (name, callback, doc) {
        var style = pca.create("link", { type: "text/css", rel: "stylesheet" }),
          head = (doc || document).getElementsByTagName("head")[0];

        style.onload = style.onreadystatechange = function () {
          if (
            !this.readyState ||
            this.readyState === "loaded" ||
            this.readyState === "complete"
          ) {
            style.onload = style.onreadystatechange = null;
            (callback || function () {})();
          }
        };

        style.href =
          (~name.indexOf("/") ? "" : pca.protocol + "//" + pca.host + "/css/") +
          name;
        head.insertBefore(style, head.firstChild);
      };

    /** Represents an item of data with a HTML element.
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {Object} data - An object containing the data for the item.
     * @param {string} format - The template string to format the item label with. */

})(window);
