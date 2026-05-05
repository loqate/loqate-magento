/**
 * @fileoverview PCA SDK – browser detection.
 *
 * Populates `pca.browser` with `{ name, version, fullVersion? }` by parsing
 * `navigator.userAgent`.  Recognises Chrome, Safari, Firefox, IE (Trident),
 * Edge, and Opera (OPR prefix).
 *
 * Depends on: {@link module:pca/core}.
 *
 * @module pca/browser
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

    /** Detected browser name and version, parsed from `navigator.userAgent`.
     * @memberof pca
     * @type {{ name: string, version: string, fullVersion?: string }}
     * @example
     * // pca.browser.name        → "Chrome"
     * // pca.browser.version     → "124"
     * // pca.browser.fullVersion → "124.0.6367.78"
     */
    pca.browser = (function () {
      var ua = navigator.userAgent,
        tem,
        M =
          ua.match(
            /(opera|chrome|safari|firefox|msie|trident(?=\/))\/?\s*(\d+)/i
          ) || [],
        fullVersionMatch =
          ua.match(
            /(opera|chrome|safari|firefox|msie|trident(?=\/))\/?\s*([\d.]+)/i
          ) || [];
      if (/trident/i.test(M[1])) {
        tem = /\brv[ :]+(\d+)/g.exec(ua) || [];
        return { name: "IE", version: tem[1] || "" };
      }
      if (M[1] === "Chrome") {
        tem = ua.match(/\b(OPR|Edge)\/(\d+)/);
        if (tem != null)
          return { name: tem[1].replace("OPR", "Opera"), version: tem[2] };
      }
      M = M[2] ? [M[1], M[2]] : [navigator.appName, navigator.appVersion, "-?"];
      if ((tem = ua.match(/version\/(\d+)/i)) != null) M.splice(1, 1, tem[1]);
      if (fullVersionMatch && fullVersionMatch[2]) {
        return { name: M[0], version: M[1], fullVersion: fullVersionMatch[2] };
      }
      return { name: M[0], version: M[1] };
    })();

})(window);
