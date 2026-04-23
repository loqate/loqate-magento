/**
 * @fileoverview Loqate Magento – field name mappings for address capture.
 *
 * Defines `window.Loqate.fieldMappings`, consumed by
 * {@link module:loqate/magento/init} to bind `pca.Address` capture instances
 * to the correct form elements for each checkout context:
 *
 * - `default`        — frontend checkout shipping/billing
 *                      (standard Magento field names: `street[0]`, `city`, …).
 * - `billingFields`  — Magento admin new-order **billing** address panel.
 * - `shippingFields` — Magento admin new-order **shipping** address panel.
 *
 * Each entry is an array of `pca.Address.Binding` objects:
 * ```js
 * { element: string,       // HTML `name` attribute of the target form field
 *   field:   string,       // Loqate response field to map to (e.g. "Line1")
 *   mode:    pca.fieldMode // Bitfield: DEFAULT, POPULATE, PRESERVE, COUNTRY, … }
 * ```
 *
 * Must load after `loqate/pca/enums.js` (requires `pca.fieldMode` constants).
 *
 * @module loqate/magento/field-mappings
 */
(function () {
  var pca = window.pca;
  window.Loqate = window.Loqate || {};

  /**
   * Field binding maps keyed by address-form context.  Each value is an array
   * of {@link pca.Address.Binding} objects passed directly to
   * `new pca.Address(fields, options)`.
   *
   * @memberof module:loqate/magento/field-mappings
   * @type {Object.<string, Array<{element: string, field: string, mode: number}>>}
   * @property {Array} default        Frontend checkout shipping + billing forms.
   * @property {Array} billingFields  Admin panel billing address fieldset.
   * @property {Array} shippingFields Admin panel shipping address fieldset.
   */
  window.Loqate.fieldMappings = {
    default: [
      { element: "street[0]", field: "Line1", mode: pca.fieldMode.DEFAULT },
      { element: "street[1]", field: "Line2", mode: pca.fieldMode.POPULATE },
      {
        element: "company",
        field: "Company",
        mode: pca.fieldMode.POPULATE | pca.fieldMode.PRESERVE,
      },
      { element: "city", field: "City", mode: pca.fieldMode.POPULATE },
      {
        element: "region",
        field: "ProvinceName",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "region_id",
        field: "ProvinceName",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "postcode",
        field: "PostalCode",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "country_id",
        field: "CountryIso2",
        mode: pca.fieldMode.COUNTRY,
      },
    ],
    billingFields: [
      {
        element: "order[billing_address][street][0]",
        field: "Line1",
        mode: pca.fieldMode.DEFAULT,
      },
      {
        element: "order[billing_address][street][1]",
        field: "Line2",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[billing_address][company]",
        field: "Company",
        mode: pca.fieldMode.POPULATE | pca.fieldMode.PRESERVE,
      },
      {
        element: "order[billing_address][city]",
        field: "City",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[billing_address][region]",
        field: "ProvinceName",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[billing_address][region_id]",
        field: "ProvinceName",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[billing_address][postcode]",
        field: "PostalCode",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[billing_address][country_id]",
        field: "CountryIso2",
        mode: pca.fieldMode.COUNTRY,
      },
    ],
    shippingFields: [
      {
        element: "order[shipping_address][street][0]",
        field: "Line1",
        mode: pca.fieldMode.DEFAULT,
      },
      {
        element: "order[shipping_address][street][1]",
        field: "Line2",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[shipping_address][company]",
        field: "Company",
        mode: pca.fieldMode.POPULATE | pca.fieldMode.PRESERVE,
      },
      {
        element: "order[shipping_address][city]",
        field: "City",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[shipping_address][region]",
        field: "ProvinceName",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[shipping_address][region_id]",
        field: "ProvinceName",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[shipping_address][postcode]",
        field: "PostalCode",
        mode: pca.fieldMode.POPULATE,
      },
      {
        element: "order[shipping_address][country_id]",
        field: "CountryIso2",
        mode: pca.fieldMode.COUNTRY,
      },
    ],
  };


})();
