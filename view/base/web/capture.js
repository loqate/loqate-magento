/**
 * capture.js — DEPRECATED ENTRY POINT
 *
 * This file has been replaced by a modular split under loqate/.
 * config.phtml now loads the individual modules in dependency order.
 *
 * Source layout:
 *
 *   loqate/pca/
 *     core.js                  PCA namespace, Eventable, document-ready, HTTP request pipeline
 *     assets.js                pca.loadScript / pca.loadStyle helpers
 *     utils/
 *       format.js              String/format utilities (formatLine, formatTag, …)
 *       dom.js                 DOM utilities (getElement, getValue, setValue, create, listen, …)
 *     ui/
 *       item.js                pca.Item      – single selectable result entry
 *       collection.js          pca.Collection – ordered, filterable set of Items
 *       list.js                pca.List       – scrollable HTML list over a Collection
 *       autocomplete.js        pca.AutoComplete – keyboard dropdown bound to a text input
 *       modal.js               pca.Modal      – modal dialog with form support
 *       tooltip.js             pca.Tooltip    – hover tooltip
 *     countries/
 *       data.js                pca.countries  – ISO country array + pca.countryNameType enum
 *       list.js                pca.CountryList – flag-icon country selector
 *     browser.js               pca.browser    – user-agent detection
 *     enums.js                 pca.fieldMode, pca.filteringMode, pca.orderingMode
 *     messages.js              pca.messages   – i18n strings (en, cy, fr, de)
 *     address.js               pca.Address    – main address capture control
 *
 *   loqate/magento/
 *     field-mappings.js        window.Loqate.fieldMappings – form field name maps
 *     region-mapper.js         window.Loqate.mapRegionSelectValueWithRetry – province <select> sync
 *     init.js                  loqateInit() – MutationObserver-based widget initialisation
 *
 * PCA SDK version: 3.99 (custom Loqate build)
 * Custom changes vs upstream SDK:
 *   - options.endpoint.literal / .find / .retrieve / .unwrapped
 *   - pca.fetch passes options object through to enable unwrapped mode
 *   - 'change' event dispatched on text inputs after field population
 */
