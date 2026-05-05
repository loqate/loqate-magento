/**
 * @fileoverview PCA SDK – pca.CountryList: flag-icon country autocomplete.
 *
 * Builds a {@link pca.AutoComplete} dropdown populated from {@link pca.countries}.
 * Supports an optional `codesList` to surface preferred countries first, flag
 * sprite icons, language switching (English / French), and geolocation-based
 * default-country detection.
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/countries/data},
 * {@link module:pca/ui/autocomplete}.
 *
 * @module pca/countries/list
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function () {
  var pca = (window.pca = window.pca || {});

    pca.countryNameType = {
      /** The full country name */
      NAME: 0,
      /** The ISO 2-char code, e.g. GB */
      ISO2: 1,
      /** The ISO 3-char code, e.g. GBR */
      ISO3: 2,
    };

    /**
     * Country List options.
     * @typedef {Object} pca.CountryList.Options
     * @property {string} [defaultCode] - The default country as an ISO 3-char code.
     * @property {string} [fallbackCode] - The default country as an ISO 3-char code in the case that the defaultCode is not present in the list.
     * @property {string} [value] - The initial value.
     * @property {string} [codesList] - A comma separated list of ISO 2-char or 3-char country codes for the basis of the list.
     * @property {boolean} [fillOthers=true] - If a codesList is provided, any remaining countries will be appended to the bottom of the list.
     * @property {prepopulate} [fillOthers=true] - When the country is changed, any fields will be populated.
     * @property {string} [nameLanguage=en] - The language for country names, only en and fr are supported.
     * @property {pca.countryNameType} [nameType=NAME] - The text format of the country name for populating an input field.
     * @property {pca.countryNameType} [valueType=ISO3] - The value format of a country option for populating a select list.
     */

    /** Creates an autocomplete list with country options.
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {Array.<HTMLElement>} fields - A list of input elements to bind to.
     * @param {pca.CountryList.Options} [options] - Additional options to apply to the list.
     */
    pca.CountryList = function (fields, options) {
      /** @lends pca.CountryList.prototype */
      var countrylist = new pca.Eventable(this);

      /** The current country fields
       * @type {Array.<HTMLElement>} */
      countrylist.fields = fields || [];
      /** The current country fields
       * @type {Array.<Object>} */
      countrylist.options = options || {};

      //parse the options
      countrylist.options.defaultCode = countrylist.options.defaultCode || "";
      countrylist.options.value = countrylist.options.value || "";
      countrylist.options.codesList = countrylist.options.codesList || "";
      countrylist.options.fillOthers = countrylist.options.fillOthers || false;
      countrylist.options.list = countrylist.options.list || {};
      countrylist.options.populate =
        typeof countrylist.options.populate == "boolean"
          ? countrylist.options.populate
          : true;
      countrylist.options.prepopulate =
        typeof countrylist.options.prepopulate == "boolean"
          ? countrylist.options.prepopulate
          : true;
      countrylist.options.language = countrylist.options.language || "en";
      countrylist.options.nameType =
        countrylist.options.nameType || pca.countryNameType.NAME;
      countrylist.options.valueType =
        countrylist.options.valueType || pca.countryNameType.NAME;
      countrylist.options.fallbackCode =
        countrylist.options.fallbackCode || "GBR";
      countrylist.options.list.type = "countrylist";

      /** The list
       * @type {pca.AutoComplete} */
      countrylist.autocomplete = new pca.AutoComplete(
        countrylist.fields,
        countrylist.options.list
      );
      /** The current country
       * @type {pca.Country} */
      countrylist.country = null;
      countrylist.textChanged = false;
      countrylist.nameProperty =
        countrylist.options.language === "fr" ? "name_fr" : "name";
      countrylist.template =
        "<div class='pcaflag'></div><div class='pcaflaglabel'>{" +
        countrylist.nameProperty +
        "}</div>";

      countrylist.load = function () {
        pca.addClass(countrylist.autocomplete.element, "pcacountrylist");

        //country has been selected
        function selectCountry(country) {
          countrylist.change(country);
          countrylist.fire("select", country);
        }

        //add countries to the list
        if (countrylist.options.codesList) {
          var codesSplit = countrylist.options.codesList
              .replace(/\s/g, "")
              .split(","),
            filteredList = [];

          countrylist.autocomplete.clear();

          for (var i = 0; i < codesSplit.length; i++) {
            var code = codesSplit[i].toString().toUpperCase();

            for (var c = 0; c < pca.countries.length; c++) {
              if (
                pca.countries[c].iso2 === code ||
                pca.countries[c].iso3 === code
              ) {
                filteredList.push(pca.countries[c]);
                break;
              }
            }
          }

          if (countrylist.options.fillOthers) {
            for (var o = 0; o < pca.countries.length; o++) {
              var contains = false;

              for (var f = 0; f < filteredList.length; f++) {
                if (pca.countries[o].iso3 === filteredList[f].iso3)
                  contains = true;
              }

              if (!contains) filteredList.push(pca.countries[o]);
            }
          }

          countrylist.autocomplete
            .clear()
            .add(filteredList, countrylist.template, selectCountry);
        } else
          countrylist.autocomplete
            .clear()
            .add(pca.countries, countrylist.template, selectCountry);

        //set flags and add alternate filter tags to each country
        countrylist.autocomplete.list.collection.all(function (item) {
          countrylist.setFlagPosition(item.element.firstChild, item.data.flag);
          item.tag +=
            " " +
            pca.formatTag(
              item.data.iso3 +
                (item.data.alternates
                  ? " " + item.data.alternates.join(" ")
                  : "")
            );
        });

        //always show the full list to begin with
        countrylist.autocomplete.listen("focus", function () {
          countrylist.autocomplete.showAll();
        });

        //user has changed country on the form
        function textChanged(field) {
          //for a select list we should try the value and label
          if (pca.selectList(field)) {
            var selected = pca.getSelectedItem(field);
            countrylist.change(
              countrylist.find(selected.value) ||
                countrylist.find(selected.text)
            );
          } else countrylist.setCountry(pca.getValue(field));

          countrylist.textChanged = false;
        }

        //automatically set the country when the field value is changed
        countrylist.autocomplete.listen("change", function (field) {
          countrylist.autocomplete.visible
            ? (countrylist.textChanged = true)
            : textChanged(field);
        });

        countrylist.autocomplete.listen("hide", function () {
          if (countrylist.textChanged)
            textChanged(countrylist.autocomplete.field);
        });

        //set the initial value
        if (countrylist.options.value)
          countrylist.country = countrylist.find(countrylist.options.value);
        if (!countrylist.country && countrylist.options.defaultCode)
          countrylist.country = countrylist.find(
            countrylist.options.defaultCode
          );

        //use the fallback or first in the list
        countrylist.country =
          countrylist.country ||
          (countrylist.options.codesList
            ? countrylist.first()
            : countrylist.find(countrylist.options.fallbackCode)) ||
          countrylist.first() ||
          countrylist.find(countrylist.options.fallbackCode);

        countrylist.fire("load");
      };

      /** Returns the name of the country with the current nameType option.
       * @param {pca.Country} [country] - The country object to get the desired name of. */
      countrylist.getName = function (country) {
        switch (countrylist.options.nameType) {
          case pca.countryNameType.NAME:
            return (country || countrylist.country)[countrylist.nameProperty];
          case pca.countryNameType.ISO2:
            return (country || countrylist.country).iso2;
          case pca.countryNameType.ISO3:
            return (country || countrylist.country).iso3;
        }

        return (country || countrylist.country)[countrylist.nameProperty];
      };

      /** Returns the value of the country with the current valueType option.
       * @param {pca.Country} [country] - The country object to get the desired value of. */
      countrylist.getValue = function (country) {
        switch (countrylist.options.valueType) {
          case pca.countryNameType.NAME:
            return (country || countrylist.country)[countrylist.nameProperty];
          case pca.countryNameType.ISO2:
            return (country || countrylist.country).iso2;
          case pca.countryNameType.ISO3:
            return (country || countrylist.country).iso3;
        }

        return (country || countrylist.country).iso3;
      };

      /** Populates all bound country fields.
       * @fires populate */
      countrylist.populate = function () {
        if (!countrylist.options.populate) return;

        var name = countrylist.getName(),
          value = countrylist.getValue();

        for (var i = 0; i < countrylist.fields.length; i++) {
          var countryField = pca.getElement(countrylist.fields[i]),
            currentValue = pca.getValue(countryField);

          pca.setValue(
            countryField,
            pca.selectList(countryField) ? value : name
          );

          if (
            countrylist.options.prepopulate &&
            currentValue !== pca.getValue(countryField)
          )
            pca.fire(countryField, "change");
        }

        countrylist.fire("populate");
      };

      /** Finds a matching country from a name or code.
       * @param {string} country - The country name or code to find.
       * @returns {pca.Country} The country object. */
      countrylist.find = function (country) {
        country = country.toString().toUpperCase();

        function isAlternate(item) {
          if (item.data.alternates) {
            for (var a = 0; a < item.data.alternates.length; a++) {
              if (item.data.alternates[a].toUpperCase() === country)
                return true;
            }
          }

          return false;
        }

        return (
          countrylist.autocomplete.list.collection.first(function (item) {
            return (
              item.data.iso2.toUpperCase() === country ||
              item.data.iso3.toUpperCase() === country ||
              item.data.name.toUpperCase() === country ||
              item.data.name_fr.toUpperCase() === country ||
              isAlternate(item)
            );
          }) || {}
        ).data;
      };

      /** Returns the first country in the list.
       * @returns {pca.Country} The first country object. */
      countrylist.first = function () {
        return countrylist.autocomplete.list.collection.first().data;
      };

      /** Country has been selected.
       * @param {pca.Country} country - The country to change to.
       * @fires change */
      countrylist.change = function (country, isByIp) {
        isByIp = typeof isByIp == "undefined" ? false : true;
        if (country) {
          countrylist.country = country;
          countrylist.populate();
          countrylist.textChanged = false;
          countrylist.fire("change", countrylist.country, isByIp);
        }
      };

      /** Sets the index of a flag icon element.
       * @param {HTMLElement} element - The flag icon element to change.
       * @param {number} index - The country flag icon index. */
      countrylist.setFlagPosition = function (element, index) {
        element.style.backgroundPosition = "-1px -" + (index * 16 + 2) + "px";
      };

      /** Creates a dynamic flag icon.
       * @returns {HTMLDivElement} A dynamic HTML DIV showing the flag as an icon. */
      countrylist.flag = function () {
        var flag = pca.create("div", { className: "pcaflag" });

        function updateFlag(country) {
          countrylist.setFlagPosition(flag, country.flag);
        }

        updateFlag(countrylist.country);
        countrylist.listen("change", updateFlag);

        return flag;
      };

      /** Sets the country
       * @param {string} country - The country name or code to change to. */
      countrylist.setCountry = function (country, isByIp) {
        isByIp = typeof isByIp == "undefined" ? false : isByIp;
        countrylist.change(countrylist.find(country), isByIp);
        return countrylist;
      };

      /** Sets the country based on the current client IP.
       * @param {string} key - A license key for the request. */
      countrylist.setCountryByIP = function (key) {
        function success(response) {
          if (response.length && response[0].Iso3)
            countrylist.setCountry(response[0].Iso3, true);
        }

        if (key)
          pca.fetch("Extras/Web/Ip2Country/v1.10", { Key: key }, success);
      };

      countrylist.load();
    };

})();
