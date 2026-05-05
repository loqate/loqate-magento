/**
 * @fileoverview PCA SDK – pca.Address: main address capture control.
 *
 * The top-level orchestrator for address capture.  Binds a
 * {@link pca.AutoComplete} search dropdown to a set of address form fields,
 * makes Loqate Find/Retrieve API requests, populates the bound fields after a
 * selection, and manages the optional country-selector bar.
 *
 * Key custom extensions vs. the stock PCA SDK (v3.99):
 * - `options.endpoint.literal` / `.find` / `.retrieve` / `.unwrapped` — routes
 *   requests through Magento's proxy controller instead of the PCA cloud.
 * - `options.simulateReactEvents` — dispatches React-compatible synthetic events
 *   on populated fields so KnockoutJS / Alpine.js form bindings update correctly.
 *
 * Depends on: all other `pca/*` modules.
 *
 * @module pca/address
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function () {
  var pca = (window.pca = window.pca || {});

    pca.exampleAddress = {
      Id: "GBR|PR|52509479|0|0|0",
      DomesticId: "52509479",
      Language: "ENG",
      LanguageAlternatives: "ENG",
      Department: "",
      Company: "Postcode Anywhere (Europe) Ltd",
      SubBuilding: "",
      BuildingNumber: "",
      BuildingName: "Waterside",
      SecondaryStreet: "",
      Street: "Basin Road",
      Block: "",
      Neighbourhood: "",
      District: "",
      City: "Worcester",
      Line1: "Waterside",
      Line2: "Basin Road",
      Line3: "",
      Line4: "",
      Line5: "",
      AdminAreaName: "Worcester",
      AdminAreaCode: "47UE",
      Province: "Worcestershire",
      ProvinceName: "Worcestershire",
      ProvinceCode: "",
      PostalCode: "WR5 3DA",
      CountryName: "United Kingdom",
      CountryIso2: "GB",
      CountryIso3: "GBR",
      CountryIsoNumber: 826,
      SortingNumber1: "94142",
      SortingNumber2: "",
      Barcode: "(WR53DA1PX)",
      Label:
        "Postcode Anywhere (Europe) Ltd\nWaterside\nBasin Road\n\nWorcester\nWR5 3DA\nUnited Kingdom",
      Type: "Commercial",
      DataLevel: "Premise",
      Field1: "",
      Field2: "",
      Field3: "",
      Field4: "",
      Field5: "",
      Field6: "",
      Field7: "",
      Field8: "",
      Field9: "",
      Field10: "",
      Field11: "",
      Field12: "",
      Field13: "",
      Field14: "",
      Field15: "",
      Field16: "",
      Field17: "",
      Field18: "",
      Field19: "",
      Field20: "",
    };

    /** Formatting templates.
     * @memberof pca */
    pca.templates = {
      AUTOCOMPLETE:
        "{HighlightedText}{<span class='pcadescription'>{HighlightedDescription}</span>}",
      AUTOCOMPLETE_UTILITY:
        "{<span class='pcautilitytype'>({UtilityType})</span>}{HighlightedText}{<span class='pcadescription'>{HighlightedDescription}</span>}",
    };

    /**
     * Address control field binding.
     * @typedef {Object} pca.Address.Binding
     * @property {string} element - The id or name of the element.
     * @property {string} field - The format string for the address field, e.g. "{Line1}"
     * @property {pca.fieldMode} mode - The mode of the field.
     */

    /**
     * Address control bar options.
     * @typedef {Object} pca.Address.BarOptions
     * @property {boolean} [visible=false] - Show the search bar.
     * @property {boolean} [showCountry=true] - Show the country flag.
     * @property {boolean} [showLogo=true] - Show the logo.
     * @property {boolean} [logoLink=true] - Use the logo as a web link.
     * @property {string} [logoClass] - The CSS class name for the logo.
     * @property {string} [logoTitle] - The hover text for the logo.
     * @property {string} [logoUrl] - The URL to link to from the logo.
     */

    /**
     * Web service search options
     * @typedef {Object} pca.Address.SearchOptions
     * @property {number} [maxSuggestions] - The maximum number of autocomplete suggestions to get.
     * @property {number} [maxResults] - The maximum number of address results to get.
     */

    /**
     * Address control options.
     * @typedef {Object} pca.Address.Options
     * @property {string} key - The key to use for service request authentication.
     * @property {string} [name] - A reference for the control used as an id to provide ARIA support.
     * @property {boolean} [populate=true] - Used to enable or disable population of all fields.
     * @property {boolean} [onlyInputs=false] - Only input fields will be populated.
     * @property {boolean} [autoSearch=false] - Search will be triggered on field focus.
     * @property {boolean} [preselect=true] - Automatically highlight the first item in the list.
     * @property {boolean} [prompt=false] - Shows a message to prompt the user for more detail.
     * @property {number} [promptDelay=0] - The time in milliseconds before the control will prompt the user for more detail.
     * @property {boolean} [inlineMessages=false] - Shows messages within the list rather than above the search field.
     * @property {boolean} [setCursor=false] - Updates the input field with the current search text.
     * @property {boolean} [matchCount=false] - Shows the number of possible matches while searching.
     * @property {number} [minSearch=1] - Search will be triggered on field focus.
     * @property {number} [minItems=1] - The minimum number of items to show in the list.
     * @property {number} [maxItems=7] - The maximum number of items to show in the list.
     * @property {boolean} [manualEntry=false] - If no results are found, the message can be clicked to disable the control.
     * @property {boolean} [manualEntryItem=false] - Adds an item to the bottom of the list which enables manual address entry.
     * @property {number} [disableTime=60000] - The time in milliseconds to disable the control for manual entry.
     * @property {boolean} [suppressAutocomplete=true] - Suppress the default browser field autocomplete on search fields.
     * @property {boolean} [setCountryByIP=false] - Automatically set the country based upon the user IP address.
     * @property {string} [culture] - Force set the culture for labels, e.g. "en-us", "fr-ca".
     * @property {string} [languagePreference] - The preferred language for the selected address, e.g. "eng", "fra".
     * @property {pca.filteringMode} [filteringMode] - The type of results to search for.
     * @property {pca.orderingMode} [orderingMode] - The order in which to display results.
     * @property {pca.CountryList.Options} [countries] - Options for the country list.
     * @property {pca.AutoComplete.Options} [list] - Options for the search list.
     * @property {pca.Address.BarOptions} [bar] - Options for the address control footer bar.
     * @property {pca.Address.SearchOptions} [search] - Options for control search results.
     */

    /** Address searching component.
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {Array.<pca.Address.Binding>} fields - A list of field bindings.
     * @param {pca.Address.Options} options - Additional options to apply to the control.
     */
    pca.Address = function (fields, options) {
      /** @lends pca.Address.prototype */

      function parseOptions(options) {
        options = options || {};
        options.name = options.name || "";
        options.source = options.source || "";
        options.populate =
          typeof options.populate == "boolean" ? options.populate : true;
        options.onlyInputs =
          typeof options.onlyInputs == "boolean" ? options.onlyInputs : false;
        options.autoSearch =
          typeof options.autoSearch == "boolean" ? options.autoSearch : false;
        options.preselect =
          typeof options.preselect == "boolean" ? options.preselect : true;
        options.simulateReactEvents =
          typeof options.simulateReactEvents == "boolean"
            ? options.simulateReactEvents
            : false;
        options.minSearch = options.minSearch || 1;
        options.minItems = options.minItems || 1;
        options.maxItems = options.maxItems || 7;
        options.advancedFields = options.advancedFields || [];
        options.manualEntry =
          typeof options.manualEntry == "boolean" ? options.manualEntry : false;
        options.manualEntryItem =
          typeof options.manualEntryItem == "boolean"
            ? options.manualEntryItem
            : false;
        options.disableTime = options.disableTime || 60000;
        options.suppressAutocomplete =
          typeof options.suppressAutocomplete == "boolean"
            ? options.suppressAutocomplete
            : true;
        options.brand =
          options.brand || "PostcodeAnywhere" || "PostcodeAnywhere";
        options.product = options.product || "Capture+";
        options.culture = options.culture || "en-GB";
        options.prompt =
          typeof options.prompt == "boolean" ? options.prompt : false;
        options.promptDelay = options.promptDelay || 0;
        options.inlineMessages =
          typeof options.inlineMessages == "boolean"
            ? options.inlineMessages
            : false;
        options.setCursor =
          typeof options.setCursor == "boolean" ? options.setCursor : false;
        options.matchCount =
          typeof options.matchCount == "boolean" ? options.matchCount : false;
        options.languagePreference = options.languagePreference || "";
        options.filteringMode =
          options.filteringMode || pca.filteringMode.EVERYTHING;
        options.orderingMode = options.orderingMode || pca.orderingMode.DEFAULT;
        options.countries = options.countries || {};
        options.countries.codesList = options.countries.codesList || "";
        options.countries.defaultCode = options.countries.defaultCode || "";
        options.setCountryByIP =
          typeof options.setCountryByIP == "boolean" &&
          !options.countries.defaultCode
            ? options.setCountryByIP
            : false;
        options.countries.value = options.countries.value || "";
        options.countries.prepopulate =
          typeof options.countries.prepopulate == "boolean"
            ? options.countries.prepopulate
            : true;
        options.list = options.list || {};
        options.list.name = options.name ? options.name + "_results" : "";
        options.list.maxItems = options.list.maxItems || options.maxItems;
        options.list.minItems = options.list.minItems || options.minItems;
        options.countries.list =
          options.countries.list || pca.extend({}, options.list);
        options.countries.list.name = options.name
          ? options.name + "_countries"
          : "";
        options.GeoLocationEnabled =
          options.GeoLocationEnabled == "true" ||
          options.GeoLocationEnabled == true;
        options.GeoLocationRadius = options.GeoLocationRadius || 50;
        options.GeoLocationMaxItems = options.GeoLocationMaxItems || 10;
        options.utilitiesenabled =
          typeof options.utilitiesenabled == "boolean"
            ? options.utilitiesenabled
            : false;
        options.utilitiesutilitycodetype =
          options.utilitiesutilitycodetype || "ALL";
        /* If geolocation is enabled, it only works for UK */
        if (options.GeoLocationEnabled) {
          options.setCountryByIP = false;
        }
        options.bar = options.bar || {};
        /* If geolocation is turned on, we need the bar to be visible. */
        options.bar.visible = options.GeoLocationEnabled
          ? true
          : typeof options.bar.visible == "boolean"
          ? options.bar.visible
          : false;
        options.bar.showCountry =
          typeof options.bar.showCountry == "boolean"
            ? options.bar.showCountry
            : false;
        options.bar.showLogo =
          typeof options.bar.showLogo == "boolean"
            ? options.bar.showLogo
            : true;
        options.bar.logoLink =
          typeof options.bar.logoLink == "boolean"
            ? options.bar.logoLink
            : false;
        options.bar.logoClass = options.bar.logoClass || "pcalogo" || "pcalogo";
        options.bar.logoTitle =
          options.bar.logoTitle || "Powered by www.pcapredict.com";
        options.bar.logoUrl =
          options.bar.logoUrl || "http://www.pcapredict.com/";
        options.search = options.search || {};
        options.search.limit = options.search.limit || options.maxItems;
        options.search.origin =
          options.search.origin || options.countries.defaultCode || "";
        options.search.countries =
          options.search.countries || options.countries.codesList || "";
        options.search.datasets = options.search.datasets || "";
        options.search.language = options.search.language || "";
        /* Custom options for Magento integration */
        options.endpoint = options.endpoint || {};
        options.endpoint.literal = options.endpoint.literal || false;
        options.endpoint.find =
          options.endpoint.find || "Capture/Interactive/Find/v1.00";
        options.endpoint.retrieve =
          options.endpoint.retrieve || "Capture/Interactive/Retrieve/v1.00";
        options.endpoint.unwrapped = options.endpoint.unwrapped || false;
      }

      parseOptions(options);

      var address = new pca.Eventable(this);

      /** The current field bindings
       * @type {Array.<pca.Address.Binding>} */
      address.fields = fields || [];

      /** The current options
       * @type {pca.Address.Options} */
      address.options = options;

      /** The current key for service request authentication
       * @type {string} */
      address.key = address.options.key || "";

      //internal properties
      address.country = address.options.countries.defaultCode; //the country to search in
      address.origin = address.options.search.origin;
      address.advancedFields = address.options.advancedFields; //advanced field formats
      address.initialSearch = false; //when this has been done the list will filter
      address.searchContext = null; //stored when filtering to aid searching
      address.lastActionTimer = null; //the time of the last user interaction with the control
      address.notifcationTimer = null; //the time to show a notification for
      address.storedSearch = null; //stored value from search when country is switched
      address.geolocation = null; //users current geolocation when searching by location
      address.geoLocationButton = null;
      address.loaded = false; //current state of the control
      address.language = "en"; //current language code for the control
      address.filteringMode = address.options.filteringMode; //search filtering mode
      address.orderingMode = address.options.orderingMode; //search ordering mode
      address.testMode = false;
      address.instance = null;
      address.frugalSearch = true; //skip searches that would not refine the current results
      address.blockSearches = true; //block subsequent search requests while waiting for a response
      address.cacheRequests = true; //cache search and retrieve request results

      /** The search list
       * @type {pca.AutoComplete} */
      address.autocomplete = null;
      /** The country list
       * @type {pca.CountryList} */
      address.countrylist = null;
      address.messageBox = null;

      /** Initialise the control.
       * @fires load */
      address.load = function () {
        var searchFields = [],
          countryFields = [];

        //create a list of search and country fields
        for (var f = 0; f < address.fields.length; f++) {
          var field = address.fields[f];

          field.mode =
            typeof field.mode == "number" ? field.mode : pca.fieldMode.DEFAULT;

          if (field.mode & pca.fieldMode.COUNTRY) {
            countryFields.push(field.element);

            //tell the countrylist to use the same format
            if (/CountryIso2/.test(field.field)) {
              address.options.countries.nameType =
                address.options.countries.nameType || pca.countryNameType.ISO2;
              address.options.countries.valueType =
                address.options.countries.valueType || pca.countryNameType.ISO2;
            }
            if (/CountryIso3/.test(field.field)) {
              address.options.countries.nameType =
                address.options.countries.nameType || pca.countryNameType.ISO3;
              address.options.countries.valueType =
                address.options.countries.valueType || pca.countryNameType.ISO3;
            }
          } else if (field.mode & pca.fieldMode.SEARCH) {
            searchFields.push(field.element);
            var elem = pca.getElement(field.element);
            if (elem) {
              elem.setAttribute("role", "combobox");
              elem.setAttribute(
                "aria-describedby",
                "pca-country-button-help-text pca-help-text"
              );
              elem.setAttribute("aria-autocomplete", "list");
              elem.setAttribute("aria-expanded", "false");
              if (address.options.suppressAutocomplete) {
                address.preventAutocomplete(elem);
              }
            }
          }

          //check for advanced fields
          field.field = address.checkFormat(field.field);
        }

        //set the current language for UI
        address.detectLanguage();

        //create an autocomplete list to display search results
        address.options.list.name = "address_list";
        address.options.list.language = address.language;
        address.options.list.ariaLabel =
          pca.messages[address.language].ADDRESSLIST;
        address.autocomplete = new pca.AutoComplete(
          searchFields,
          address.options.list
        );
        address.autocomplete.address = address;

        //disable standard filter, this will be handled
        address.autocomplete.skipFilter = true;

        //marker function for when we display results with utilities lookup enabled.
        address.autocomplete.addUtilityLookupToTop = false;
        address.autocomplete.checkIfProbablyUtilitySearch = function () {
          // Nested, reduces logic evaluation.
          if (address.options.utilitiesenabled) {
            if (
              pca.getValue(address.autocomplete.field).replace(/[^0-9]/g, "")
                .length >= 5
            ) {
              address.autocomplete.addUtilityLookupToTop = true;

              // backup what user might have set.
              address.userSetFrugalSearch = address.frugalSearch;
              address.userSetCacheRequests = address.cacheRequests;

              // turn them off.
              address.frugalSearch = false;
              address.cacheRequests = false;
            } else {
              address.autocomplete.addUtilityLookupToTop = false;

              // restore the user's preferences.
              address.frugalSearch = address.userSetFrugalSearch;
              address.cacheRequests = address.userSetCacheRequests;
            }
          }
        };

        //listen for the user typing something
        address.autocomplete.listen("keyup", function (key) {
          pca.activeAddress = address;
          address.autocomplete.checkIfProbablyUtilitySearch();
          if (address.countrylist.autocomplete.visible)
            address.countrylist.autocomplete.handleKey(key);
          else if (address.autocomplete.controlDown && key === 40)
            address.switchToCountrySelect();
          else if (
            key === 0 ||
            key === 8 ||
            key === 32 ||
            (key >= 36 && key <= 40 && !address.initialSearch) ||
            key > 40
          )
            address.searchFromField();
        });

        //listen to the user pasting something
        address.autocomplete.listen("paste", function () {
          address.autocomplete.checkIfProbablyUtilitySearch();
          address.newSearch();
          address.searchFromField();
        });

        //show just the bar when a field gets focus
        address.autocomplete.listen("focus", address.focus);

        //pass through the show event
        address.autocomplete.listen("show", function () {
          address.fire("show");
        });

        //pass through the hide event
        address.autocomplete.listen("hide", function () {
          address.fire("hide");
        });

        //search on double click
        address.autocomplete.listen("dblclick", address.searchFromField);

        //if the list says its filtered out some results we need to load more
        address.autocomplete.list.listen("filter", function () {
          if (address.frugalSearch)
            address.search(pca.getValue(address.autocomplete.field));
        });

        //if the user hits delete we can't be sure we've done the first search
        address.autocomplete.listen("delete", address.newSearch);

        //get initial country value
        if (!address.options.countries.value && countryFields.length)
          address.options.countries.value = pca.getValue(countryFields[0]);

        //set the language for country names
        address.options.countries.language = address.language;

        //create a countrylist to change the current country
        address.countrylist = new pca.CountryList(
          countryFields,
          address.options.countries
        );
        address.countrylist.autocomplete.options.emptyMessage =
          pca.messages[address.language].NOCOUNTRY;
        address.country = address.countrylist.country.iso3;

        //when the country is changed
        address.countrylist.listen("change", function (country, isByIp) {
          address.country =
            country && country.iso3
              ? country.iso3
              : address.options.countries.defaultCode;
          if (isByIp) {
            address.updateGeoLocationActive();
          }
          address.origin = address.country;
          address.inCountryListMode = false;
          var countryDescription =
            address.autocomplete.footer.element.querySelector(
              "#pca-country-button-help-text"
            );
          if (countryDescription) {
            countryDescription.innerText = pca.formatLine(
              {
                country: country
                  ? address.language === "fr"
                    ? country.name_fr
                    : country.name
                  : "",
              },
              pca.messages[address.language].COUNTRYHELP
            );
          }
          address.fire("country", country);
        });

        //switch back to the regular list when a country is selected
        address.countrylist.listen("select", address.switchToSearchMode);

        //pass through the show event
        address.countrylist.autocomplete.listen("show", function () {
          address.fire("show");
        });

        //when the list is closed restore the search state
        address.countrylist.autocomplete.listen("hide", function () {
          address.autocomplete.enable();

          if (address.storedSearch != null)
            pca.setValue(
              address.autocomplete.field,
              address.storedSearch,
              address.options.simulateReactEvents
            );

          address.storedSearch = null;
          address.fire("hide");
        });

        //add the logo to the footer - shown with results
        var link = pca.create("a", {
            href: address.options.bar.logoUrl,
            target: "_blank",
            rel: "nofollow",
          }),
          logo = pca.create("div", {
            className:
              address.options.bar.logoClass + " pcalogo" + address.language,
            title: address.options.bar.logoTitle,
          });
        if (address.options.bar.logoLink) link.appendChild(logo);
        else link = logo;
        address.autocomplete.footer.setContent(link);

        //do not show the button if there is only one country
        if (address.countrylist.autocomplete.list.collection.count === 1)
          address.options.bar.showCountry = false;

        //add reverse geocode button if required
        if (address.options.GeoLocationEnabled) {
          var reverseButton = pca.create("div", {
            className: "geoLocationIcon",
            title: pca.messages[address.language].GEOLOCATION,
          });
          var reverseText = pca.create("div", {
            className: "geoLocationMessage",
            innerHTML: pca.messages[address.language].GEOLOCATION,
          });
          pca.listen(reverseButton, "click", address.startGeoLocation);
          pca.listen(reverseText, "click", address.startGeoLocation);

          address.autocomplete.footer.setContent(reverseButton);
          address.autocomplete.footer.setContent(reverseText);

          address.geocodeButton = reverseButton;
        }

        //create a flag icon and add to the footer of the search list
        var flagbutton = pca.create("div", { className: "pcaflagbutton" });
        flagbutton.setAttribute("tabindex", 0);
        flagbutton.setAttribute("role", "button");
        flagbutton.setAttribute("id", (options.list.name || "") + "_button"),
          flagbutton.setAttribute(
            "aria-labelledby",
            (options.list.name || "") + "_label"
          );

        var flag = address.countrylist.flag();

        var message = pca.create("div", {
          className: "pcamessage pcadisableselect",
          innerHTML: pca.messages[address.language].COUNTRYSELECT,
        });
        message.setAttribute(
          "id",
          (options.list.name || "addresslist_change_country") + "_label"
        );

        var countryMessage = pca.create("div", {
          className: "pcamessage pcadisableselect",
          innerHTML: pca.messages[address.language].COUNTRYSELECT,
        });
        countryMessage.setAttribute(
          "id",
          (options.countries.list.name || "countrylist_change_country") +
            "_label"
        );

        flagbutton.appendChild(message);
        flagbutton.appendChild(flag);

        //country a11y description
        var selectedCountry = pca.countries.find(function (c) {
          return c.iso3 == address.country;
        });
        var countryDescription = pca.create("span", {
          id: "pca-country-button-help-text",
          className: "pca-visually-hidden",
          innerText: pca.formatLine(
            {
              country: selectedCountry
                ? address.language === "fr"
                  ? selectedCountry.name_fr
                  : selectedCountry.name
                : "",
            },
            pca.messages[address.language].COUNTRYHELP
          ),
        });

        if (address.options.bar.showCountry) {
          address.autocomplete.footer.setContent(flagbutton);
          address.autocomplete.footer.setContent(countryDescription);
          address.countrylist.autocomplete.footer.setContent(countryMessage);
        }

        //clicking the flag button will show the country list
        pca.listen(flagbutton, "click", function (evt) {
          pca.smash(evt);
          address.switchToCountrySelect();
        });

        pca.listen(flagbutton, "keyup", function (evt) {
          if (evt.keyCode == 13) {
            pca.smash(evt);
            address.switchToCountrySelect();
          }
        });

        //switch to the logo
        address.showFooterLogo = function () {
          address.autocomplete.footer.element.classList.add("pca-showlogo");
          link.style.display = address.options.bar.showLogo ? "" : "none";
        };

        //switch to the message
        address.showFooterMessage = function () {
          link.style.display = address.options.bar.showCountry
            ? "none"
            : address.options.bar.showLogo
            ? ""
            : "none";
        };

        //check if search bar is visible
        if (address.options.bar.visible) {
          address.autocomplete.footer.show();
          address.countrylist.autocomplete.footer.show();
          address.showFooterMessage();
        } else {
          address.autocomplete.hide();
          address.countrylist.autocomplete.footer.hide();
        }

        //add an item for manual entry
        if (address.options.manualEntryItem) {
          address.addManualEntryItem();
        }

        //get the users country by IP
        if (address.options.setCountryByIP) {
          address.setCountryByIP();
        } else {
          address.countrylist.setCountry(address.country);
        }

        //create the hovering message box
        address.messageBox = pca.create("div", {
          className: "pcatext pcanotification",
          id: "pca-help-text",
        });
        pca.append(address.messageBox, pca.container);

        //control load finished
        address.loaded = true;
        address.fire("load");
      };

      /** Searches based upon the content of the current field. */
      address.searchFromField = function () {
        var term = pca.getValue(address.autocomplete.field);

        if (
          term &&
          !address.autocomplete.disabled &&
          (!address.initialSearch || !address.frugalSearch) &&
          term.length >= address.options.minSearch
        ) {
          address.initialSearch = true;
          address.search(term);
        }
      };

      address.updateGeoLocationActive = function () {
        if (
          address.country == "GBR" &&
          pca.supports("reverseGeo") &&
          address.options.GeoLocationEnabled
        ) {
          if (address.geocodeButton) {
            pca.addClass(address.geocodeButton, "active");
          }
        } else {
          if (address.geocodeButton) {
            pca.removeClass(address.geocodeButton, "active");
          }
        }
      };

      address.geolocationLookup = function (location) {
        if (location.coords) {
          var params = {
            Key: address.key,
            Latitude: location.coords.latitude,
            Longitude: location.coords.longitude,
            Items: address.options.GeoLocationMaxItems,
            Radius: address.options.GeoLocationRadius,
          };
          address.fire("geolocation", params);
          pca.fetch(
            "Capture/Interactive/GeoLocation/v1.00",
            params,
            function (items, response) {
              //success
              pca.removeClass(address.geocodeButton, "working");
              if (items.length)
                address.display(items, pca.templates.AUTOCOMPLETE, response);
              else address.noResultsMessage();
            },
            function (err) {
              pca.removeClass(address.geocodeButton, "working");
              address.error(err);
            }
          );
        } else {
          address.error(
            "The location supplied for the reverse geocode doesn't contain coordinate information."
          );
        }
      };

      address.utilitiesLookup = function (utilityCode) {
        var params = {
          Key: address.key,
          Text: utilityCode,
          UtilCodeType: address.options.utilitiesutilitycodetype,
        };
        address.fire("utilities", params);
        pca.fetch(
          "Capture/Interactive/Utilities/v1.00",
          params,
          function (items, response) {
            // Success
            if (items.length)
              address.display(
                items,
                pca.templates.AUTOCOMPLETE_UTILITY,
                response
              );
            else address.noResultsMessage();
          },
          function (err) {
            address.error(err);
          }
        );
      };

      /** Takes a search string and gets matches for it.
       * @param {string} term - The text to search for.
       * @fires search */
      address.search = function (term) {
        // utility route.
        if (address.autocomplete.addUtilityLookupToTop) {
          address.fire("utilitiesactive", term.replace(/[^0-9]/g, ""));

          address.display(
            [
              {
                Id: "",
                Type: "Utility",
                Text: "Lookup MPAN/MPRN/Serial Number",
                Highlight: "",
                Description: "",
              },
            ],
            pca.templates.AUTOCOMPLETE_UTILITY,
            null
          );
        } else {
          //does the search string still contain the last selected result
          if (address.searchContext) {
            if (~term.indexOf(address.searchContext.search))
              term = term.replace(
                address.searchContext.search,
                address.searchContext.text
              );
            else address.searchContext = null;
          }

          //if the last result is still being used, then filter from the id
          var isContained = address.searchContext != null;
          var search = {
            text: term,
            container: address.searchContext
              ? address.searchContext.id || ""
              : "",
            origin: address.origin || "",
            countries: address.options.search.countries,
            datasets: address.options.search.datasets,
            filter: address.filteringMode,
            limit: address.options.search.limit,
            language: address.options.search.language || address.language,
          };

          function success(items, response) {
            if (items.length) {
              address.display(items, pca.templates.AUTOCOMPLETE, response);
              if (isContained) {
                //focus on first item
                address.autocomplete.list.next();
              }
            } else {
              address.noResultsMessage();
            }
          }
          address.fire("search", search);

          if (search.text) {
            var searchParameters = {
              Key: address.key,
              Text: search.text,
              Container: search.container,
              Origin: search.origin,
              Countries: search.countries,
              Datasets: search.datasets,
              Limit: search.limit,
              Filter: search.filter,
              Language: search.language,
              $block: address.blockSearches,
              $cache: address.cacheRequests,
            };
            var requestOptions = { endpoint: {} };

            //flags for testing purposes
            if (address.testMode) searchParameters.Test = address.testMode;

            if (address.instance) searchParameters.Instance = address.instance;

            if (address.options.endpoint.unwrapped)
              searchParameters.$unwrapped = address.options.endpoint.unwrapped;

            if (address.options.endpoint.literal)
              requestOptions.endpoint.literal =
                address.options.endpoint.literal;

            pca.fetch(
              address.options.endpoint.find,
              searchParameters,
              success,
              address.error,
              requestOptions
            );
          }
        }

        return address;
      };

      /** Retrieves an address from an Id and populates the fields.
       * @param {string} id - The address id to retrieve. */
      address.retrieve = function (id) {
        var params = {
          Key: address.key,
          Id: id,
          Source: address.options.source,
          $cache: address.cacheRequests,
        };
        var requestOptions = { endpoint: {} };

        function fail(message) {
          address.message(pca.messages[address.language].RETRIEVEERROR, {
            clickToDisable: address.options.manualEntry,
            error: true,
            clearList: true,
          });
          address.error(message);
        }

        function success(response, responseObject, request) {
          if (request) {
            address.fire("retrieveResponse", response, responseObject, request);
          }
          response.length ? address.populate(response) : fail(response);
        }

        //add the advanced fields
        for (var i = 0; i < address.advancedFields.length; i++)
          params["field" + (i + 1) + "format"] = address.advancedFields[i];

        //add the param for field count
        if (address.advancedFields.length) {
          params.Fields = address.advancedFields.length;
        }

        if (address.options.endpoint.unwrapped)
          params.$unwrapped = address.options.endpoint.unwrapped;

        if (address.options.endpoint.literal)
          requestOptions.endpoint.literal = address.options.endpoint.literal;

        pca.fetch(
          address.options.endpoint.retrieve,
          params,
          success,
          fail,
          requestOptions
        );
      };

      /** Handles an error from the service.
       * @param {string} message - A description of the error.
       * @fires error
       * @throws The error. */
      address.error = function (message) {
        address.fire("error", message);

        pca.clearBlockingRequests();

        //if the error message is not handled throw it
        if (!address.listeners["error"]) {
          if (
            typeof console != "undefined" &&
            typeof console.error != "undefined"
          )
            console.error(
              pca.messages[address.language].SERVICEERROR + " " + message
            );
          else
            throw pca.messages[address.language].SERVICEERROR + " " + message;
        }
      };

      //clears any current prompt timer
      function clearPromptTimer() {
        if (address.lastActionTimer != null) {
          window.clearTimeout(address.lastActionTimer);
          address.lastActionTimer = null;
        }
      }

      /** Show search results in the list.
       * @param {Array.<Object>} results - The response from a service request.
       * @param {string} template - The format template for list items.
       * @fires results
       * @fires display */
      address.display = function (results, template, attributes) {
        address.autocomplete.header.hide();
        address.highlight(results);
        address.fire("results", results, attributes);
        address.autocomplete
          .clear()
          .add(results, template, address.select)
          .show();
        address.showFooterLogo();

        //add expandable class
        address.autocomplete.list.collection.all(function (item) {
          if (item.data && item.data.Type && item.data.Type !== "Address") {
            pca.addClass(item.element, "pcaexpandable");
            item.element.appendChild(
              pca.create("div", {
                className: "pca-visually-hidden",
                innerText: ", " + pca.messages[address.language].DRILLDOWN,
              })
            );
          }
        });

        //prompt the user for more detail
        if (address.options.prompt) {
          function showPromptMessage() {
            address.message(pca.messages[address.language].KEEPTYPING);
          }

          clearPromptTimer();

          if (address.options.promptDelay)
            address.lastActionTimer = window.setTimeout(
              showPromptMessage,
              address.options.promptDelay
            );
          else showPromptMessage();
        }

        //show the number of matching results
        if (
          address.options.matchCount &&
          attributes &&
          attributes.ContainerCount
        )
          address.resultCountMessage(attributes.ContainerCount);

        address.fire("display", results, attributes);
        return address;
      };

      /**
       * Message options.
       * @typedef {Object} pca.Address.MessageOptions
       * @property {number} [notificationTimeout] - The time in ms to show the notification for.
       * @property {boolean} [inline] - Show messages in the header of the list.
       * @property {boolean} [clearList] - Clears the list of results when showing this message.
       * @property {boolean} [clickToDisable] - Clicking the message will hide and disable the control.
       * @property {boolean} [error] - Apply the style class for an error message.
       */

      /** Shows a message in the autocomplete.
       * @param {string} text - The message to show.
       * @param {pca.Address.MessageOptions} messageOptions - Options for the message. */
      address.message = function (text, messageOptions) {
        messageOptions = messageOptions || {};
        messageOptions.notificationTimeout =
          messageOptions.notificationTimeout || 3000;
        messageOptions.inline =
          messageOptions.inline || address.options.inlineMessages;

        clearPromptTimer();

        if (messageOptions.inline) {
          address.autocomplete.show();

          if (messageOptions.clickToDisable)
            address.autocomplete.header
              .clear()
              .setContent(
                pca.create(
                  "div",
                  {
                    className: "pcamessage",
                    innerHTML: text,
                    onclick: address.manualEntry,
                  },
                  "cursor:pointer;"
                )
              )
              .show();
          else address.autocomplete.header.clear().setText(text).show();

          address.reposition();
        } else {
          address.messageBox.innerHTML = text;
          pca.addClass(address.messageBox, "pcavisible");

          pca.removeClass(address.messageBox, "pcaerror");
          if (messageOptions.error)
            pca.addClass(address.messageBox, "pcaerror");

          if (address.notifcationTimer)
            window.clearTimeout(address.notifcationTimer);
          pca.removeClass(address.messageBox, "pcafade");
          address.notifcationTimer = window.setTimeout(function () {
            pca.addClass(address.messageBox, "pcafade");
            window.setTimeout(function () {
              pca.removeClass(address.messageBox, "pcavisible");
            }, 500);
          }, messageOptions.notificationTimeout);

          var fieldPosition = pca.getPosition(address.autocomplete.field),
            fieldSize = pca.getSize(address.autocomplete.field),
            messageSize = pca.getSize(address.messageBox);

          address.messageBox.style.top =
            (address.autocomplete.upwards
              ? fieldPosition.top + fieldSize.height + 8
              : fieldPosition.top - messageSize.height - 8) + "px";
          address.messageBox.style.left = fieldPosition.left + "px";
        }

        if (messageOptions.clearList) address.autocomplete.clear().list.hide();

        return address;
      };

      // Show the no results message which can be clicked to disable searching.
      address.noResultsMessage = function () {
        address.reset();
        address.message(pca.messages[address.language].NORESULTS, {
          clickToDisable: address.options.manualEntry,
          error: true,
          clearList: true,
        });
        address.fire("noresults");
      };

      // Show the number of results possible
      address.resultCountMessage = function (count) {
        address.message(
          pca.formatLine(
            { count: count },
            pca.messages[address.language].RESULTCOUNT
          )
        );
      };

      /** Sets the value of current input field to prompt the user.
       * @param {string} text - The text to show.
       * @param {number} [position] - The index at which to set the carat. */
      address.setCursorText = function (text, position) {
        address.autocomplete.prompt(text, position);
        return address;
      };

      /** User has selected something, either an address or location.
       * @param {Object} suggestion - The selected item from a find service response. */
      address.select = function (suggestion) {
        function filterSearch() {
          var searchText = pca.getValue(address.autocomplete.field);

          if (address.options.setCursor) {
            searchText = pca.removeHtml(suggestion.Text).replace("...", "");
            address.setCursorText(
              searchText,
              suggestion.Cursor >= 0 ? suggestion.Cursor : null
            );
          } else {
            pca.setValue(
              address.autocomplete.field,
              searchText + " ",
              address.options.simulateReactEvents
            );
            address.autocomplete.field.focus();
          }

          address.searchContext = {
            id: suggestion.Id,
            text: suggestion.Text,
            search: searchText,
          };
          address.search(searchText);
        }

        if (suggestion.Type === "Address") {
          address.retrieve(suggestion.Id);
        } else if (suggestion.Type === "Utility") {
          var searchText = pca.getValue(address.autocomplete.field);
          address.utilitiesLookup(searchText);
        } else {
          filterSearch();
        }

        return address;
      };

      /** Adds highlights to suggestions
       * @param {Array.<Object>} suggestions - The response from the find service.
       * @param {string} [prefix=<b>] - The string to insert at the start of a highlight.
       * @param {string} [suffix=</b>] - The string to insert at the end of a highlight. */
      address.highlight = function (suggestions, prefix, suffix) {
        prefix = prefix || "<b>";
        suffix = suffix || "</b>";

        function applyHighlights(text, highlights) {
          for (var i = highlights.length - 1; i >= 0; i--) {
            var indexes = highlights[i].split("-");

            text =
              text.substring(0, parseInt(indexes[0])) +
              prefix +
              text.substring(parseInt(indexes[0]), parseInt(indexes[1])) +
              suffix +
              text.substring(parseInt(indexes[1]), text.length);
          }

          return text;
        }

        for (var s = 0; s < suggestions.length; s++) {
          var suggestion = suggestions[s];

          //initial values are all the same
          suggestion.HighlightedText =
            suggestion.title =
            suggestion.tag =
              suggestion.Text;
          suggestion.HighlightedDescription = suggestion.Description;

          //no highlight indexes
          if (!suggestion.Highlight) continue;

          var highlightParts = suggestion.Highlight.split(";");

          //main text highlights
          if (highlightParts.length > 0)
            suggestion.HighlightedText = applyHighlights(
              suggestion.HighlightedText,
              highlightParts[0].split(",")
            );

          //description text highlights
          if (highlightParts.length > 1)
            suggestion.HighlightedDescription = applyHighlights(
              suggestion.HighlightedDescription,
              highlightParts[1].split(",")
            );
        }
      };

      /** Populate the fields with the address result.
       * @param {Array.<Object>} response - A response from the retrieve service.
       * @fires prepopulate
       * @fires populate */
      address.populate = function (items) {
        var detail = items[0];

        //apply language preference
        if (address.options.languagePreference) {
          for (var i = 0; i < items.length; i++) {
            if (
              items[i].Language ===
              address.options.languagePreference.toUpperCase()
            ) {
              detail = items[i];
              break;
            }
          }
        }

        //set the current country
        address.setCountry(detail.CountryIso2);

        //pre populate country
        if (address.options.countries.prepopulate) {
        }
        address.countrylist.populate();

        //check the number of address lines defined
        var addressLineFields = {
            Line1: null,
            Line2: null,
            Line3: null,
            Line4: null,
            Line5: null,
            Street: null,
            Building: null,
            Company: null,
          },
          addressLineCount = 0;

        for (var f = 0; f < address.fields.length; f++) {
          for (var l in addressLineFields) {
            if (~address.fields[f].field.indexOf(l))
              addressLineFields[l] = address.fields[f];
          }
        }

        //replace with additional address line formats
        for (var la = 1; la <= 5; la++) {
          if (addressLineFields["Line" + la]) addressLineCount++;
        }

        if (addressLineFields.Building && addressLineFields.Street)
          addressLineCount++;

        //add additional formatted address lines
        for (var lb = 1; lb <= 5; lb++)
          detail["FormattedLine" + lb] = address.getAddressLine(
            detail,
            lb,
            addressLineCount,
            !addressLineFields.Company
          );

        address.fire("prepopulate", detail, items);

        //check and poplate the fields
        for (
          var a = 0;
          a < address.fields.length && address.options.populate;
          a++
        ) {
          var field = address.fields[a];

          //skip this field if it's not set to be populated
          if (!(field.mode & pca.fieldMode.POPULATE)) continue;

          //skip the field if it's not an input field and the onlyInputs option is set
          if (
            address.options.onlyInputs &&
            !(
              pca.inputField(field.element) ||
              pca.selectList(field.element) ||
              pca.checkBox(field.element)
            )
          )
            continue;

          //skip this field if it's in preserve mode, already had a value and is not the search field
          if (
            field.mode & pca.fieldMode.PRESERVE &&
            pca.getValue(field.element) &&
            address.autocomplete.field !== pca.getElement(field.element)
          )
            continue;

          //process format strings and/or field names
          var format = address.fields[a].field.replace(
              /(Formatted)?Line/g,
              "FormattedLine"
            ),
            value =
              /[\{\}]/.test(format) || format === ""
                ? pca.formatLine(detail, format)
                : detail[format];

          pca.setValue(
            field.element,
            value,
            address.options.simulateReactEvents
          );
        }

        address.hide();
        address.newSearch();
        address.fire("populate", detail, items, address.key);
        pca.read(pca.messages[address.language].POPULATED);
        if (address.autocomplete.field) {
          address.autocomplete.field.focus();
        }
        return address;
      };

      /** Returns a formatted address line from the address response.
       * @param {Object} details - The address as a response item from the retrieve service.
       * @param {number} lineNumber - The required address line number.
       * @param {number} lineTotal - The total number of lines required.
       * @param {boolean} includeCompany - Specifies whether to include the company name in the address.
       * @returns {string} The formatted address line. */
      address.getAddressLine = function (
        details,
        lineNumber,
        fieldCount,
        includeCompany
      ) {
        var addressLines,
          result = "";

        includeCompany = includeCompany && !!details.Company;

        if (includeCompany) {
          if (lineNumber === 1 && fieldCount > 1) return details.Company;

          if (lineNumber === 1 && fieldCount === 1) result = details.Company;
          else {
            lineNumber--;
            fieldCount--;
          }
        }

        if (!details.Line1) addressLines = 0;
        else if (!details.Line2) addressLines = 1;
        else if (!details.Line3) addressLines = 2;
        else if (!details.Line4) addressLines = 3;
        else if (!details.Line5) addressLines = 4;
        else addressLines = 5;

        //work out the first address line number to return and how many address elements should appear on it
        var firstLine =
            fieldCount >= addressLines
              ? lineNumber
              : Math.floor(
                  1 +
                    (addressLines / fieldCount +
                      (fieldCount - (lineNumber - 1)) / fieldCount) *
                      (lineNumber - 1)
                ),
          numberOfLines = Math.floor(
            addressLines / fieldCount + (fieldCount - lineNumber) / fieldCount
          );

        //concatenate the address elements to make the address line
        for (var a = 0; a < numberOfLines; a++)
          result +=
            (result ? ", " : "") + (details["Line" + (a + firstLine)] || "");

        return result;
      };

      /** Switches to the country list. */
      address.switchToCountrySelect = function () {
        if (address.inCountryListMode) {
          return address.switchToSearchMode();
        }
        var countryDescription =
          address.autocomplete.footer.element.querySelector(
            "#pca-country-button-help-text"
          );
        if (countryDescription) {
          var country = pca.countries.find(function (c) {
            return c.iso3 == address.country;
          });
          countryDescription.innerHTML = pca.formatLine(
            {
              country: country
                ? address.language === "fr"
                  ? country.name_fr
                  : country.name
                : "",
            },
            pca.messages[address.language].INCOUNTRYHELP
          );
        }
        address.countrylist.autocomplete.position(address.autocomplete.field);
        address.countrylist.autocomplete.field = address.autocomplete.field;
        address.countrylist.autocomplete.focused = true;
        address.countrylist.autocomplete.enable().showAll();
        address.countrylist.autocomplete.list.first();
        address.autocomplete.disable().hide();
        address.inCountryListMode = true;

        //store the state of the search mode
        address.storedSearch = pca.getValue(address.autocomplete.field);
        pca.clear(address.autocomplete.field);
        address.autocomplete.field.focus();
      };

      /** Switches back to the default search list. */
      address.switchToSearchMode = function () {
        var searchAfter = address.storedSearch != null;

        var countryDescription =
          address.autocomplete.footer.element.querySelector(
            "#pca-country-button-help-text"
          );

        if (countryDescription) {
          var country = pca.countries.find(function (c) {
            return c.iso3 == address.country;
          });
          countryDescription.innerHTML = countryDescription.innerHTML =
            pca.formatLine(
              {
                country: country
                  ? address.language === "fr"
                    ? country.name_fr
                    : country.name
                  : "",
              },
              pca.messages[address.language].COUNTRYHELP
            );
        }

        address.countrylist.autocomplete.hide();
        address.autocomplete.enable();

        if (searchAfter) {
          address.newSearch();
          address.autocomplete.field.focus();
          address.searchFromField();
        }
      };

      address.startGeoLocation = function () {
        if (pca.supports("reverseGeo")) {
          var nav = window.navigator;
          pca.addClass(address.geocodeButton, "working");
          nav.geolocation.getCurrentPosition(
            function (position) {
              address.geolocation = position;
              address.geolocationLookup(address.geolocation);
            },
            function (error) {
              pca.removeClass(address.geocodeButton, "working");
              address.error(error.message);
            },
            {
              timeout: 5000,
            }
          );
        } else {
          //browser not able to do geo location
          //TODO - handle error gracefully
          address.error("Location data is not supported in this browser.");
        }
      };

      /** Sets the country for searching.
       * @param {string} country - The country name or code to change to. */
      address.setCountry = function (country) {
        address.countrylist.setCountry(country);
        return address;
      };

      /** Sets the country based on the current client IP. */
      address.setCountryByIP = function () {
        address.countrylist.setCountryByIP(address.key);
        return address;
      };

      /** Alters attributes on an element to try and prevent autocomplete */
      address.preventAutocomplete = function (element) {
        if (element) {
          var isSet = false;
          if (pca.browser && pca.browser.name) {
            switch (pca.browser.name) {
              case "Chrome":
                if (
                  pca.browser.version &&
                  !isNaN(Number(pca.browser.version))
                ) {
                  var version = Number(pca.browser.version);
                  if (version === 63 || (version >= 80 && version < 90)) {
                    element.autocomplete = "pca-override";
                    isSet = true;
                  }
                }
                break;
            }
          }
          if (!isSet) {
            element.autocomplete = "off";
          }
        }
      };

      /** Detects the browser culture. */
      address.detectLanguage = function () {
        var culture = address.options.culture;
        var searchLanguage = address.options.search.language;

        if (culture !== searchLanguage) {
          culture =
            (window && window.navigator
              ? window.navigator.language || window.navigator.browserLanguage
              : "") || "";
        }

        address.language =
          culture && culture.length > 1
            ? culture.substring(0, 2).toLowerCase()
            : "en";

        if (!pca.messages[address.language]) address.language = "en";
      };

      /** Sets the control culture.
       * @param {string} culture - The culture code to set. */
      address.setCulture = function (culture) {
        address.options.culture = culture;
        address.reload();
      };

      /** Sets the width of the control.
       * @param {number|string} width - The width in pixels for the control. */
      address.setWidth = function (width) {
        address.autocomplete.setWidth(width);
        address.countrylist.autocomplete.setWidth(width);
      };

      /** Sets the height of the control.
       * @param {number|string} height - The height in pixels for the control. */
      address.setHeight = function (height) {
        address.autocomplete.setHeight(height);
        address.countrylist.autocomplete.setHeight(height);
      };

      /** Clear the address fields.
       * @fires clear */
      address.clear = function () {
        for (var a = 0; a < address.fields.length; a++)
          pca.setValue(
            address.fields[a].element,
            "",
            address.options.simulateReactEvents
          );

        address.fire("clear");
        return address;
      };

      /** Reset the control back to it's initial state. */
      address.reset = function () {
        if (address.options.bar.visible) {
          address.autocomplete.list.clear().hide();
          address.autocomplete.header.hide();
          address.showFooterMessage();
          address.autocomplete.reposition();
        } else {
          address.autocomplete.hide();
          address.autocomplete.footer.hide();
        }

        clearPromptTimer();
        address.newSearch();
        return address;
      };

      //tell the control to begin a fresh search
      address.newSearch = function () {
        address.initialSearch = false;
        address.searchContext = null;
      };

      /** Address control has focus.
       * @fires focus */
      address.focus = function () {
        address.reset();

        if (address.options.autoSearch) address.searchFromField();

        address.fire("focus");
      };

      /** Hides the address control.
       * @fires hide */
      address.hide = function () {
        clearPromptTimer();

        address.autocomplete.hide();
        address.countrylist.autocomplete.hide();

        address.fire("hide");
      };

      /** Return the visible state of the control.
       * @returns {boolean} True if the control is visible. */
      address.visible = function () {
        return (
          address.autocomplete.visible ||
          address.countrylist.autocomplete.visible
        );
      };

      /** Repositions the address control. */
      address.reposition = function () {
        address.autocomplete.reposition();
        address.countrylist.autocomplete.reposition();
      };

      /** Disables the address control. */
      address.disable = function () {
        address.autocomplete.disabled = true;
        address.countrylist.autocomplete.disabled = true;
        return address;
      };

      /** Enables the address control after being disabled. */
      address.enable = function () {
        address.autocomplete.disabled = false;
        address.countrylist.autocomplete.disabled = false;
        return address;
      };

      /** Permanently removes the address control elements and event listeners from the page. */
      address.destroy = function () {
        if (address.autocomplete) address.autocomplete.destroy();
        if (address.countrylist) address.countrylist.autocomplete.destroy();
        return address;
      };

      /** Reloads the address control */
      address.reload = function () {
        address.destroy();
        address.load();
      };

      /** Disables the control to allow for manual address entry. */
      address.manualEntry = function () {
        if (window && window.setTimeout && address.options.disableTime) {
          address.autocomplete.field.focus();
          address.destroy();

          window.setTimeout(address.load, address.options.disableTime);

          address.fire("manual");
        }

        return address;
      };

      /** Adds a permanent item to the bottom of the list to enable manual address entry.
       * @param {string} [message] - The text to display. */
      address.addManualEntryItem = function (message) {
        message = message || pca.messages[address.language].MANUALENTRY;
        address.autocomplete.list.setFooterItem(
          { text: message },
          "<u>{text}</u>",
          address.manualEntry
        );
      };

      /** Checks whether the control is bound to a particular element.
       * @param {string|HTMLElement} element - The element or element id to check for.
       * @returns {boolean} True if the control is bound to that element. */
      address.bound = function (element) {
        if ((element = pca.getElement(element))) {
          for (var f = 0; f < address.fields.length; f++) {
            if (element == pca.getElement(fields[f].element)) return true;
          }
        }

        return false;
      };

      /** Checks a format string for non-standard fields.
       * @param {string} format - The address line format string to check.
       * @returns {string} The standardised format string. */
      address.checkFormat = function (format) {
        function standardField(field) {
          for (var i in pca.exampleAddress) {
            if (i === field) return true;
          }

          return false;
        }

        return format.replace(/\{(\w+)([^\}\w])?\}/g, function (m, c) {
          if (!standardField(c)) {
            address.advancedFields.push(m);
            return "{Field" + address.advancedFields.length + "}";
          }

          return m;
        });
      };

      /* Preload images that are to be used in the css. */
      function preloadImage(url) {
        var img = new Image();
        img.src = url;
      }

      preloadImage(
        "//" + pca.host + "/images/icons/captureplus/loqatelogoinverted.svg"
      );
      preloadImage(
        "//" + pca.host + "/images/icons/captureplus/geolocationicon.svg"
      );
      preloadImage("//" + pca.host + "/images/icons/captureplus/loader.gif");
      preloadImage("//" + pca.host + "/images/icons/captureplus/chevron.png");

      //only load when the page is ready
      pca.ready(address.load);
    };

})();
