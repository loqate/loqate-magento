/**
 * @fileoverview PCA SDK – localised UI string catalogue.
 *
 * Defines `pca.messages`, an object keyed by locale code containing every
 * user-visible string rendered by the PCA SDK.  Currently supports:
 * `en` (English), `cy` (Welsh), `fr` (French), `de` (German).
 *
 * The active locale is selected at runtime by {@link pca.Address} based on
 * `options.culture` or the browser's default language.
 *
 * Depends on: {@link module:pca/core}.
 *
 * @module pca/messages
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function () {
  var pca = (window.pca = window.pca || {});

    pca.messages = {
      en: {
        DIDYOUMEAN: "Did you mean:",
        NORESULTS: "No results found",
        KEEPTYPING: "Keep typing your address to display more results",
        RETRIEVEERROR: "Sorry, we could not retrieve this address",
        SERVICEERROR: "Service Error:",
        COUNTRYSELECT: "Change Country",
        NOLOCATION: "Sorry, we could not get your location",
        NOCOUNTRY: "Sorry, we could not find this country",
        MANUALENTRY: "I cannot find my address. Let me type it in",
        RESULTCOUNT: "<b>{count}</b> matching results",
        GEOLOCATION: "Use my Location",
        COUNTRYHELP:
          "The selected country is {country}. Press control and shift and C to change the selected country.",
        INCOUNTRYHELP:
          "You are in the country select menu. The selected country is {country}. Press control and shift and C to return to the address search.",
        DRILLDOWN:
          "There are multiple address options at this location, select this location to expand the address options.",
        POPULATED: "Address has populated",
        ADDRESSAVAILABLE: "address available",
        ADDRESSESAVAILABLE: "addresses available",
        COUNTRYAVAILABLE: "country available",
        COUNTRIESAVAILABLE: "countries available",
        ADDRESSLIST: "address list",
      },
      cy: {
        DIDYOUMEAN: "A oeddech yn meddwl:",
        NORESULTS: "Dim canlyniadau ar ganlyniadau",
        KEEPTYPING:
          "Cadwch teipio eich cyfeiriad i arddangos mwy o ganlyniadau",
        RETRIEVEERROR: "Mae'n ddrwg gennym, ni allem adfer y cyfeiriad hwn",
        SERVICEERROR: "Gwall gwasanaeth:",
        COUNTRYSELECT: "Dewiswch gwlad",
        NOLOCATION:
          "Mae'n ddrwg gennym, nid oeddem yn gallu cael eich lleoliad",
        NOCOUNTRY: "Mae'n ddrwg gennym, ni allem ddod o hyd y wlad hon",
        MANUALENTRY:
          "Ni allaf ddod o hyd i fy nghyfeiriad. Gadewch i mi deipio mewn",
        RESULTCOUNT: "<b>{count}</b> Canlyniadau paru",
        GEOLOCATION: "Defnyddiwch fy Lleoliad",
        COUNTRYHELP:
          "Y wlad a ddewiswyd yw {country}. Pwyswch reolaeth a shifft a C i newid y wlad a ddewiswyd.",
        INCOUNTRYHELP:
          "Rydych chi yn y ddewislen dewis gwlad. Y wlad a ddewiswyd yw {country}. Pwyswch control a shifft a C i ddychwelyd i'r chwiliad cyfeiriad.",
        DRILLDOWN:
          "Mae sawl opsiwn cyfeiriad yn y lleoliad hwn, dewiswch y lleoliad hwn i ehangu'r opsiynau cyfeiriad.",
        POPULATED: "Mae'r cyfeiriad wedi poblogi",
        ADDRESSAVAILABLE: "cyfeiriad ar gael",
        ADDRESSESAVAILABLE: "gyfeiriad ar gael",
        COUNTRYAVAILABLE: "gwlad ar gael",
        COUNTRIESAVAILABLE: "gwledydd ar gael",
        ADDRESSLIST: "rhestr cyfeiriadau",
      },
      fr: {
        DIDYOUMEAN: "Vouliez-vous dire:",
        NORESULTS: "Aucun résultat n'a été trouvé",
        KEEPTYPING:
          "Continuer à taper votre adresse pour afficher plus de résultats",
        RETRIEVEERROR: "Désolé , nous ne pouvions pas récupérer cette adresse",
        SERVICEERROR: "Erreur de service:",
        COUNTRYSELECT: "Changer de pays",
        NOLOCATION: "Désolé, nous n'avons pas pu obtenir votre emplacement",
        NOCOUNTRY: "Désolé, nous n'avons pas trouvé ce pays",
        MANUALENTRY:
          "Je ne peux pas trouver mon adresse. Permettez-moi de taper dans",
        RESULTCOUNT: "<b>{count}</b> résultats correspondants",
        GEOLOCATION: "Utiliser ma position",
        COUNTRYHELP:
          "Le pays sélectionné est {country}. Appuyez sur Ctrl et Maj et C pour changer le pays sélectionné.",
        INCOUNTRYHELP:
          "Vous êtes dans le menu de sélection du pays. Le pays sélectionné est {country}. Appuyez sur Ctrl et Maj et sur C pour revenir à la recherche d'adresse.",
        DRILLDOWN:
          "Il existe plusieurs options d'adresse à cet emplacement, sélectionnez cet emplacement pour développer les options d'adresse.",
        POPULATED: "L'adresse a été remplie",
        ADDRESSAVAILABLE: "adresse disponible",
        ADDRESSESAVAILABLE: "adresses disponibles",
        COUNTRYAVAILABLE: "pays disponible",
        COUNTRIESAVAILABLE: "pays disponibles",
        ADDRESSLIST: "liste d'adresses",
      },
      de: {
        DIDYOUMEAN: "Meinten Sie:",
        NORESULTS: "Keine Adressen gefunden",
        KEEPTYPING:
          "Geben Sie mehr von Ihrer Adresse ein, um weitere Ergebnisse anzuzeigen",
        RETRIEVEERROR: "Wir konnten diese Adresse leider nicht abrufen",
        SERVICEERROR: "Service-Fehler:",
        COUNTRYSELECT: "Land wechseln",
        NOLOCATION: "Wir konnten Ihren Standort leider nicht finden",
        NOCOUNTRY: "Wir konnten dieses Land leider nicht finden",
        MANUALENTRY:
          "Ich kann meine Adresse nicht finden. Lassen Sie mich es manuell eingeben",
        RESULTCOUNT: "<b>{count}</b> passenden Ergebnisse",
        GEOLOCATION: "Meinen Standort verwenden",
        COUNTRYHELP:
          "Das ausgewählte Land ist {country}. Drücken Sie Strg und Umschalt und C, um das ausgewählte Land zu ändern.",
        INCOUNTRYHELP:
          "Sie befinden sich im Länderauswahlmenü. Das ausgewählte Land ist {country}. Drücken Sie Strg und Umschalttaste und C, um zur Adresssuche zurückzukehren.",
        DRILLDOWN:
          "An diesem Speicherort gibt es mehrere Adressoptionen. Wählen Sie diesen Speicherort aus, um die Adressoptionen zu erweitern.",
        POPULATED: "Adresse wurde ausgefüllt",
        ADDRESSAVAILABLE: "Adresse verfügbar",
        ADDRESSESAVAILABLE: "Adressen verfügbar",
        COUNTRYAVAILABLE: "Land verfügbar",
        COUNTRIESAVAILABLE: "Länder verfügbar",
        ADDRESSLIST: "Adressliste",
      },
    };

    /** An example retrieve response.
     * @memberof pca */

})();
