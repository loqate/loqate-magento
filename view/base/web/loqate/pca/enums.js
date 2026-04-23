/**
 * @fileoverview PCA SDK – field, filtering, and ordering mode enumerations.
 *
 * Defines three read-only enum objects on the `pca` namespace:
 * - `pca.fieldMode`     — bitfield controlling how a form field participates in
 *   address capture.  Values may be combined with bitwise OR, e.g.
 *   `pca.fieldMode.POPULATE | pca.fieldMode.PRESERVE`.
 * - `pca.filteringMode` — restricts search results to a specific address
 *   component type (addresses, streets, localities, postcodes, or all).
 * - `pca.orderingMode`  — controls result ordering (proximity or default).
 *
 * Depends on: {@link module:pca/core}.
 *
 * @module pca/enums
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function () {
  var pca = (window.pca = window.pca || {});

    /** Field binding mode.  Controls how a form element participates in an
     * address capture widget.  Values can be combined with bitwise OR.
     * @memberof pca
     * @readonly
     * @enum {number}
     */
    pca.fieldMode = {
      /** Default of search and populate */
      DEFAULT: 3,
      /** The field will be ignored. */
      NONE: 0,
      /** Search from this field. */
      SEARCH: 1,
      /** Set the value of this field. */
      POPULATE: 2,
      /** Do not overwrite. */
      PRESERVE: 4,
      /** Show just the country list. */
      COUNTRY: 8,
    };

    /** Search filtering modes.
     * @memberof pca
     * @readonly
     * @enum {string} */
    pca.filteringMode = {
      /** Addresses results will be returned */
      ADDRESS: "Address",
      /** Streets results will be returned */
      STREET: "Street",
      /** Cities, towns and districts will be returned */
      LOCALITY: "Locality",
      /** Postcodes will be returned */
      POSTCODE: "Postcode",
      /** Everything will be returned */
      EVERYTHING: "",
    };

    /** Search ordering mode.
     * @memberof pca
     * @readonly
     * @enum {string} */
    pca.orderingMode = {
      /** Default ordering will be used */
      DEFAULT: "UserLocation",
      /** Results will be ordered by current proximity */
      LOCATION: "UserLocation",
      /** No special ordering */
      NONE: "",
    };

    /** Text messages to display
     * @memberof pca */

})();
