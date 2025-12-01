requirejs(["jquery", "mage/url", "domReady"], function ($, urlBuilder) {
  /* Based on v3.99 (custom changes have been made and are currently awaiting to be backported into the SDK 06/08/2025) - Updates toolkit to 3.94 and introduces option to simulate react event */

  /*
    Custom changes:
    - Added new configurables to the options object:
        - options.endpoint.literal - defines whether to use the `options.endpoint.find`/`options.endpoint.retrieve` endpoints directly
        - options.endpoint.find - the find endpoint to use
        - options.endpoint.retrieve - the retrieve endpoint to use
        - options.endpoint.unwrapped - whether to assume the response from the endpoints will be unwrapped (i.e not in the `Items` array)
    - Updated the `pca.fetch` method to pass through an options object, this is necessary for the unwrapped option to be toggleable
    - Updated event logic to init the 'change' event for text inputs (see commit #6e3fa015c69f657572009a578ea15f1efbe09af3)
  */

  /*! Copyright © 2009-2025 Postcode Anywhere (Holdings) Ltd. (http://www.postcodeanywhere.co.uk)
   *
   * Address v3.99
   * Address capture script file
   *
   * p-w-e-w1-4-ls3d 04/08/2025 13:24:47
   */
  /*3.94 - Adds the ability to simular a react event on setvalue /*
  /** @namespace pca */
  (function (window, undefined) {
    var pca = (window.pca = window.pca || {}),
      document = window.document;

    //Apply some sensible defaults in case we need to run outside of a browser
    if (typeof document == "undefined") document = { location: {} };
    if (typeof window.location == "undefined") window.location = {};
    if (typeof window.navigator == "undefined") window.navigator = {};

    //Service target information
    pca.protocol = "https:";
    pca.host = "services.postcodeanywhere.co.uk";
    pca.endpoint = "json3ex.ws";
    pca.limit = 2000;
    pca.sourceString = pca.sourceString || "PCA-SCRIPT";

    //Synonyms for list filtering.
    //Only need to replace things at the start of item text.
    pca.synonyms = pca.synonyms || [
      { r: /\bN(?=\s)/, w: "NORTH" },
      { r: /\b(?:NE|NORTHEAST)(?=\s)/, w: "NORTH EAST" },
      { r: /\b(?:NW|NORTHWEST)(?=\s)/, w: "NORTH WEST" },
      { r: /\bS(?=\s)/, w: "SOUTH" },
      { r: /\b(?:SE|SOUTHEAST)(?=\s)/, w: "SOUTH EAST" },
      { r: /\b(?:SW|SOUTHWEST)(?=\s)/, w: "SOUTH WEST" },
      { r: /\bE(?=\s)/, w: "EAST" },
      { r: /\bW(?=\s)/, w: "WEST" },
      { r: /\bST(?=\s)/, w: "SAINT" },
    ];

    //Basic diacritic replacements.
    pca.diacritics = pca.diacritics || [
      { r: /[ÀÁÂÃ]/gi, w: "A" },
      { r: /Å/gi, w: "AA" },
      { r: /[ÆæÄ]/gi, w: "AE" },
      { r: /Ç/gi, w: "C" },
      { r: /Ð/gi, w: "DJ" },
      { r: /[ÈÉÊË]/gi, w: "E" },
      { r: /[ÌÍÏ]/gi, w: "I" },
      { r: /Ñ/gi, w: "N" },
      { r: /[ÒÓÔÕ]/gi, w: "O" },
      { r: /[ŒØÖ]/gi, w: "OE" },
      { r: /Š/gi, w: "SH" },
      { r: /ß/gi, w: "SS" },
      { r: /[ÙÚÛ]/gi, w: "U" },
      { r: /Ü/gi, w: "UE" },
      { r: /[ŸÝ]/gi, w: "ZH" },
      { r: /-/gi, w: " " },
      { r: /[.,]/gi, w: "" },
    ];

    //HTML encoded character replacements.
    pca.hypertext = pca.hypertext || [
      { r: /&/g, w: "&amp;" },
      { r: /"/g, w: "&quot;" },
      { r: /'/g, w: "&#39;" },
      { r: /</g, w: "&lt;" },
      { r: />/g, w: "&gt;" },
    ];

    //Current service requests.
    //pca.requests = [];
    pca.requestQueue = pca.requestQueue || [];
    pca.requestCache = pca.requestCache || {};
    pca.scriptRequests = pca.scriptRequests || [];
    pca.waitingRequest = pca.waitingRequest || false;
    pca.blockRequests = pca.blockRequests || false;

    //Current style fixes.
    pca.styleFixes = pca.styleFixes || [];
    pca.agent =
      pca.agent || (window.navigator && window.navigator.userAgent) || "";
    //mousedown issue with older galaxy devices with stock browser
    pca.galaxyFix =
      pca.galaxyFix ||
      (/Safari\/534.30/.test(pca.agent) &&
        /GT-I8190|GT-I9100|GT-I9305|GT-P3110/.test(pca.agent));

    //Container for page elements.
    pca.container = pca.container || null;

    //store local reference to XHR
    pca.XMLHttpRequest = pca.XMLHttpRequest || window.XMLHttpRequest;

    //Ready state.
    var ready = false,
      readyList = [];

    /** Allows regex matching on field IDs.
     * @memberof pca */
    pca.fuzzyMatch =
      typeof pca.fuzzyMatch === "undefined" ? true : pca.fuzzyMatch;

    /** HTML element tag types to check when fuzzy matching.
     * @memberof pca */
    pca.fuzzyTags = pca.fuzzyTags || ["*"];

    /** Called when document is ready.
     * @memberof pca
     * @param {function} delegate - a function to call when the document is ready. */
    pca.ready =
      pca.ready ||
      function (delegate) {
        if (ready) {
          //process waiting handlers first
          if (readyList.length) {
            var handlers = readyList;

            readyList = [];

            for (var i = 0; i < handlers.length; i++) handlers[i]();
          }

          if (delegate) delegate();
        } else if (typeof delegate == "function") readyList.push(delegate);
      };

    //Checks document load.
    function documentLoaded() {
      if (document.addEventListener) {
        pca.ignore(document, "DOMContentLoaded", documentLoaded);
        ready = true;
        pca.ready();
      } else if (document.readyState === "complete") {
        pca.ignore(document, "onreadystatechange", documentLoaded);
        ready = true;
        pca.ready();
      }
    }

    //Listen for document load.
    function checkDocumentLoad() {
      if (document.readyState === "complete") {
        ready = true;
        pca.ready();
      } else {
        if (document.addEventListener)
          pca.listen(document, "DOMContentLoaded", documentLoaded);
        else pca.listen(document, "onreadystatechange", documentLoaded);
        pca.listen(window, "load", documentLoaded);
      }
    }

    /** Provides methods for event handling.
     * @memberof pca
     * @constructor
     * @mixin
     * @param {Object} [source] - The base object to inherit from. */
    pca.Eventable =
      pca.Eventable ||
      function (source) {
        /** @lends pca.Eventable.prototype */
        var obj = source || this;

        /** The list of listener for the object. */
        obj.listeners = {};

        /** Listen to a PCA event.
         * @param {string} event - The name of the even to listen for.
         * @param {pca.Eventable~eventHandler} action - The handler to add.
         */
        obj.listen = function (event, action) {
          obj.listeners[event] = obj.listeners[event] || [];
          obj.listeners[event].push(action);
        };

        /** Ignore a PCA event.
         * @param {string} event - The name of the even to ignore.
         * @param {pca.Eventable~eventHandler} action - The handler to remove.
         */
        obj.ignore = function (event, action) {
          if (obj.listeners[event]) {
            for (var i = 0; i < obj.listeners[event].length; i++) {
              if (obj.listeners[event][i] === action) {
                obj.listeners[event].splice(i, 1);
                break;
              }
            }
          }
        };

        /** Fire a PCA event. Can take any number of additional parameters and pass them on to the listeners.
         * @param {string} event - The name of the event to fire.
         * @param {...*} data - The detail of the event. */
        obj.fire = function (event, data) {
          if (obj.listeners[event]) {
            for (var i = 0; i < obj.listeners[event].length; i++) {
              var args = [data];

              for (var a = 2; a < arguments.length; a++)
                args.push(arguments[a]);

              obj.listeners[event][i].apply(obj, args);
            }
          }
        };

        return obj;

        /** Callback for a successful request.
         * @callback pca.Eventable~eventHandler
         * @param {...*} data - The detail of the event. */
      };

    ///Makes a service request using a XMLHttpRequest POST method.
    function postRequestXHR(request) {
      var xhr = new pca.XMLHttpRequest();

      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200)
          request.callback(pca.parseJSON(xhr.responseText));
      };

      if (request.credentials) xhr.withCredentials = request.credentials;

      xhr.onerror = request.serviceError;
      xhr.ontimeout = request.timeoutError;
      xhr.open("POST", request.destination, true);
      xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
      xhr.send(request.query);
    }

    //Makes a service request using a form POST method.
    function postRequestForm(request) {
      var form = document.createElement("form"),
        iframe = document.createElement("iframe"),
        loaded = false;

      function addParameter(name, value) {
        var field = document.createElement("input");
        field.name = name;
        field.value = value;
        form.appendChild(field);
      }

      form.method = "POST";
      form.action =
        pca.protocol + "//" + pca.host + "/" + request.service + "/json.ws";

      for (var key in request.data) addParameter(key, request.data[key]);

      addParameter("CallbackVariable", "window.name");
      addParameter("CallbackWithScriptTags", "true");

      iframe.onload = function () {
        if (!loaded) {
          loaded = true;
          iframe.contentWindow.location = "about:blank";
        } else {
          request.callback({ Items: pca.parseJSON(iframe.contentWindow.name) });
          document.body.removeChild(iframe);
        }
      };

      iframe.style.display = "none";
      document.body.appendChild(iframe);

      var doc = iframe.contentDocument || iframe.contentWindow.document;
      doc.body ? doc.body.appendChild(form) : doc.appendChild(form);
      form.submit();
    }

    //Makes a POST request using best method available.
    //Security must be bypassed in Internet Explorer up to version 10
    function postRequest(request) {
      window.navigator.appName === "Microsoft Internet Explorer"
        ? postRequestForm(request)
        : postRequestXHR(request);
    }

    //Makes a service request using a XMLHttpRequest GET method.
    function getRequestXHR(request) {
      var xhr = new pca.XMLHttpRequest();

      //if the URL length is long and likely to cause problems with URL limits, so we should make a POST request
      if (request.url.length > pca.limit) {
        request.post = true;
        postRequest(request);
      } else {
        xhr.onreadystatechange = function () {
          if (xhr.readyState === 4 && xhr.status === 200)
            request.callback(pca.parseJSON(xhr.responseText));
        };

        if (request.credentials) xhr.withCredentials = request.credentials;

        xhr.onerror = request.serviceError;
        xhr.ontimeout = request.timeoutError;
        xhr.open("GET", request.url, true);
        xhr.send();
      }
    }

    //Makes a service request using a script GET method.
    function getRequestScript(request) {
      var script = pca.create("script", {
          type: "text/javascript",
          async: "async",
        }),
        head = document.getElementsByTagName("head")[0];

      //set a callback point
      request.position = pca.scriptRequests.push(request);
      script.src =
        request.url +
        "&callback=pca.scriptRequests[" +
        (request.position - 1) +
        "].callback";

      script.onload = script.onreadystatechange = function () {
        if (
          !this.readyState ||
          this.readyState === "loaded" ||
          this.readyState === "complete"
        ) {
          script.onload = script.onreadystatechange = null;
          if (head && script.parentNode) head.removeChild(script);
        }
      };

      //if the src length is long and likely to cause problems with url limits we should make a POST request
      if (script.src.length > pca.limit) {
        request.post = true;
        postRequest(request);
      } else head.insertBefore(script, head.firstChild);
    }

    //Makes a GET request using best method available.
    //Security must be bypassed in Internet Explorer up to version 10.
    function getRequest(request) {
      window.navigator.appName === "Microsoft Internet Explorer"
        ? getRequestScript(request)
        : getRequestXHR(request);
    }

    //Decide what to do with the request.
    function processRequest(request) {
      //block requests if the flag is set, ignore all but the last request in this state
      if (pca.blockRequests && pca.waitingRequest) {
        pca.requestQueue = [request];
        return;
      }

      if (request.block) pca.blockRequests = true;

      //queue the request if flag is set
      if (request.queue && pca.waitingRequest) {
        pca.requestQueue.push(request);
        return;
      }

      pca.waitingRequest = true;

      //check the cache if the flag is set
      if (request.cache && pca.requestCache[request.url]) {
        function ayncCallback() {
          request.callback(pca.requestCache[request.url].response);
        }

        window.setImmediate
          ? window.setImmediate(ayncCallback)
          : window.setTimeout(ayncCallback, 1);
        return;
      }

      //make the request
      request.post ? postRequest(request) : getRequest(request);
    }

    //Receives and processes the service response.
    function processResponse(request) {
      pca.waitingRequest = false;

      if (request.block) pca.blockRequests = false;

      if (request.unwrapped) {
        request.success(request.response, request.response, request);
      } else {
        if (
          request.response.Items.length === 1 &&
          request.response.Items[0].Error !== undefined
        )
          request.error(request.response.Items[0].Description, request);
        else request.success(request.response.Items, request.response, request);
      }

      if (request.cache) pca.requestCache[request.url] = request;

      if (request.position) pca.scriptRequests[request.position - 1] = null;

      if (pca.requestQueue.length) processRequest(pca.requestQueue.shift());
    }

    /** Represents a service request
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {string} service - The service name. e.g. CapturePlus/Interactive/Find/v1.00
     * @param {Object} [data] - An object containing request parameters, such as key.
     * @param {boolean} [data.$cache=false] - The request will be cached.
     * @param {boolean} [data.$queue=false] - Queue other quests and make them once a response is received.
     * @param {boolean} [data.$block=false] - Ignore other requests until a response is received.
     * @param {boolean} [data.$post=false] - Make a POST request.
     * @param {boolean} [data.$credentials=false] - Send credentials with request.
     * @param {boolean} [data.$unwrapped=false] - return data will not be wrapped in items array.
     * @param {pca.Request~successCallback} [success] - A callback function for successful requests.
     * @param {pca.Request~errorCallback} [error] - A callback function for errors.
     * @param {Object} [options] - An object containing request configuration options. */
    pca.Request =
      pca.Request ||
      function (service, data, success, error, options) {
        /** @lends pca.Request.prototype */
        var request = new pca.Eventable(this);

        request.service = service || "";
        request.data = data || {};
        request.success = success || function () {};
        request.error = error || function () {};
        request.response = null;

        request.source = pca.sourceString || "";
        request.sessionId = pca.sessionId || "";

        request.cache = !!request.data.$cache; //request will not be deleted, other requests for the same data will return this response
        request.queue = !!request.data.$queue; //queue this request until other request is finished
        request.block = !!request.data.$block; //other requests will be blocked until this request is finished, only the last request will be queued
        request.post = !!request.data.$post; //force the request to be made using a HTTP POST
        request.credentials = !!request.data.$credentials; //send request credentials such as cookies
        request.unwrapped = !!request.data.$unwrapped; //eturn data will not be wrapped in items array.

        //build the basic request url
        request.destination = options.endpoint.literal
          ? request.service
          : pca.protocol +
            "//" +
            pca.host +
            "/" +
            request.service +
            "/" +
            pca.endpoint;
        request.query = "";

        for (var p in request.data)
          request.query +=
            (request.query ? "&" : "") +
            p +
            "=" +
            encodeURIComponent(request.data[p]);

        if (request.source) {
          request.query +=
            (request.query ? "&" : "") +
            "SOURCE=" +
            encodeURIComponent(request.source);
        }

        if (request.sessionId) {
          request.query +=
            (request.query ? "&" : "") +
            "SESSION=" +
            encodeURIComponent(request.sessionId);
        }

        request.url = request.destination + "?" + request.query;

        request.callback = function (response) {
          request.response = response;
          processResponse(request);
        };

        request.serviceError = function (event) {
          request.error(
            event && event.currentTarget && event.currentTarget.statusText
              ? "Webservice request error: " + event.currentTarget.statusText
              : "Webservice request failed."
          );
        };

        request.timeoutError = function () {
          request.error("Webservice request timed out.");
        };

        request.process = function () {
          pca.process(request);
        };

        /** Callback for a successful request.
         * @callback pca.Request~successCallback
         * @param {Object} items - The items returned in the response.
         * @param {Object} response - The raw response including additional fields. */

        /** Callback for a failed request.
         * @callback pca.Request~errorCallback
         * @param {string} message - The error text. */
      };

    /** Processes a webservice request
     * @memberof pca
     * @param {pca.Request} request - The request to process */
    pca.process =
      pca.process ||
      function (request) {
        processRequest(request);
      };

    /** Simple method for making a Postcode Anywhere service request and processing it
     * @memberof pca
     * @param {string} service - The service name. e.g. CapturePlus/Interactive/Find/v1.00
     * @param {Object} [data] - An object containing request parameters, such as key.
     * @param {boolean} [data.$cache] - The request will be cached.
     * @param {boolean} [data.$queue] - Queue other quests and make them once a response is received.
     * @param {boolean} [data.$block] - Ignore other requests until a response is received.
     * @param {boolean} [data.$post] - Make a POST request.
     * @param {pca.Request~successCallback} [success] - A callback function for successful requests.
     * @param {pca.Request~errorCallback} [error] - A callback function for errors.
     * @param {Object} [options] - An object containing request configuration options.*/
    pca.fetch =
      pca.fetch ||
      function (service, data, success, error, options = {}) {
        processRequest(new pca.Request(service, data, success, error, options));
      };

    /** Clears blocking requests */
    pca.clearBlockingRequests =
      pca.clearBlockingRequests ||
      function () {
        pca.waitingRequest = false;
        pca.blockRequests = false;
      };

    /** Dynamically load an additional script.
     * @memberof pca
     * @param {string} name - the name of the script to load.
     * @param {function} [callback] - a function to call once the script has loaded.
     * @param {HTMLDocument} [doc=document] - The document element in which to append the script. */
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
    pca.Item =
      pca.Item ||
      function (data, format) {
        /** @lends pca.Item.prototype */
        var item = new pca.Eventable(this),
          highlightClass = "pcaselected";

        /** The original data for the item. */
        item.data = data;
        /** The original formatter for the item. */
        item.format = format;
        item.html = pca.formatLine(data, format);
        item.tag = pca.formatTag(data.tag || item.html);
        /** The HTML element for the item. */

        item.element = pca.create("div", {
          className: "pcaitem",
          innerHTML: item.html,
          role: "option",
        });

        item.element.setAttribute("tabindex", -1);

        //ARIA
        item.element.addEventListener("keydown", function (evt) {
          //get correct control
          if (pca.operatingNamespace || pca.activeAddress) {
            var control =
              pca.activeAddress ||
              pca[pca.operatingNamespace].controls.find(function (control) {
                return (
                  control.autocomplete.list.collection.items.indexOf(item) > -1
                );
              });
            if (control) {
              var currentAutocomplete = control.inCountryListMode
                ? control.countrylist.autocomplete
                : control.autocomplete;
              //delegate to autocomplete control
              if (
                currentAutocomplete.list.collection.items.indexOf(item) == 0 &&
                evt.keyCode == 38
              ) {
                //is first and going up - refocus to input
                if (currentAutocomplete.field) {
                  currentAutocomplete.field.focus();
                  setTimeout(function () {
                    pca.moveCursorToEnd(currentAutocomplete.field);
                  }, 0);
                }
              } else if (evt.keyCode == 9 || evt.keyCode == 27) {
                //tab
                if (
                  !control.inCountryListMode &&
                  control.options.bar.visible &&
                  control.options.bar.showCountry
                ) {
                  //focus on the country button
                } else if (currentAutocomplete.field) {
                  currentAutocomplete.field.focus();
                  pca.moveCursorToEnd(currentAutocomplete.field);
                  currentAutocomplete.hide();
                  currentAutocomplete.field.dispatchEvent(evt);
                  pca.smash(evt);
                }
              } else {
                currentAutocomplete.list.navigate(evt.keyCode);
              }
            }
          }
        });

        item.visible = true;

        /** Applies the highlight style.
         * @fires highlight */
        item.highlight = function () {
          pca.addClass(item.element, highlightClass);
          item.fire("highlight");
          item.element.focus();
          return item;
        };

        /** Removes the highlight style.
         * @fires lowlight */
        item.lowlight = function () {
          pca.removeClass(item.element, highlightClass);
          item.fire("lowlight");

          return item;
        };

        /** The user is hovering over the item.
         * @fires mouseover */
        item.mouseover = function () {
          item.fire("mouseover");
        };

        /** The user has left the item.
         * @fires mouseout */
        item.mouseout = function () {
          item.fire("mouseout");
        };

        /** The user is pressed down on the item.
         * @fires mousedown */
        item.mousedown = function () {
          item.fire("mousedown");
        };

        /** The user released the item.
         * @fires mouseup */
        item.mouseup = function () {
          item.fire("mouseup");

          if (pca.galaxyFix) item.select();
        };

        /** The user has clicked the item.
         * @fires click */
        item.click = function () {
          item.fire("click");

          if (pca.galaxyFix) return;

          item.select();
        };

        /** Selects the item.
         * @fires select */
        item.select = function () {
          item.fire("select", item.data);

          return item;
        };

        /** Makes the item invisible.
         * @fires hide */
        item.hide = function () {
          item.visible = false;
          item.element.style.display = "none";
          item.fire("hide");

          return item;
        };

        /** Makes the item visible.
         * @fires show */
        item.show = function () {
          item.visible = true;
          item.element.style.display = "";
          item.fire("show");

          return item;
        };

        pca.listen(item.element, "mouseout", item.mouseout);
        pca.listen(item.element, "mousedown", item.mousedown);
        pca.listen(item.element, "mouseup", item.mouseup);
        pca.listen(item.element, "click", item.click);

        return item;
      };

    /** Represents a collection of items.
     * @memberof pca
     * @constructor
     * @mixes Eventable */
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
    pca.List =
      pca.List ||
      function (options) {
        /** @lends pca.List.prototype */
        var list = new pca.Eventable(this);

        list.options = options || {};
        /** The HTML parent element of the list */
        list.element = pca.create("div", { className: "pca pcalist" });
        /** The collection of items in the list
         * @type {pca.Collection} */
        list.collection = new pca.Collection();
        list.visible = true;
        list.scroll = {
          held: false,
          moved: false,
          origin: 0,
          position: 0,
          x: 0,
          y: 0,
          dx: 0,
          dy: 0,
        };
        list.highlightedItem = null;
        /** An item that will always be displayed first in the list.
         * @type {pca.Item} */
        list.headerItem = null;
        /** An item that will always be displayed last in the list.
         * @type {pca.Item} */
        list.footerItem = null;
        list.firstItem = null;
        list.lastItem = null;
        list.firstItemClass = "pcafirstitem";
        list.lastItemClass = "pcalastitem";

        list.options.minItems = list.options.minItems || 0;
        list.options.maxItems = list.options.maxItems || 10;
        list.options.allowTab = list.options.allowTab || false;

        /** Shows the list.
         * @fires show */
        list.show = function () {
          list.visible = true;
          list.element.style.display = "";
          list.fire("show");
          list.resize();

          return list;
        };

        /** Hides the list.
         * @fires hide */
        list.hide = function () {
          list.visible = false;
          list.element.style.display = "none";
          list.fire("hide");

          return list;
        };

        /** Redraws the list by removing all children and adding them again.
         * @fires draw */
        list.draw = function () {
          list.destroy();

          if (list.headerItem)
            list.element.appendChild(list.headerItem.element);

          list.collection.all(function (item) {
            list.element.appendChild(item.element);
          });

          if (list.footerItem)
            list.element.appendChild(list.footerItem.element);

          list.resize();
          list.fire("draw");

          return list;
        };

        /** Marks the first and last items in the list with a CSS class */
        list.markItems = function () {
          if (list.firstItem)
            pca.removeClass(list.firstItem.element, list.firstItemClass);
          if (list.lastItem)
            pca.removeClass(list.lastItem.element, list.lastItemClass);

          if (list.collection.count) {
            list.firstItem =
              list.headerItem || list.collection.firstVisibleItem;
            list.lastItem = list.footerItem || list.collection.lastVisibleItem;
            pca.addClass(list.firstItem.element, list.firstItemClass);
            pca.addClass(list.lastItem.element, list.lastItemClass);
          }
        };

        /** Adds items to the list collection.
         * @param {Array.<Object>} array - An array of data objects to add e.g. a response array from a service.
         * @param {string} format - A template string to format the label of the item.
         * @param {pca.Collection~itemCallback} callback - A callback function when the item is selected.
         * @fires add */
        list.add = function (array, format, callback) {
          list.collection.add(array, format, callback);
          list.draw();

          return list;
        };

        /** Destroys all items in the list. */
        list.destroy = function () {
          while (list.element.childNodes && list.element.childNodes.length)
            list.element.removeChild(list.element.childNodes[0]);

          return list;
        };

        /** Clears all items from the list
         * @fires clear */
        list.clear = function () {
          list.collection.clear();
          list.destroy();
          list.fire("clear");

          return list;
        };

        /** Sets the scroll position of the list.
         * @param {number} position - The top scroll position in pixels.
         * @fires scroll */
        list.setScroll = function (position) {
          list.element.scrollTop = position;
          list.fire("scroll");

          return list;
        };

        /** Enables touch input for list scrolling.
         * Most mobile browsers will handle scrolling without this. */
        list.enableTouch = function () {
          //touch events
          function touchStart(event) {
            event = event || window.event;
            list.scroll.held = true;
            list.scroll.moved = false;
            list.scroll.origin = parseInt(list.scrollTop);
            list.scroll.y = parseInt(event.touches[0].pageY);
          }

          function touchEnd() {
            list.scroll.held = false;
          }

          function touchCancel() {
            list.scroll.held = false;
          }

          function touchMove(event) {
            if (list.scroll.held) {
              event = event || window.event;

              //Disable Gecko and Webkit image drag
              pca.smash(event);

              list.scroll.dy = list.scroll.y - parseInt(event.touches[0].pageY);
              list.scroll.position = list.scroll.origin + list.scroll.dy;
              list.setScroll(list.scroll.position);
              list.scroll.moved = true;
            }
          }

          pca.listen(list.element, "touchstart", touchStart);
          pca.listen(list.element, "touchmove", touchMove);
          pca.listen(list.element, "touchend", touchEnd);
          pca.listen(list.element, "touchcancel", touchCancel);

          return list;
        };

        /** Moves to an item in the list */
        list.move = function (item) {
          if (item) {
            list.collection.highlight(item);

            if (item === list.headerItem || item === list.footerItem)
              item.highlight();

            list.scrollToItem(item);
          }

          return list;
        };

        /** Moves to the next item in the list. */
        list.next = function () {
          return list.move(list.nextItem());
        };

        /** Moves to the previous item in the list */
        list.previous = function () {
          return list.move(list.previousItem());
        };

        /** Moves to the first item in the list. */
        list.first = function () {
          return list.move(list.firstItem);
        };

        /** Moves to the last item in the list. */
        list.last = function () {
          return list.move(list.lastItem);
        };

        /** Returns the next item.
         * @returns {pca.Item} The next item. */
        list.nextItem = function () {
          if (!list.highlightedItem) return list.firstItem;

          if (
            list.highlightedItem === list.collection.lastVisibleItem &&
            (list.footerItem || list.headerItem)
          )
            return list.footerItem || list.headerItem;

          if (
            list.footerItem &&
            list.headerItem &&
            list.highlightedItem === list.footerItem
          )
            return list.headerItem;

          return list.collection.next();
        };

        /** Returns the previous item.
         * @returns {pca.Item} The previous item. */
        list.previousItem = function () {
          if (!list.highlightedItem) return list.lastItem;

          if (
            list.highlightedItem === list.collection.firstVisibleItem &&
            (list.footerItem || list.headerItem)
          )
            return list.headerItem || list.footerItem;

          if (
            list.footerItem &&
            list.headerItem &&
            list.highlightedItem === list.headerItem
          )
            return list.footerItem;

          return list.collection.previous();
        };

        /** Returns the current item.
         * @returns {pca.Item} The current item. */
        list.currentItem = function () {
          return list.highlightedItem;
        };

        /** Returns true if the current item is selectable.
         * @returns {boolean} True if the current item is selectable. */
        list.selectable = function () {
          return list.visible && !!list.currentItem();
        };

        /** Calls the select function for the current item */
        list.select = function () {
          if (list.selectable()) list.currentItem().select();

          return list;
        };

        /** Handles list navigation based upon a key code
         * @param {number} key - The keyboard key code.
         * @param {boolean} firstIfDown - Go to the first element if down key is pressed.
         * @returns {boolean} True if the list handled the key code. */
        list.navigate = function (key, firstIfDown) {
          switch (key) {
            case 40: //down
              if (firstIfDown) {
                list.first();
              } else {
                list.next();
              }
              return true;
            case 38: //up
              list.previous();
              return true;
            case 13: //enter/return
              if (list.selectable()) {
                list.select();
                return true;
              }
            case 9: //tab
              if (list.options.allowTab) {
                list.next();
                return true;
              }
          }

          return false;
        };

        /** Scrolls the list to show an item.
         * @param {pca.Item} item - The item to scroll to. */
        list.scrollToItem = function (item) {
          list.scroll.position = list.element.scrollTop;

          if (item.element.offsetTop < list.scroll.position) {
            list.scroll.position = item.element.offsetTop;
            list.setScroll(list.scroll.position);
          } else {
            if (
              item.element.offsetTop + item.element.offsetHeight >
              list.scroll.position + list.element.offsetHeight
            ) {
              list.scroll.position =
                item.element.offsetTop +
                item.element.offsetHeight -
                list.element.offsetHeight;
              list.setScroll(list.scroll.position);
            }
          }

          return list;
        };

        /** Filters the list item collection.
         * @param {string} term - The term to filter the items on.
         * @fires filter */
        list.filter = function (term) {
          var current = list.collection.count;

          list.collection.filter(term);
          list.markItems();

          if (current !== list.collection.count) list.fire("filter", term);

          return list;
        };

        /** Calculates the height of the based on minItems, maxItems and item size.
         * @returns {number} The height required in pixels. */
        list.getHeight = function () {
          var visibleItems = list.collection.visibleItems(),
            headerItemHeight = list.headerItem
              ? pca.getSize(list.headerItem.element).height
              : 0,
            footerItemHeight = list.footerItem
              ? pca.getSize(list.footerItem.element).height
              : 0,
            lastItemHeight = 0,
            itemsHeight = 0;

          //count the height of items in the list
          for (
            var i = 0;
            i < visibleItems.length && i < list.options.maxItems;
            i++
          ) {
            lastItemHeight = pca.getSize(visibleItems[i].element).height;
            itemsHeight += lastItemHeight;
          }

          //calculate the height of blank space required to keep the list height - assumes the last item has no bottom border
          if (visibleItems.length < list.options.minItems)
            itemsHeight +=
              (lastItemHeight + 1) *
              (list.options.minItems - visibleItems.length);

          return itemsHeight + headerItemHeight + footerItemHeight;
        };

        /** Sizes the list based upon the maximum number of items. */
        list.resize = function () {
          var height = list.getHeight();

          if (height > 0) list.element.style.height = height + "px";
        };

        //Create an item for the list which is not in the main collection
        function createListItem(data, format, callback) {
          var item = new pca.Item(data, format);

          item.listen("mouseover", function () {
            list.collection.highlight(item);
            item.highlight();
          });

          list.collection.listen("highlight", item.lowlight);

          item.listen("select", function (selectedItem) {
            list.collection.fire("select", selectedItem);
            callback(selectedItem);
          });

          return item;
        }

        /** Adds an item to the list which will always appear at the bottom. */
        list.setHeaderItem = function (data, format, callback) {
          list.headerItem = createListItem(data, format, callback);
          pca.addClass(list.footerItem.element, "pcaheaderitem");
          list.markItems();
          return list;
        };

        /** Adds an item to the list which will always appear at the bottom. */
        list.setFooterItem = function (data, format, callback) {
          list.footerItem = createListItem(data, format, callback);
          pca.addClass(list.footerItem.element, "pcafooteritem");
          list.markItems();
          return list;
        };

        //store the current highlighted item
        list.collection.listen("highlight", function (item) {
          list.highlightedItem = item;
        });

        //Map collection events
        list.collection.listen("add", function (additions) {
          list.markItems();
          list.fire("add", additions);
        });

        //ARIA support
        pca.setAttributes(list.element, {
          id: list.options.name,
          role: "listbox",
          "aria-activedescendant": "",
        });
        if (list.options.ariaLabel) {
          pca.setAttributes(list.element, {
            "aria-label": list.options.ariaLabel,
          });
        }

        list.collection.listen("add", function (additions) {
          function listenHighlightChange(item) {
            item.listen("highlight", function () {
              pca.setAttributes(list.element, {
                "aria-activedescendant": item.id,
              });
            });
          }

          for (var i = 0; i < additions.length; i++)
            listenHighlightChange(additions[i]);

          list.collection.all(function (item, index) {
            item.element.id = item.id = list.options.name + "_item" + index;
            pca.setAttributes(item.element, { role: "option" });
          });
        });

        return list;
      };

    /**
     * Autocomplete list options.
     * @typedef {Object} pca.AutoComplete.Options
     * @property {string} [name] - A reference for the list used an Id for ARIA.
     * @property {string} [className] - An additional class to add to the autocomplete.
     * @property {boolean} [force] - Forces the list to bind to the fields.
     * @property {boolean} [onlyDown] - Force the list to only open downwards.
     * @property {number|string} [width] - Fixes the width to the specified number of pixels.
     * @property {number|string} [height] - Fixes the height to the specified number of pixels.
     * @property {number|string} [left] - Shifts the list left by the specified number of pixels.
     * @property {number|string} [top] - Shifts the list left by the specified number of pixels.
     * @property {string} [emptyMessage] - When set an empty list will show this message rather than hiding after a filter.
     */

    /** Creates an autocomplete list which is bound to a field.
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {Array.<HTMLElement>} fields - A list of input elements to bind to.
     * @param {pca.AutoComplete.Options} [options] - Additional options to apply to the autocomplete list. */
    pca.AutoComplete =
      pca.AutoComplete ||
      function (fields, options) {
        /** @lends pca.AutoComplete.prototype */
        var autocomplete = new pca.Eventable(this);

        autocomplete.options = options || {};
        autocomplete.options.force = autocomplete.options.force || false;
        autocomplete.options.allowTab = autocomplete.options.allowTab || false;
        autocomplete.options.onlyDown = autocomplete.options.onlyDown || false;
        /** The parent HTML element for the autocomplete list. */
        autocomplete.element = pca.create("div", {
          className: "pcaautocomplete pcatext",
        });
        autocomplete.anchors = [];
        /** The parent list object.
         * @type {pca.List} */
        autocomplete.list = new pca.List(autocomplete.options);
        autocomplete.fieldListeners = [];
        /** The current field that the autocomplete is bound to. */
        autocomplete.field = null;
        autocomplete.positionField = null;
        /** The visibility state of the autocomplete list.
         * @type {boolean} */
        autocomplete.visible = true;
        autocomplete.hover = false;
        autocomplete.focused = false;
        autocomplete.upwards = false;
        autocomplete.controlDown = false;
        autocomplete.altDown = false;
        /** The disabled state of the autocomplete list.
         * @type {boolean} */
        autocomplete.disabled = false;
        autocomplete.fixedWidth = false;
        /** When set an empty list will show this message rather than hiding after a filter.
         * @type {string} */
        autocomplete.emptyMessage = autocomplete.options.emptyMessage || "";
        /** When enabled list will not redraw as the user types, but filter events will still be raised.
         * @type {boolean} */
        autocomplete.skipFilter = false;
        /** Won't show the list, but it will continue to fire events in the same way. */
        autocomplete.stealth = false;

        function documentClicked() {
          autocomplete.checkHide();
        }

        function windowResized() {
          autocomplete.resize();
        }

        /** Header element. */
        autocomplete.header = {
          element: pca.create("div", { className: "pcaheader" }),
          headerText: pca.create("div", { className: "pcamessage" }),

          init: function () {
            this.hide();
          },

          setContent: function (content) {
            content = content || "";
            typeof content == "string"
              ? (this.element.innerHTML = content)
              : this.element.appendChild(content);
            autocomplete.fire("header");
            return this;
          },

          setText: function (text) {
            text = text || "";
            this.element.appendChild(this.headerText);

            if (typeof text == "string") {
              pca.clear(this.headerText);
              this.headerText.appendChild(
                pca.create("span", { className: "pcamessageicon" })
              );
              this.headerText.appendChild(
                pca.create("span", { innerHTML: text })
              );
            } else this.headerText.appendChild(text);

            autocomplete.fire("header");
            return this;
          },

          clear: function () {
            this.setContent();
            autocomplete.fire("header");
            return this;
          },

          show: function () {
            this.element.style.display = "";
            autocomplete.fire("header");
            return this;
          },

          hide: function () {
            this.element.style.display = "none";
            autocomplete.fire("header");
            return this;
          },
        };

        /** Footer element. */
        autocomplete.footer = {
          element: pca.create("div", { className: "pcafooter" }),

          init: function () {
            this.hide();
          },

          setContent: function (content) {
            content = content || "";
            typeof content == "string"
              ? (this.element.innerHTML = content)
              : this.element.appendChild(content);
            autocomplete.fire("footer");
            return this;
          },

          show: function () {
            this.element.style.display = "";
            autocomplete.fire("footer");
            return this;
          },

          hide: function () {
            this.element.style.display = "none";
            autocomplete.fire("footer");
            return this;
          },
        };

        /** Attaches the list to field or list of fields provided. */
        autocomplete.load = function () {
          if (fields.length && fields.constructor === Array) {
            for (var i = 0; i < fields.length; i++)
              autocomplete.attach(pca.getElement(fields[i]));
          } else autocomplete.attach(pca.getElement(fields));

          pca.listen(autocomplete.element, "mouseover", function () {
            autocomplete.hover = true;
          });
          pca.listen(autocomplete.element, "mouseout", function () {
            autocomplete.hover = false;
          });

          //page events
          pca.listen(document, "click", documentClicked);
          pca.listen(window, "resize", windowResized);

          if (
            (document.documentMode && document.documentMode <= 7) ||
            /\bMSIE\s(7|6)/.test(pca.agent)
          )
            autocomplete.setWidth(280);

          if (document.documentMode && document.documentMode <= 5) {
            pca.applyStyleFixes(".pca .pcafooter", { fontSize: "0pt" });
            pca.applyStyleFixes(".pca .pcaflag", { fontSize: "0pt" });
          }

          return autocomplete;
        };

        /** Attaches the list to a field.
         * @param {HTMLElement} field - The field to attach to. */
        autocomplete.attach = function (field) {
          function bindFieldEvent(f, event, action) {
            pca.listen(f, event, action);
            autocomplete.fieldListeners.push({
              field: f,
              event: event,
              action: action,
            });
          }

          function anchorToField(f) {
            var anchor = pca.create("table", {
                className: "pca pcaanchor",
                cellPadding: 0,
                cellSpacing: 0,
              }),
              chain = [
                anchor.insertRow(0).insertCell(0),
                anchor.insertRow(1).insertCell(0),
              ],
              link = pca.create("div", { className: "pcachain" });

            function focus() {
              link.appendChild(autocomplete.element);
              autocomplete.focus(f);
            }

            //check the field
            if (!f || !f.tagName) {
              pca.append(autocomplete.element);
              return;
            }

            f.parentNode.insertBefore(anchor, f);
            chain[0].appendChild(f);
            chain[1].appendChild(link);
            autocomplete.anchors.push(anchor);

            if (pca.inputField(f)) {
              bindFieldEvent(f, "keyup", autocomplete.keyup);
              bindFieldEvent(f, "keydown", function (evt) {
                autocomplete.keydown(evt, f);
              });
              bindFieldEvent(f, "keypress", autocomplete.keypress);
              bindFieldEvent(f, "paste", autocomplete.paste);

              // ReSharper disable once ConditionIsAlwaysConst
              // IE9 bug when running within iframe
              if (
                typeof document.activeElement != "unknown" &&
                f === document.activeElement
              )
                focus();
            }

            bindFieldEvent(f, "click", function () {
              autocomplete.click(f);
            });
            bindFieldEvent(f, "dblclick", function () {
              autocomplete.dblclick(f);
            });
            bindFieldEvent(f, "change", function () {
              autocomplete.change(f);
            });
          }

          function positionAdjacentField(f) {
            function focus() {
              autocomplete.focus(f);
            }

            pca.append(autocomplete.element);

            //check the field
            if (!f || !f.tagName) return;

            if (pca.inputField(f)) {
              bindFieldEvent(f, "keyup", autocomplete.keyup);
              bindFieldEvent(f, "keydown", function (evt) {
                autocomplete.keydown(evt, f);
              });
              bindFieldEvent(f, "keypress", autocomplete.keypress);
              bindFieldEvent(f, "paste", autocomplete.paste);

              // ReSharper disable once ConditionIsAlwaysConst
              // IE9 bug when running within iframe
              if (
                typeof document.activeElement != "unknown" &&
                f === document.activeElement
              )
                focus();
            }

            bindFieldEvent(f, "click", function () {
              autocomplete.click(f);
            });
            bindFieldEvent(f, "dblclick", function () {
              autocomplete.dblclick(f);
            });
            bindFieldEvent(f, "change", function () {
              autocomplete.change(f);
            });
          }

          autocomplete.options.force
            ? anchorToField(field)
            : positionAdjacentField(field);
        };

        /** Positions the autocomplete.
         * @param {HTMLElement} field - The field to position the list under. */
        autocomplete.position = function (field) {
          var fieldPosition = pca.getPosition(field),
            fieldSize = pca.getSize(field),
            topParent = pca.getTopOffsetParent(field),
            parentScroll = pca.getParentScroll(field),
            listSize = pca.getSize(autocomplete.element),
            windowSize = pca.getSize(window),
            windowScroll = pca.getScroll(window),
            fixed = !pca.isPage(topParent);

          //check where there is space to open the list
          var hasSpaceBelow =
              fieldPosition.top +
                listSize.height -
                (fixed ? 0 : windowScroll.top) <
              windowSize.height,
            hasSpaceAbove =
              fieldPosition.top - (fixed ? 0 : windowScroll.top) >
              listSize.height;

          //should the popup open upwards
          autocomplete.upwards =
            !hasSpaceBelow && hasSpaceAbove && !autocomplete.options.onlyDown;

          if (autocomplete.upwards) {
            if (autocomplete.options.force) {
              autocomplete.element.style.top =
                -(listSize.height + fieldSize.height + 2) + "px";
            } else {
              autocomplete.element.style.top =
                fieldPosition.top -
                parentScroll.top -
                listSize.height +
                (fixed ? windowScroll.top : 0) +
                "px";
              autocomplete.element.style.left =
                fieldPosition.left -
                parentScroll.left +
                (fixed ? windowScroll.left : 0) +
                "px";
            }
          } else {
            if (autocomplete.options.force)
              autocomplete.element.style.top = "auto";
            else {
              autocomplete.element.style.top =
                fieldPosition.top -
                parentScroll.top +
                fieldSize.height +
                1 +
                (fixed ? windowScroll.top : 0) +
                "px";
              autocomplete.element.style.left =
                fieldPosition.left -
                parentScroll.left +
                (fixed ? windowScroll.left : 0) +
                "px";
            }
          }

          if (autocomplete.options.left)
            autocomplete.element.style.left =
              parseInt(autocomplete.element.style.left) +
              parseInt(autocomplete.options.left) +
              "px";
          if (autocomplete.options.top)
            autocomplete.element.style.top =
              parseInt(autocomplete.element.style.top) +
              parseInt(autocomplete.options.top) +
              "px";

          var ownBorderWidth =
              parseInt(pca.getStyle(autocomplete.element, "borderLeftWidth")) +
                parseInt(
                  pca.getStyle(autocomplete.element, "borderRightWidth")
                ) || 0,
            preferredWidth = Math.max(
              pca.getSize(field).width - ownBorderWidth,
              0
            );

          //set minimum width for field
          if (!autocomplete.fixedWidth) {
            autocomplete.element.style.minWidth = preferredWidth + "px";
            //recalculate the maxwidth for autocomplete control to support mobile friendly
            if (window.innerWidth < 767) {
              var bbox = autocomplete.element.getBoundingClientRect();
              autocomplete.element.style.maxWidth =
                "calc(100vw - " + (bbox.left + 10) + "px";
            }
          }

          //fix the size when there is no support for minimum width
          if (
            (document.documentMode && document.documentMode <= 7) ||
            /\bMSIE\s(7|6)/.test(pca.agent)
          ) {
            autocomplete.setWidth(Math.max(preferredWidth, 280));
            autocomplete.element.style.left =
              (parseInt(autocomplete.element.style.left) || 0) - 2 + "px";
            autocomplete.element.style.top =
              (parseInt(autocomplete.element.style.top) || 0) - 2 + "px";
          }

          autocomplete.positionField = field;
          autocomplete.fire("move");

          return autocomplete;
        };

        /** Positions the list under the last field it was positioned to. */
        autocomplete.reposition = function () {
          if (autocomplete.positionField)
            autocomplete.position(autocomplete.positionField);
          return autocomplete;
        };

        /** Sets the value of input field to prompt the user.
         * @param {string} text - The text to show.
         * @param {number} [position] - The index at which to set the carat. */
        autocomplete.prompt = function (text, position) {
          if (typeof position == "number") {
            //insert space
            if (position === 0) text = " " + text;
            else if (position >= text.length) {
              text = text + " ";
              position++;
            } else {
              text =
                text.substring(0, position) +
                "  " +
                text.substring(position, text.length);
              position++;
            }

            pca.setValue(autocomplete.field, text);

            if (autocomplete.field.setSelectionRange) {
              autocomplete.field.focus();
              autocomplete.field.setSelectionRange(position, position);
            } else if (autocomplete.field.createTextRange) {
              var range = autocomplete.field.createTextRange();
              range.move("character", position);
              range.select();
            }
          } else pca.setValue(autocomplete.field, text);

          return autocomplete;
        };

        /** Shows the autocomplete.
         * @fires show */
        autocomplete.show = function () {
          if (!autocomplete.disabled && !autocomplete.stealth) {
            autocomplete.visible = true;
            autocomplete.element.style.display = "";
            //announce aria if more than 3 chars
            if (autocomplete.field.value.length > 2) {
              var lang = autocomplete.options.language || "en";
              var isCountryList =
                autocomplete.options &&
                autocomplete.options.type &&
                autocomplete.options.type === "countrylist";
              pca.read(
                autocomplete.list.collection.count +
                  " " +
                  (autocomplete.list.collection.count == 1
                    ? isCountryList
                      ? pca.messages[lang].COUNTRYAVAILABLE
                      : pca.messages[lang].ADDRESSAVAILABLE
                    : isCountryList
                    ? pca.messages[lang].COUNTRIESAVAILABLE
                    : pca.messages[lang].ADDRESSESAVAILABLE)
              );
            }
            //deal with empty list
            if (!autocomplete.list.collection.count) {
              if (autocomplete.options.emptyMessage)
                autocomplete.header
                  .setText(autocomplete.options.emptyMessage)
                  .show();

              autocomplete.list.hide();
            } else {
              if (autocomplete.options.emptyMessage)
                autocomplete.header.clear().hide();

              autocomplete.list.show();
            }

            autocomplete.setScroll(0);
            autocomplete.reposition();
            autocomplete.fire("show");
            autocomplete.exitEvent = document.addEventListener(
              "click",
              autocomplete.externalClickHandler
            );
          }
          return autocomplete;
        };

        autocomplete.externalClickHandler = function (evt) {
          if (!pca.closestElement(event.target, ".pca")) {
            autocomplete.hide();
          }
        };

        /** Shows the autocomplete and all items without a filter. */
        autocomplete.showAll = function () {
          autocomplete.list.filter("");
          autocomplete.show();
        };

        /** Hides the autocomplete.
         * @fires hide */
        autocomplete.hide = function () {
          autocomplete.visible = false;
          autocomplete.element.style.display = "none";
          autocomplete.fire("hide");
          document.removeEventListener(
            "click",
            autocomplete.externalClickHandler
          );
          return autocomplete;
        };

        /** Shows the autocomplete list under a field.
         * @param {HTMLElement} field - The field to show the list under.
         * @fires focus */
        autocomplete.focus = function (field) {
          autocomplete.field = field;
          autocomplete.focused = true;
          autocomplete.show();
          autocomplete.position(field);
          autocomplete.fire("focus");
        };

        /** Hides the list unless it has field or mouse focus */
        autocomplete.checkHide = function () {
          if (
            autocomplete.visible &&
            !autocomplete.focused &&
            !autocomplete.hover
          )
            autocomplete.hide();

          return autocomplete;
        };

        /** Handles a keyboard key.
         * @param {number} key - The keyboard key code to handle.
         * @param {Event} [event] - The original event to cancel if required.
         * @fires keyup */
        autocomplete.handleKey = function (key, event) {
          if (key === 27 || key === 9) {
            //escape
            autocomplete.hide();
            autocomplete.fire("escape");
          } else if (key === 17 || key === 91) {
            //ctrl
            autocomplete.controlDown = false;
          } else if (key === 18) {
            autocomplete.altDown = false;
          } else if (key === 16) {
            autocomplete.shiftDown = false;
          } else if (key === 8 || key === 46) {
            //del or backspace
            autocomplete.filter();
            autocomplete.fire("delete");
          } else if (key !== 0 && key <= 46 && key !== 32) {
            //recognised non-character key
            if (key !== 38) {
              //do nothing on up with focus in field
              if (
                autocomplete.visible &&
                autocomplete.list.navigate(key, true)
              ) {
                if (event) pca.smash(event); //keys handled by the list, stop other events
              } else if (key === 40) {
                //up or down when list is hidden
                autocomplete.filter();
              }
            }
          } else if (autocomplete.visible) {
            //normal key press when list is visible
            autocomplete.filter();
          }
          autocomplete.fire("keyup", key);
        };

        //keydown event handler
        autocomplete.keydown = function (event, field) {
          if (event.keyCode == 9) {
            autocomplete.hide();
          } else {
            if (!autocomplete.visible) {
              autocomplete.focus(field);
            }
            event = event || window.event;
            var key = event.which || event.keyCode;
            if (key === 17 || key === 91) {
              autocomplete.controlDown = true;
            } else if (key === 16) {
              autocomplete.shiftDown = true;
            } else if (key === 18) {
              autocomplete.altDown = true;
            } else if (
              key === 67 &&
              autocomplete.shiftDown &&
              autocomplete.controlDown
            ) {
              if (autocomplete.address) {
                event.preventDefault();
                autocomplete.address.switchToCountrySelect();
              }
            }
          }
        };

        //keyup event handler
        autocomplete.keyup = function (event) {
          event = event || window.event;
          var key = event.which || event.keyCode;
          autocomplete.handleKey(key, event);
        };

        //keypress event handler
        autocomplete.keypress = function (event) {
          var key = window.event ? window.event.keyCode : event.which;

          if (
            autocomplete.visible &&
            key === 13 &&
            autocomplete.list.selectable()
          )
            pca.smash(event);
        };

        //paste event handler
        autocomplete.paste = function () {
          window.setTimeout(function () {
            autocomplete.filter();
            autocomplete.fire("paste");
          }, 0);
        };

        /** Handles user clicks on field.
         * @fires click */
        autocomplete.click = function (f) {
          autocomplete.fire("click", f);
        };

        /** Handles user double clicks on the field.
         * @fires dblclick */
        autocomplete.dblclick = function (f) {
          autocomplete.fire("dblclick", f);
        };

        /** Handles field value change.
         * @fires change */
        autocomplete.change = function (f) {
          autocomplete.fire("change", f);
        };

        /** Handles page resize.
         * @fires change */
        autocomplete.resize = function () {
          if (autocomplete.visible) autocomplete.reposition();
        };

        /** Add items to the autocomplete list.
         * @param {Array.<Object>} array - An array of data objects to add as items.
         * @param {string} format - A format string to display items.
         * @param {function} callback - A callback function for item select. */
        autocomplete.add = function (array, format, callback) {
          autocomplete.list.add(array, format, callback);

          return autocomplete;
        };

        /** Clears the autocomplete list. */
        autocomplete.clear = function () {
          autocomplete.list.clear();

          return autocomplete;
        };

        /** Sets the scroll position of the autocomplete list. */
        autocomplete.setScroll = function (position) {
          autocomplete.list.setScroll(position);

          return autocomplete;
        };

        /** Sets the width of the autocomplete list.
         * @param {number|string} width - The width in pixels for the list. */
        autocomplete.setWidth = function (width) {
          if (typeof width == "number") {
            width = Math.max(width, 220);
            autocomplete.element.style.width = width + "px";
            if (document.documentMode && document.documentMode <= 5) width -= 2;
            autocomplete.list.element.style.width = width + "px";
          } else {
            autocomplete.element.style.width = width;
            autocomplete.list.element.style.width = width;
          }

          autocomplete.fixedWidth = width !== "auto";
          autocomplete.element.style.minWidth = 0;

          return autocomplete;
        };

        /** Sets the height of the autocomplete list.
         * @param {number|string} height - The height in pixels for the list. */
        autocomplete.setHeight = function (height) {
          if (typeof height == "number")
            autocomplete.list.element.style.height = height + "px";
          else autocomplete.list.element.style.height = height;

          return autocomplete;
        };

        /** Filters the autocomplete list for items matching the supplied term.
         * @param {string} term - The term to search for. Case insensitive.
         * @fires filter */
        autocomplete.filter = function (term) {
          term = term || pca.getValue(autocomplete.field);

          if (autocomplete.skipFilter) {
            if (
              autocomplete.list.collection.match(term).length <
              autocomplete.list.collection.count
            )
              autocomplete.list.fire("filter");
          } else {
            autocomplete.list.filter(term, autocomplete.skipFilter);
            term &&
            !autocomplete.list.collection.count &&
            !autocomplete.skipFilter &&
            !autocomplete.options.emptyMessage
              ? autocomplete.hide()
              : autocomplete.show();
          }

          autocomplete.fire("filter", term);

          return autocomplete;
        };

        /** Disables the autocomplete. */
        autocomplete.disable = function () {
          autocomplete.disabled = true;

          return autocomplete;
        };

        /** Enables the autocomplete when disabled. */
        autocomplete.enable = function () {
          autocomplete.disabled = false;

          return autocomplete;
        };

        /** Removes the autocomplete elements and event listeners from the page. */
        autocomplete.destroy = function () {
          pca.remove(autocomplete.element);

          //stop listening to page events
          pca.ignore(document, "click", documentClicked);
          pca.ignore(window, "resize", windowResized);

          for (var i = 0; i < autocomplete.fieldListeners.length; i++)
            pca.ignore(
              autocomplete.fieldListeners[i].field,
              autocomplete.fieldListeners[i].event,
              autocomplete.fieldListeners[i].action
            );
        };

        autocomplete.element.appendChild(autocomplete.header.element);
        autocomplete.element.appendChild(autocomplete.list.element);
        autocomplete.element.appendChild(autocomplete.footer.element);
        autocomplete.header.init();
        autocomplete.footer.init();

        if (fields) autocomplete.load(fields);
        if (autocomplete.options.width)
          autocomplete.setWidth(autocomplete.options.width);
        if (autocomplete.options.height)
          autocomplete.setHeight(autocomplete.options.height);
        if (autocomplete.options.className)
          pca.addClass(autocomplete.element, autocomplete.options.className);

        if (!autocomplete.field) autocomplete.hide();

        return autocomplete;
      };

    /**
     * Modal window options.
     * @typedef {Object} pca.Modal.Options
     * @property {string} [title] - The title text for the window.
     * @property {string} [titleStyle] - The CSS text to apply to the title.
     */

    /** Creates a modal popup window.
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {pca.Modal.Options} [options] - Additional options to apply to the modal window. */
    pca.Modal =
      pca.Modal ||
      function (options) {
        /** @lends pca.Modal.prototype */
        var modal = new pca.Eventable(this);

        modal.options = options || {};

        /** The parent HTML element of the modal window */
        modal.element = pca.create("div", { className: "pcamodal" });
        modal.border = pca.create("div", { className: "pcaborder" });
        modal.frame = pca.create("div", { className: "pcaframe" });
        modal.content = pca.create("div", { className: "pcacontent pcatext" });
        modal.mask = pca.create("div", { className: "pcafullscreen pcamask" });
        modal.form = [];

        /** Header element. */
        modal.header = {
          element: pca.create("div", { className: "pcaheader" }),
          headerText: pca.create(
            "div",
            { className: "pcatitle" },
            modal.options.titleStyle || ""
          ),

          init: function () {
            this.setText(modal.options.title || "");
          },

          setContent: function (content) {
            content = content || "";
            typeof content == "string"
              ? (this.element.innerHTML = content)
              : this.element.appendChild(content);
            modal.fire("header");
            return this;
          },

          setText: function (text) {
            text = text || "";
            this.element.appendChild(this.headerText);
            typeof text == "string"
              ? (this.headerText.innerHTML = text)
              : this.headerText.appendChild(text);
            modal.fire("header");
            return this;
          },

          show: function () {
            this.element.style.display = "";
            modal.fire("header");
            return this;
          },

          hide: function () {
            this.element.style.display = "none";
            modal.fire("header");
            return this;
          },
        };

        /** Footer element */
        modal.footer = {
          element: pca.create("div", { className: "pcafooter" }),

          setContent: function (content) {
            content = content || "";
            typeof content == "string"
              ? (this.element.innerHTML = content)
              : this.element.appendChild(content);
            modal.fire("footer");
            return this;
          },

          show: function () {
            this.element.style.display = "";
            modal.fire("header");
            return this;
          },

          hide: function () {
            this.element.style.display = "none";
            modal.fire("header");
            return this;
          },
        };

        /** Shortcut to set the content of the modal title and show it.
         * @param {string|HTMLElement} content - The content to set in the title. */
        modal.setTitle = function (content) {
          modal.header.setText(content).show();
        };

        /** Sets the content of the modal window.
         * @param {string|HTMLElement} content - The content to set in the body of the modal.
         * @fires change */
        modal.setContent = function (content) {
          typeof content == "string"
            ? (modal.content.innerHTML = content)
            : modal.content.appendChild(content);
          modal.fire("change");

          return modal;
        };

        //sets defaults for a field
        function defaultProperties(properties) {
          properties = properties || {};
          properties.type = properties.type || "text";
          return properties;
        }

        /** Adds a new field to the modal content.
         * @param {string} labelText - The text for the field label.
         * @param {Object} [properties] - Properties to set on the input field.
         * @param {Object} [properties.tag=input] - Changes the type of element to create.
         * @param {HTMLElement} The HTML field created. */
        modal.addField = function (labelText, properties) {
          properties = defaultProperties(properties);

          var row = pca.create("div", { className: "pcainputrow" }),
            input = pca.create(properties.tag || "input", properties),
            label = pca.create("label", {
              htmlFor: input.id || "",
              innerHTML: labelText || "",
            });

          row.appendChild(label);
          row.appendChild(input);
          modal.setContent(row);

          modal.form.push({ label: labelText, element: input });

          return input;
        };

        /** Adds two half width fields to the modal content.
         * @param {string} labelText - The text for the field label.
         * @param {Object} [firstProperties] - Properties to set on the first (left) input field.
         * @param {Object} [firstProperties.tag] - Changes the type of element to create.
         * @param {Object} [secondProperties] - Properties to set on the second (right) input field.
         * @param {Object} [secondProperties.tag] - Changes the type of element to create.
         * @return {Array.<HTMLElement>} The two HTML fields created. */
        modal.addHalfFields = function (
          labelText,
          firstProperties,
          secondProperties
        ) {
          firstProperties = defaultProperties(firstProperties);
          secondProperties = defaultProperties(secondProperties);

          var row = pca.create("div", { className: "pcainputrow" }),
            firstInput = pca.create(
              firstProperties.tag || "input",
              firstProperties
            ),
            secondInput = pca.create(
              secondProperties.tag || "input",
              secondProperties
            ),
            label = pca.create("label", {
              htmlFor: firstInput.id || "",
              innerHTML: labelText || "",
            });

          pca.addClass(firstInput, "pcahalf");
          pca.addClass(secondInput, "pcahalf");

          row.appendChild(label);
          row.appendChild(firstInput);
          row.appendChild(secondInput);
          modal.setContent(row);

          modal.form.push({ label: "First " + labelText, element: firstInput });
          modal.form.push({
            label: "Second " + labelText,
            element: secondInput,
          });

          return [firstInput, secondInput];
        };

        /** Adds a button to the modal footer.
         * @param {string} labelText - The text for the field label.
         * @param {function} callback - A callback function which handles the button click.
         * @param {boolean} floatRight - Sets float:right on the button. Ignored by versions of IE older than 8.
         * @returns {HTMLElement} The HTML input element created. */
        modal.addButton = function (labelText, callback, floatRight) {
          var button = pca.create("input", {
            type: "button",
            value: labelText,
            className: "pcabutton",
          });

          callback = callback || function () {};

          //call the callback function with the form details
          function click() {
            var details = {};

            for (var i = 0; i < modal.form.length; i++)
              details[modal.form[i].label] = pca.getValue(
                modal.form[i].element
              );

            callback(details);
          }

          if (
            floatRight &&
            !(document.documentMode && document.documentMode <= 7)
          )
            button.style.cssFloat = "right";

          pca.listen(button, "click", click);
          modal.footer.setContent(button);

          return button;
        };

        /** Centres the modal in the browser window */
        modal.centre = function () {
          var modalSize = pca.getSize(modal.element);

          modal.element.style.marginTop = -(modalSize.height / 2) + "px";
          modal.element.style.marginLeft = -(modalSize.width / 2) + "px";

          return modal;
        };

        /** Shows the modal window.
         * @fires show */
        modal.show = function () {
          //not supported in quirks mode or ie6 currently
          if (
            !(document.documentMode && document.documentMode <= 5) &&
            !/\bMSIE\s6/.test(pca.agent)
          ) {
            modal.element.style.display = "";
            modal.mask.style.display = "";
            modal.centre();
            modal.fire("show");
          }

          return modal;
        };

        /** Hides the modal window.
         * @fires hide */
        modal.hide = function () {
          modal.element.style.display = "none";
          modal.mask.style.display = "none";
          modal.fire("hide");

          return modal;
        };

        /** Clears the content and buttons of the modal window.
         * @fires clear */
        modal.clear = function () {
          while (modal.content.childNodes.length)
            modal.content.removeChild(modal.content.childNodes[0]);

          while (modal.footer.element.childNodes.length)
            modal.footer.element.removeChild(
              modal.footer.element.childNodes[0]
            );

          modal.form = [];
          modal.fire("clear");

          return modal;
        };

        pca.listen(modal.mask, "click", modal.hide);

        modal.element.appendChild(modal.border);
        modal.element.appendChild(modal.frame);
        modal.frame.appendChild(modal.header.element);
        modal.frame.appendChild(modal.content);
        modal.frame.appendChild(modal.footer.element);
        modal.header.init();

        pca.append(modal.mask);
        pca.append(modal.element);

        modal.hide();

        return modal;
      };

    /** Creates a helpful tooltip when hovering over an element.
     * @memberof pca
     * @constructor
     * @mixes Eventable
     * @param {HTMLElement} element - The element to bind to.
     * @param {string} message - The text to show. */
    pca.Tooltip =
      pca.Tooltip ||
      function (element, message) {
        /** @lends pca.Tooltip.prototype */
        var tooltip = new pca.Eventable(this);

        /** The parent HTML element for the tooltip. */
        tooltip.element = pca.create("div", { className: "pcatooltip" });
        tooltip.background = pca.create("div", { className: "pcabackground" });
        tooltip.message = pca.create("div", {
          className: "pcamessage",
          innerText: message,
        });

        /** Shows the tooltip.
         * @fires show */
        tooltip.show = function () {
          tooltip.element.style.display = "";
          tooltip.position();
          tooltip.fire("show");
          return tooltip;
        };

        /** Hides the tooltip.
         * @fires hide */
        tooltip.hide = function () {
          tooltip.element.style.display = "none";
          tooltip.fire("hide");
          return tooltip;
        };

        /** Sets the text for the tooltip.
         * @param {string} text - The text to set. */
        tooltip.setMessage = function (text) {
          pca.setValue(tooltip.message, text);
        };

        /** Positions the tooltip centrally above the element. */
        tooltip.position = function () {
          var parentPosition = pca.getPosition(element),
            parentSize = pca.getSize(element),
            topParent = pca.getTopOffsetParent(element),
            messageSize = pca.getSize(tooltip.message),
            windowSize = pca.getSize(window),
            windowScroll = pca.getScroll(window),
            fixed = !pca.isPage(topParent);

          var top =
              parentPosition.top -
              messageSize.height -
              5 +
              (fixed ? windowScroll.top : 0),
            left =
              parentPosition.left +
              parentSize.width / 2 -
              messageSize.width / 2 +
              (fixed ? windowScroll.left : 0);

          top = Math.min(
            top,
            windowSize.height + windowScroll.top - messageSize.height
          );
          top = Math.max(top, 0);

          left = Math.min(
            left,
            windowSize.width + windowScroll.left - messageSize.width
          );
          left = Math.max(left, 0);

          tooltip.element.style.top = top + "px";
          tooltip.element.style.left = left + "px";
        };

        if ((element = pca.getElement(element))) {
          pca.listen(element, "mouseover", tooltip.show);
          pca.listen(element, "mouseout", tooltip.hide);
        }

        tooltip.element.appendChild(tooltip.background);
        tooltip.element.appendChild(tooltip.message);
        tooltip.setMessage(message);

        pca.append(tooltip.element);

        tooltip.hide();

        return tooltip;
      };

    /** Formats a line by replacing tags in the form {Property} with the corresponding property value or method result from the item object.
     * @memberof pca
     * @param {Object} item - An object to format the parameters of.
     * @param {string} format - A template format string.
     * @returns {string} The formatted text.
     * @example pca.formatLine({"line1": "Line One", "line2": "Line Two"}, "{line1}{, {line2}}");
     * @returns "Line One, Line Two" */
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
    pca.getElement =
      pca.getElement ||
      function (reference, base) {
        if (!reference) return null;

        if (typeof reference.nodeType == "number")
          //Is a HTML DOM Node
          return reference;

        if (typeof reference == "string") {
          base = pca.getElement(base) || document;

          var byId = base.getElementById
            ? base.getElementById(reference)
            : null;
          if (byId) return byId;

          var byName = base.getElementsByName
            ? base.getElementsByName(reference)
            : null;
          if (byName.length) return byName[0];
        }

        //try a regex match if allowed
        return pca.fuzzyMatch ? pca.getElementByRegex(reference, base) : null;
      };

    pca.matches =
      pca.matches ||
      function (element, selector) {
        if (typeof window.Element.prototype.matches === "function") {
          return element.matches(selector);
        }
        var elements = (
          element.document || element.ownerDocument
        ).querySelectorAll(selector);
        var index = 0;

        while (elements[index] && elements[index] !== element) {
          ++index;
        }

        return Boolean(elements[index]);
      };

    pca.closestElement =
      pca.closestElement ||
      function (element, selector) {
        if (typeof window.Element.prototype.closest === "function") {
          return element.closest(selector);
        }
        while (element && element.nodeType === 1) {
          if (pca.matches(element, selector)) {
            return element;
          }

          element = element.parentNode;
        }

        return null;
      };

    /** Retrieves a DOM element using RegEx matching on the id.
     * @memberof pca
     * @param {Regex|string} regex - The RegExp to test search element id for.
     * @param {string|HTMLElement} base - The id, name or parent element to search from.
     * @returns {HTMLElement} The first element found or null. */
    pca.getElementByRegex =
      pca.getElementByRegex ||
      function (regex, base) {
        //compile and check regex strings
        if (typeof regex == "string") {
          try {
            regex = new RegExp(regex);
          } catch (e) {
            return null;
          }
        }

        //make sure its a RegExp
        if (regex && typeof regex == "object" && regex.constructor === RegExp) {
          base = pca.getElement(base) || document;

          for (var t = 0; t < pca.fuzzyTags.length; t++) {
            var elements = base.getElementsByTagName(pca.fuzzyTags[t]);

            for (var i = 0; i < elements.length; i++) {
              var elem = elements[i];
              if (elem.id && regex.test(elem.id)) return elem;
            }
          }
        }

        return null;
      };

    /** Get the value of a DOM element.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to get the value of.
     * @returns {string} The value of the element. */
    pca.getValue =
      pca.getValue ||
      function (element) {
        if ((element = pca.getElement(element))) {
          if (element.tagName === "INPUT" || element.tagName === "TEXTAREA") {
            if (element.type === "checkbox") return element.checked;
            else if (element.type === "radio") {
              var group = document.getElementsByName(element.name);
              for (var r = 0; r < group.length; r++) {
                if (group[r].checked) return group[r].value;
              }
            } else return element.value;
          }
          if (element.tagName === "SELECT") {
            if (element.selectedIndex < 0) return "";
            var selectedOption = element.options[element.selectedIndex];
            return selectedOption.value || selectedOption.text || "";
          }
          if (
            element.tagName === "DIV" ||
            element.tagName === "SPAN" ||
            element.tagName === "TD" ||
            element.tagName === "LABEL"
          )
            return element.innerHTML;
        }

        return "";
      };

    pca.reactTriggerChange =
      pca.reactTriggerChange ||
      function reactTriggerChange(node) {
        //Copyright (c) 2017 Vitaly Kuznetsov
        var supportedInputTypes = {
          color: true,
          date: true,
          datetime: true,
          "datetime-local": true,
          email: true,
          month: true,
          number: true,
          password: true,
          range: true,
          search: true,
          tel: true,
          text: true,
          time: true,
          url: true,
          week: true,
        };
        var nodeName = node.nodeName.toLowerCase();
        var type = node.type;
        var event;
        var descriptor;
        var initialValue;
        var initialChecked;
        var initialCheckedRadio;

        // Do not try to delete non-configurable properties.
        // Value and checked properties on DOM elements are non-configurable in PhantomJS.
        function deletePropertySafe(elem, prop) {
          var desc = Object.getOwnPropertyDescriptor(elem, prop);
          if (desc && desc.configurable) {
            delete elem[prop];
          }
        }

        // In IE10 propertychange is not dispatched on range input if invalid
        // value is set.
        function changeRangeValue(range) {
          var initMin = range.min;
          var initMax = range.max;
          var initStep = range.step;
          var initVal = Number(range.value);

          range.min = initVal;
          range.max = initVal + 1;
          range.step = 1;
          range.value = initVal + 1;
          deletePropertySafe(range, "value");
          range.min = initMin;
          range.max = initMax;
          range.step = initStep;
          range.value = initVal;
        }

        function getCheckedRadio(radio) {
          var name = radio.name;
          var radios;
          var i;
          if (name) {
            radios = document.querySelectorAll(
              'input[type="radio"][name="' + name + '"]'
            );
            for (i = 0; i < radios.length; i += 1) {
              if (radios[i].checked) {
                return radios[i] !== radio ? radios[i] : null;
              }
            }
          }
          return null;
        }

        function preventChecking(e) {
          e.preventDefault();
          if (!initialChecked) {
            e.target.checked = false;
          }
          if (initialCheckedRadio) {
            initialCheckedRadio.checked = true;
          }
        }

        if (
          nodeName === "select" ||
          (nodeName === "input" && type === "file") ||
          (nodeName === "input" && type === "text")
        ) {
          // IE9-IE11, non-IE
          // Dispatch change.
          event = document.createEvent("HTMLEvents");
          event.initEvent("change", true, false);
          node.dispatchEvent(event);
        } else if (
          (nodeName === "input" && supportedInputTypes[type]) ||
          nodeName === "textarea"
        ) {
          // React 16
          // Cache artificial value property descriptor.
          // Property doesn't exist in React <16, descriptor is undefined.
          descriptor = Object.getOwnPropertyDescriptor(node, "value");

          // React 0.14: IE9
          // React 15: IE9-IE11
          // React 16: IE9
          // Dispatch focus.
          event = document.createEvent("UIEvents");
          event.initEvent("focus", false, false);
          node.dispatchEvent(event);

          // React 0.14: IE9
          // React 15: IE9-IE11
          // React 16
          // In IE9-10 imperative change of node value triggers propertychange event.
          // Update inputValueTracking cached value.
          // Remove artificial value property.
          // Restore initial value to trigger event with it.
          if (type === "range") {
            changeRangeValue(node);
          } else {
            initialValue = node.value;
            node.value = initialValue + "#";
            deletePropertySafe(node, "value");
            node.value = initialValue;
          }

          // React 15: IE11
          // For unknown reason React 15 added listener for propertychange with addEventListener.
          // This doesn't work, propertychange events are deprecated in IE11,
          // but allows us to dispatch fake propertychange which is handled by IE11.
          event = document.createEvent("HTMLEvents");
          event.initEvent("propertychange", false, false);
          event.propertyName = "value";
          node.dispatchEvent(event);

          // React 0.14: IE10-IE11, non-IE
          // React 15: non-IE
          // React 16: IE10-IE11, non-IE
          event = document.createEvent("HTMLEvents");
          event.initEvent("input", true, false);
          node.dispatchEvent(event);

          // React 16
          // Restore artificial value property descriptor.
          if (descriptor) {
            Object.defineProperty(node, "value", descriptor);
          }
        } else if (nodeName === "input" && type === "checkbox") {
          // Invert inputValueTracking cached value.
          node.checked = !node.checked;

          // Dispatch click.
          // Click event inverts checked value.
          event = document.createEvent("MouseEvents");
          event.initEvent("click", true, true);
          node.dispatchEvent(event);
        } else if (nodeName === "input" && type === "radio") {
          // Cache initial checked value.
          initialChecked = node.checked;

          // Find and cache initially checked radio in the group.
          initialCheckedRadio = getCheckedRadio(node);

          // React 16
          // Cache property descriptor.
          // Invert inputValueTracking cached value.
          // Remove artificial checked property.
          // Restore initial value, otherwise preventDefault will eventually revert the value.
          descriptor = Object.getOwnPropertyDescriptor(node, "checked");
          node.checked = !initialChecked;
          deletePropertySafe(node, "checked");
          node.checked = initialChecked;

          // Prevent toggling during event capturing phase.
          // Set checked value to false if initialChecked is false,
          // otherwise next listeners will see true.
          // Restore initially checked radio in the group.
          node.addEventListener("click", preventChecking, true);

          // Dispatch click.
          // Click event inverts checked value.
          event = document.createEvent("MouseEvents");
          event.initEvent("click", true, true);
          node.dispatchEvent(event);

          // Remove listener to stop further change prevention.
          node.removeEventListener("click", preventChecking, true);

          // React 16
          // Restore artificial checked property descriptor.
          if (descriptor) {
            Object.defineProperty(node, "checked", descriptor);
          }
        }
      };

    /** Set the value of a DOM element.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to set the value of.
     * @param {*} value - The value to set. */
    pca.setValue =
      pca.setValue ||
      function (element, value, simulateReactTrigger) {
        if ((value || value === "") && (element = pca.getElement(element))) {
          var valueText = value.toString(),
            valueTextMatch = pca.formatTag(valueText);

          if (element.tagName === "INPUT") {
            if (element.type === "checkbox") {
              element.checked =
                (typeof value == "boolean" && value) ||
                valueTextMatch === "TRUE";
            } else if (element.type === "radio") {
              var group = document.getElementsByName(element.name);
              for (var r = 0; r < group.length; r++) {
                if (pca.formatTag(group[r].value) === valueTextMatch) {
                  group[r].checked = true;
                  return;
                }
              }
            } else {
              element.value = pca.tidy(
                valueText.replace(/\\n|\n/gi, ", "),
                ", "
              );
            }
          } else if (element.tagName === "TEXTAREA") {
            element.value = valueText.replace(/\\n|\n/gi, "\n");
          } else if (element.tagName === "SELECT") {
            for (var s = 0; s < element.options.length; s++) {
              if (
                pca.formatTag(element.options[s].value) === valueTextMatch ||
                pca.formatTag(element.options[s].text) === valueTextMatch
              ) {
                element.selectedIndex = s;
                return;
              }
            }
          } else if (
            element.tagName === "DIV" ||
            element.tagName === "SPAN" ||
            element.tagName === "TD" ||
            element.tagName === "LABEL"
          ) {
            element.innerHTML = valueText.replace(/\\n|\n/gi, "<br/>");
          }
          if (
            simulateReactTrigger &&
            ["SELECT", "TEXTAREA", "INPUT"].indexOf(element.tagName) > -1
          ) {
            pca.reactTriggerChange(element);
          }
        }
      };

    /** Returns true if the element is a text input field.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to check.
     * @returns {boolean} True if the element supports text input. */
    pca.inputField =
      pca.inputField ||
      function (element) {
        if ((element = pca.getElement(element)))
          return (
            element.tagName &&
            (element.tagName === "INPUT" || element.tagName === "TEXTAREA") &&
            element.type &&
            (element.type === "text" ||
              element.type === "search" ||
              element.type === "email" ||
              element.type === "textarea" ||
              element.type === "number" ||
              element.type === "tel")
          );

        return false;
      };

    /** Returns true if the element is a select list field.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to check.
     * @returns {boolean} True if the element in a select list field. */
    pca.selectList =
      pca.selectList ||
      function (element) {
        if ((element = pca.getElement(element)))
          return element.tagName && element.tagName === "SELECT";

        return false;
      };

    pca.moveCursorToEnd =
      pca.moveCursorToEnd ||
      function (el) {
        if (typeof el.selectionStart == "number") {
          el.selectionStart = el.selectionEnd = el.value.length;
        } else if (typeof el.createTextRange != "undefined") {
          el.focus();
          var range = el.createTextRange();
          range.collapse(false);
          range.select();
        }
      };

    /** Returns the current selected item of a select list field.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to check.
     * @returns {HTMLOptionElement} The current selected item. */
    pca.getSelectedItem =
      pca.getSelectedItem ||
      function (element) {
        if (
          (element = pca.getElement(element)) &&
          element.tagName === "SELECT" &&
          element.selectedIndex >= 0
        )
          return element.options[element.selectedIndex];

        return null;
      };

    /** Returns true if the element is a checkbox.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to check.
     * @returns {boolean} True if the element in a checkbox. */
    pca.checkBox =
      pca.checkBox ||
      function (element) {
        if ((element = pca.getElement(element)))
          return (
            element.tagName &&
            element.tagName === "INPUT" &&
            element.type &&
            element.type === "checkbox"
          );

        return false;
      };

    /** Shortcut to clear the value of a DOM element.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to clear. */
    pca.clear =
      pca.clear ||
      function (element) {
        pca.setValue(element, "");
        return pca;
      };

    /** Get the position of a DOM element.
     * @memberof pca
     * @param {string|HTMLElement} element - The element to get the position of.
     * @returns {Object} The top and left of the position. */
    pca.getPosition =
      pca.getPosition ||
      function (element) {
        var empty = { left: 0, top: 0 };

        if ((element = pca.getElement(element))) {
          if (!element.tagName) return empty;

          if (typeof element.getBoundingClientRect != "undefined") {
            var bb = element.getBoundingClientRect(),
              fixed = !pca.isPage(pca.getTopOffsetParent(element)),
              pageScroll = pca.getScroll(window),
              parentScroll = pca.getParentScroll(element);
            return {
              left: bb.left + parentScroll.left + (fixed ? 0 : pageScroll.left),
              top: bb.top + parentScroll.top + (fixed ? 0 : pageScroll.top),
            };
          }

          var x = 0,
            y = 0;

          do {
            x += element.offsetLeft;
            y += element.offsetTop;
          } while ((element = element.offsetParent));

          return { left: x, top: y };
        }

        return empty;
      };

    //Is the element the document or window.
    pca.isPage =
      pca.isPage ||
      function (element) {
        return (
          element === window ||
          element === document ||
          element === document.body
        );
      };

    /** Gets the scroll values from an elements top offset parent.
     * @memberof pca
     * @param {HTMLElement} element - The element to get the scroll of.
     * @returns {Object} The top and left of the scroll. */
    pca.getScroll =
      pca.getScroll ||
      function (element) {
        return {
          left:
            parseInt(element.scrollX || element.scrollLeft, 10) ||
            (pca.isPage(element)
              ? parseInt(document.documentElement.scrollLeft) || 0
              : 0),
          top:
            parseInt(element.scrollY || element.scrollTop, 10) ||
            (pca.isPage(element)
              ? parseInt(document.documentElement.scrollTop) || 0
              : 0),
        };
      };

    /** Get the height and width of a DOM element.
     * @memberof pca
     * @param {HTMLElement} element - The element to get the size of.
     * @returns {Object} The height and width of the element. */
    pca.getSize =
      pca.getSize ||
      function (element) {
        return {
          height:
            element.offsetHeight ||
            element.innerHeight ||
            (pca.isPage(element)
              ? document.documentElement.clientHeight ||
                document.body.clientHeight
              : 0),
          width:
            element.offsetWidth ||
            element.innerWidth ||
            (pca.isPage(element)
              ? document.documentElement.clientWidth ||
                document.body.clientWidth
              : 0),
        };
      };

    /** Get the scroll value for all parent elements.
     * @memberof pca
     * @param {HTMLElement|string} element - The child element to begin from.
     * @returns {Object} The top and left of the scroll. */
    pca.getParentScroll =
      pca.getParentScroll ||
      function (element) {
        var empty = { left: 0, top: 0 };

        if ((element = pca.getElement(element))) {
          if (!element.tagName) return empty;
          if (!(element = element.parentNode)) return empty;

          var x = 0,
            y = 0;

          do {
            if (pca.isPage(element)) break;
            x += parseInt(element.scrollLeft) || 0;
            y += parseInt(element.scrollTop) || 0;
          } while ((element = element.parentNode));

          return { left: x, top: y };
        }

        return empty;
      };

    /** Get the element which an element is positioned relative to.
     * @memberof pca
     * @param {HTMLElement} element - The child element to begin from.
     * @returns {HTMLElement} The element controlling the relative position. */
    pca.getTopOffsetParent =
      pca.getTopOffsetParent ||
      function (element) {
        while (element.offsetParent) {
          element = element.offsetParent;

          //fix for Firefox
          if (pca.getStyle(element, "position") === "fixed") break;
        }

        return element;
      };

    /** Gets the current value of a style property of an element.
     * @memberof pca
     * @param {HTMLElement} element - The element to get the style property of.
     * @param {string} property - The name of the style property to query.
     * @returns {string} The value of the style property. */
    pca.getStyle =
      pca.getStyle ||
      function (element, property) {
        return (
          ((window.getComputedStyle
            ? window.getComputedStyle(element)
            : element.currentStyle) || {})[property] || ""
        );
      };

    /** Adds a CSS class to an element.
     * @memberof pca
     * @param {HTMLElement|string} element - The element to add the style class to.
     * @param {string} className - The name of the style class to add. */
    pca.addClass =
      pca.addClass ||
      function (element, className) {
        if ((element = pca.getElement(element))) {
          if (!pca.containsWord(element.className || "", className))
            element.className += (element.className ? " " : "") + className;
        }
      };

    /** Removes a CSS class from an element.
     * @memberof pca
     * @param {HTMLElement|string} element - The element to remove the style class from.
     * @param {string} className - The name of the style class to remove. */
    pca.removeClass =
      pca.removeClass ||
      function (element, className) {
        if ((element = pca.getElement(element)))
          element.className = pca.removeWord(element.className, className);
      };

    /** Sets an attribute of an element.
     * @memberof pca
     * @param {HTMLElement|string} element - The element to set the attribute of.
     * @param {string} attribute - The element attribute to set.
     * @param {Object} attribute - The value to set. */
    pca.setAttribute =
      pca.setAttribute ||
      function (element, attribute, value) {
        if ((element = pca.getElement(element)))
          element.setAttribute(attribute, value);
      };

    /** Sets multiple attributes of an element.
     * @memberof pca
     * @param {HTMLElement|string} element - The element to set the attributes of.
     * @param {Object} attributes - The element attributes and values to set. */
    pca.setAttributes =
      pca.setAttributes ||
      function (element, attributes) {
        if ((element = pca.getElement(element))) {
          for (var i in attributes) element.setAttribute(i, attributes[i]);
        }
      };

    /** Applies fixes to a style sheet.
     * This will add them to the fixes list for pca.reapplyStyleFixes.
     * @memberof pca
     * @param {string} selectorText - The full CSS selector text for the rule as it appears in the style sheet.
     * @param {Object} fixes - An object with JavaScript style property name and value. */
    pca.applyStyleFixes =
      pca.applyStyleFixes ||
      function (selectorText, fixes) {
        for (var s = 0; s < document.styleSheets.length; s++) {
          var sheet = document.styleSheets[s],
            rules = [];

          try {
            rules = sheet.rules || sheet.cssRules || []; //possible denial of access if script and css are hosted separately
          } catch (e) {}

          for (var r = 0; r < rules.length; r++) {
            var rule = rules[r];

            if (rule.selectorText.toLowerCase() === selectorText) {
              for (var f in fixes) rule.style[f] = fixes[f];
            }
          }
        }

        pca.styleFixes.push({ selectorText: selectorText, fixes: fixes });
      };

    /** Reapplies all fixes to style sheets added by pca.applyStyleFixes.
     * @memberof pca */
    pca.reapplyStyleFixes =
      pca.reapplyStyleFixes ||
      function () {
        var fixesList = pca.styleFixes;

        pca.styleFixes = [];

        for (var i = 0; i < fixesList.length; i++)
          pca.applyStyleFixes(fixesList[i].selectorText, fixesList[i].fixes);
      };

    /** Creates a style sheet from cssText.
     * @memberof pca
     * @param {string} cssText - The CSS text for the body of the style sheet. */
    pca.createStyleSheet =
      pca.createStyleSheet ||
      function (cssText) {
        if (document.createStyleSheet)
          document.createStyleSheet().cssText = cssText;
        else
          document.head.appendChild(
            pca.create("style", { type: "text/css", innerHTML: cssText })
          );
      };

    /** Simple short function to create an element.
     * @memberof pca
     * @param {string} tag - The HTML tag for the element.
     * @param {Object} properties - The properties to set in JavaScript form.
     * @param {string} cssText - Any CSS to add the style property.
     * @returns {HTMLElement} The created element. */
    pca.create =
      pca.create ||
      function (tag, properties, cssText) {
        var elem = document.createElement(tag);
        for (var i in properties || {}) elem[i] = properties[i];
        if (cssText) elem.style.cssText = cssText;
        return elem;
      };

    /** Adds an element to the pca container on the page.
     * If the container does not exist it is created.
     * @memberof pca
     * @param {HTMLElement} element - The element to add to the container. */
    pca.append =
      pca.append ||
      function (element) {
        if (!pca.container) {
          pca.container = pca.create("div", { className: "pca" });
          document.body.appendChild(pca.container);
          pca.liveA11y = pca.create("div", {
            className: "pca_live_a11y pca-visually-hidden",
          });
          pca.liveA11y.setAttribute("aria-live", "polite");
          pca.liveA11y.setAttribute("aria-atomic", "true");
          pca.liveA11y.setAttribute("role", "status");
          pca.container.appendChild(pca.liveA11y);
        }

        pca.container.appendChild(element);
      };

    pca.read = function (text) {
      pca.liveA11y.innerText = "";
      setTimeout(function () {
        pca.liveA11y.innerText = text;
      }, 1000);
    };

    /** Removes an element from the container on the page.
     * @memberof pca
     * @param {HTMLElement} element - The element to remove from the container. */
    pca.remove =
      pca.remove ||
      function (element) {
        if (
          element &&
          element.parentNode &&
          element.parentNode === pca.container
        )
          pca.container.removeChild(element);
      };

    /** Listens to an event with standard DOM event handling.
     * @memberof pca
     * @param {HTMLElement} target - The element to listen to.
     * @param {string} event - The name of the event to listen for, e.g. "click".
     * @param {pca.Eventable~eventHandler} action - The callback for this event.
     * @param {boolean} capture - Use event capturing. */
    pca.listen =
      pca.listen ||
      function (target, event, action, capture) {
        target.addEventListener(event, action, capture);
      };

    /** Creates and fires a standard DOM event.
     * @memberof pca
     * @param {HTMLElement} target - The element to trigger the event for.
     * @param {string} event - The name of the event, e.g. "click".
     * @returns {boolean} False is the event was stopped by any of its handlers. */
    pca.fire =
      pca.fire ||
      function (target, event) {
        if (document.createEvent) {
          var e = document.createEvent("HTMLEvents");
          e.initEvent(event, true, true);
          return !target.dispatchEvent(e);
        } else
          return target.fireEvent("on" + event, document.createEventObject());
      };

    /** Removes listeners for an event with standard DOM event handling.
     * @memberof pca
     * @param {HTMLElement} target - The element.
     * @param {string} event - The name of the event, e.g. "click".
     * @param {pca.Eventable~eventHandler} action - The callback to remove for this event. */
    pca.ignore =
      pca.ignore ||
      function (target, event, action) {
        if (window.removeEventListener)
          target.removeEventListener(event, action);
        else target.detachEvent("on" + event, action);
      };

    /** Stops other actions of an event.
     * @memberof pca
     * @param {Event} event - The event to stop. */
    pca.smash =
      pca.smash ||
      function (event) {
        var e = event || window.event;
        e.stopPropagation ? e.stopPropagation() : (e.cancelBubble = true);
        e.preventDefault ? e.preventDefault() : (e.returnValue = false);
      };

    /** Debug messages to the console.
     * @memberof pca
     * @param {string} message - The debug message text. */
    pca.debug =
      pca.debug ||
      function (message) {
        if (typeof console != "undefined" && console.debug)
          console.debug(message);
      };

    /** Creates and returns are new debounced version of the passed function, which will postpone
     * its execution until after the 'delay' milliseconds have elapsed since this last time the function was
     * invoked. (-- PORT FROM underscore.js with some tweaks to support IE8 events--)
     * @memberof pca
     * @param {function} func - The funcion to call when the timeout has elapsed.
     * @param {integer} wait - The number of milliseconds to wait between calling the function.
     * @param {integer} immediate - An ovveride to call the function immediately. */
    pca.debounce =
      pca.debounce ||
      function (func, wait, immediate) {
        var timeout;
        return function () {
          var context = this;

          var args = arguments;

          if (arguments && arguments.length > 0) {
            args = [{ target: arguments[0].target || arguments[0].srcElement }];
          }

          var later = function () {
            timeout = null;
            if (!immediate) func.apply(context, args);
          };
          var callNow = immediate && !timeout;
          clearTimeout(timeout);
          timeout = setTimeout(later, wait);
          if (callNow) func.apply(context, args);
        };
      };

    /** Returns whether or not a particular function is defined.
     * @memberof pca
     * @param {function} func - The function to check */
    pca.defined =
      pca.defined ||
      function (func) {
        return typeof func == "function";
      };

    /** Returns whether or not a particular function is undefined.
     * @memberof pca
     * @param {function} fn - The function to check */
    pca.fnDefined = pca.defined;

    /** Returns the label element for a given DOM element.
     * @memberof pca
     * @param {string} elementNameOrId - The name or ID of the DOM element. */
    pca.getLabel =
      pca.getLabel ||
      function (elementNameOrId) {
        var labels = document.getElementsByTagName("LABEL");
        for (var i = 0; i < labels.length; i++) {
          if (labels[i].htmlFor !== "") {
            var elem = pca.getElement(labels[i].htmlFor);

            if (
              (elem && elem.name === elementNameOrId) ||
              elem.id === elementNameOrId
            )
              return labels[i];
          }
        }
        return null;
      };

    //get some reference to an element that we can use later in getElement
    pca.getReferenceToElement =
      pca.getReferenceToElement ||
      function (element) {
        return typeof element == "string"
          ? element
          : element
          ? element.id || element.name || ""
          : "";
      };

    /**
     * Extends one object into another, any number of objects can be supplied
     * To create a new object supply an empty object as the first argument
     * @param {Object} obj - The object to add properties to.
     * @param {Object} [sources] - One or more objects to take properties from.
     * @returns {Object} The destination object.
     */
    pca.extend =
      pca.extend ||
      function (obj /*...*/) {
        for (var i = 1; i < arguments.length; i++) {
          for (var key in arguments[i]) {
            if (arguments[i].hasOwnProperty(key)) obj[key] = arguments[i][key];
          }
        }

        return obj;
      };

    /**
     * Gets even inherited styles from element
     * @param {} element - The element to get the style for
     * @param {} styleProperty - The style property to be got, in the original css form
     * @returns {}
     */
    pca.getStyle =
      pca.getStyle ||
      function (element, styleProperty) {
        var camelize = function (str) {
          return str.replace(/\-(\w)/g, function (str, letter) {
            return letter.toUpperCase();
          });
        };

        if (element.currentStyle) {
          return element.currentStyle[camelize(styleProperty)];
        } else if (
          document.defaultView &&
          document.defaultView.getComputedStyle
        ) {
          return document.defaultView
            .getComputedStyle(element, null)
            .getPropertyValue(styleProperty);
        } else {
          return element.style[camelize(styleProperty)];
        }
      };

    /**
     * Detects browser support for a predefined list of capabilities
     * @param {string} checkType - The check to perform
     * @returns {boolean} Wether the given type is supported.
     */
    pca.supports =
      pca.supports ||
      function (checkType) {
        switch (checkType) {
          case "reverseGeo":
            return (
              document.location.protocol == "https:" &&
              window.navigator &&
              window.navigator.geolocation
            );
        }
        return false;
      };

    pca.guid =
      pca.guid ||
      (function () {
        /**
         * Generates a new guid
         * @method s4
         * @return CallExpression
         */
        function s4() {
          return Math.floor((1 + Math.random()) * 0x10000)
            .toString(16)
            .substring(1);
        }
        return function () {
          return (
            s4() +
            s4() +
            "-" +
            s4() +
            "-" +
            s4() +
            "-" +
            s4() +
            "-" +
            s4() +
            s4() +
            s4()
          );
        };
      })();

    pca.sessionId = pca.sessionId || pca.guid();

    //load when the document is ready
    checkDocumentLoad();
  })(window);
  /*3.92 - No changes, just to move inline with update version*/
  (function () {
    var pca = (window.pca = window.pca || {});

    /**
     * Details of a country.
     * @typedef {Object} pca.Country
     * @property {string} iso2 - The ISO 2-char code, e.g. GB.
     * @property {string} iso3 - The ISO 3-char code, e.g. GBR.
     * @property {string} name - The full country name.
     * @property {number} flag - Flag index.
     * @property {Array.<string>} [alternates] - Any alternate names for the country.
     */

    /** The list of countries.
     * @memberof pca
     * @type {Array.<pca.Country>} */
    pca.countries = [
      {
        iso2: "AF",
        iso3: "AFG",
        name: "Afghanistan",
        name_fr: "Afghanistan",
        flag: 1,
      },
      {
        iso2: "AX",
        iso3: "ALA",
        name: "Åland",
        name_fr: "Åland(les Îles)",
        flag: 220,
      },
      {
        iso2: "AL",
        iso3: "ALB",
        name: "Albania",
        name_fr: "Albanie",
        alternates: ["Shqipëria"],
        flag: 2,
      },
      { iso2: "DZ", iso3: "DZA", name: "Algeria", name_fr: "Algérie", flag: 3 },
      {
        iso2: "AS",
        iso3: "ASM",
        name: "American Samoa",
        name_fr: "Samoa américaines",
        flag: 4,
      },
      { iso2: "AD", iso3: "AND", name: "Andorra", name_fr: "Andorre", flag: 5 },
      { iso2: "AO", iso3: "AGO", name: "Angola", name_fr: "Angola", flag: 6 },
      {
        iso2: "AI",
        iso3: "AIA",
        name: "Anguilla",
        name_fr: "Anguilla",
        flag: 7,
      },
      {
        iso2: "AQ",
        iso3: "ATA",
        name: "Antarctica",
        name_fr: "Antarctique",
        flag: 0,
      },
      {
        iso2: "AG",
        iso3: "ATG",
        name: "Antigua and Barbuda",
        name_fr: "Antigua-et-Barbuda",
        flag: 8,
      },
      {
        iso2: "AR",
        iso3: "ARG",
        name: "Argentina",
        name_fr: "Argentine",
        flag: 9,
      },
      {
        iso2: "AM",
        iso3: "ARM",
        name: "Armenia",
        name_fr: "Arménie",
        flag: 10,
      },
      { iso2: "AW", iso3: "ABW", name: "Aruba", name_fr: "Aruba", flag: 11 },
      {
        iso2: "AU",
        iso3: "AUS",
        name: "Australia",
        name_fr: "Australie",
        flag: 12,
      },
      {
        iso2: "AT",
        iso3: "AUT",
        name: "Austria",
        name_fr: "Autriche",
        alternates: ["Österreich"],
        flag: 13,
      },
      {
        iso2: "AZ",
        iso3: "AZE",
        name: "Azerbaijan",
        name_fr: "Azerbaïdjan",
        flag: 14,
      },
      {
        iso2: "BS",
        iso3: "BHS",
        name: "Bahamas",
        name_fr: "Bahamas",
        flag: 15,
      },
      {
        iso2: "BH",
        iso3: "BHR",
        name: "Bahrain",
        name_fr: "Bahreïn",
        flag: 16,
      },
      {
        iso2: "BD",
        iso3: "BGD",
        name: "Bangladesh",
        name_fr: "Bangladesh",
        flag: 17,
      },
      {
        iso2: "BB",
        iso3: "BRB",
        name: "Barbados",
        name_fr: "Barbade",
        flag: 18,
      },
      {
        iso2: "BY",
        iso3: "BLR",
        name: "Belarus",
        name_fr: "Bélarus",
        flag: 19,
      },
      {
        iso2: "BE",
        iso3: "BEL",
        name: "Belgium",
        name_fr: "Belgique",
        alternates: ["België"],
        flag: 20,
      },
      { iso2: "BZ", iso3: "BLZ", name: "Belize", name_fr: "Belize", flag: 21 },
      { iso2: "BJ", iso3: "BEN", name: "Benin", name_fr: "Bénin", flag: 22 },
      {
        iso2: "BM",
        iso3: "BMU",
        name: "Bermuda",
        name_fr: "Bermudes",
        flag: 23,
      },
      { iso2: "BT", iso3: "BTN", name: "Bhutan", name_fr: "Bhoutan", flag: 24 },
      {
        iso2: "BO",
        iso3: "BOL",
        name: "Bolivia",
        name_fr: "Bolivie, l'État plurinational de la",
        flag: 25,
      },
      {
        iso2: "BQ",
        iso3: "BES",
        name: "Bonaire, Sint Eustatius and Saba",
        name_fr: "Bonaire, Saint-Eustache et Saba",
        flag: 0,
      },
      {
        iso2: "BA",
        iso3: "BIH",
        name: "Bosnia and Herzegovina",
        name_fr: "Bosnie-Herzégovine",
        alternates: ["Bosna i Hercegovina"],
        flag: 26,
      },
      {
        iso2: "BW",
        iso3: "BWA",
        name: "Botswana",
        name_fr: "Botswana",
        flag: 27,
      },
      {
        iso2: "BV",
        iso3: "BVT",
        name: "Bouvet Island",
        name_fr: "Bouvet (l'Île)",
        flag: 0,
      },
      {
        iso2: "BR",
        iso3: "BRA",
        name: "Brazil",
        name_fr: "Brésil",
        alternates: ["Brasil"],
        flag: 28,
      },
      {
        iso2: "IO",
        iso3: "IOT",
        name: "British Indian Ocean Territory",
        name_fr: "Indien (le Territoire britannique de l'océan)",
        flag: 29,
      },
      {
        iso2: "VG",
        iso3: "VGB",
        name: "British Virgin Islands",
        name_fr: "Vierges britanniques (les Îles)",
        flag: 30,
      },
      { iso2: "BN", iso3: "BRN", name: "Brunei", name_fr: "Brunei", flag: 0 },
      {
        iso2: "BG",
        iso3: "BGR",
        name: "Bulgaria",
        name_fr: "Bulgarie",
        flag: 31,
      },
      {
        iso2: "BF",
        iso3: "BFA",
        name: "Burkina Faso",
        name_fr: "Burkina Faso",
        flag: 32,
      },
      {
        iso2: "BI",
        iso3: "BDI",
        name: "Burundi",
        name_fr: "Burundi",
        flag: 34,
      },
      {
        iso2: "KH",
        iso3: "KHM",
        name: "Cambodia",
        name_fr: "Cambodge",
        flag: 35,
      },
      {
        iso2: "CM",
        iso3: "CMR",
        name: "Cameroon",
        name_fr: "Cameroun",
        flag: 36,
      },
      { iso2: "CA", iso3: "CAN", name: "Canada", name_fr: "Canada", flag: 37 },
      {
        iso2: "CV",
        iso3: "CPV",
        name: "Cape Verde",
        name_fr: "Cabo Verde",
        flag: 38,
      },
      {
        iso2: "KY",
        iso3: "CYM",
        name: "Cayman Islands",
        name_fr: "Caïmans (les Îles)",
        flag: 39,
      },
      {
        iso2: "CF",
        iso3: "CAF",
        name: "Central African Republic",
        name_fr: "République centrafricaine",
        flag: 40,
      },
      { iso2: "TD", iso3: "TCD", name: "Chad", name_fr: "Tchad", flag: 41 },
      { iso2: "CL", iso3: "CHL", name: "Chile", name_fr: "Chili", flag: 42 },
      { iso2: "CN", iso3: "CHN", name: "China", name_fr: "Chine", flag: 43 },
      {
        iso2: "CX",
        iso3: "CXR",
        name: "Christmas Island",
        name_fr: "Christmas (l'Île)",
        flag: 0,
      },
      {
        iso2: "CC",
        iso3: "CCK",
        name: "Cocos (Keeling) Islands",
        name_fr: "Cocos (les Îles)/ Keeling (les Îles)",
        flag: 0,
      },
      {
        iso2: "CO",
        iso3: "COL",
        name: "Colombia",
        name_fr: "Colombie",
        flag: 44,
      },
      {
        iso2: "KM",
        iso3: "COM",
        name: "Comoros",
        name_fr: "Comores",
        flag: 45,
      },
      { iso2: "CG", iso3: "COG", name: "Congo", name_fr: "Congo", flag: 0 },
      {
        iso2: "CD",
        iso3: "COD",
        name: "Congo (Democratic Republic)",
        name_fr: "Congo (la République démocratique du)",
        flag: 46,
      },
      {
        iso2: "CK",
        iso3: "COK",
        name: "Cook Islands",
        name_fr: "Cook (les Îles)",
        flag: 47,
      },
      {
        iso2: "CR",
        iso3: "CRI",
        name: "Costa Rica",
        name_fr: "Costa Rica",
        flag: 48,
      },
      {
        iso2: "HR",
        iso3: "HRV",
        name: "Croatia",
        name_fr: "Croatie",
        alternates: ["Hrvatska"],
        flag: 50,
      },
      { iso2: "CU", iso3: "CUB", name: "Cuba", name_fr: "Cuba", flag: 51 },
      { iso2: "CW", iso3: "CUW", name: "Curaçao", name_fr: "Curaçao", flag: 0 },
      { iso2: "CY", iso3: "CYP", name: "Cyprus", name_fr: "Chypre", flag: 52 },
      {
        iso2: "CZ",
        iso3: "CZE",
        name: "Czechia",
        name_fr: "tchèque (la République)",
        alternates: ["Ceská republika"],
        flag: 53,
      },
      {
        iso2: "DK",
        iso3: "DNK",
        name: "Denmark",
        name_fr: "Danemark",
        flag: 54,
      },
      {
        iso2: "DJ",
        iso3: "DJI",
        name: "Djibouti",
        name_fr: "Djibouti",
        flag: 55,
      },
      {
        iso2: "DM",
        iso3: "DMA",
        name: "Dominica",
        name_fr: "Dominique",
        flag: 56,
      },
      {
        iso2: "DO",
        iso3: "DOM",
        name: "Dominican Republic",
        name_fr: "dominicaine (la République)",
        flag: 57,
      },
      {
        iso2: "TL",
        iso3: "TLS",
        name: "East Timor",
        name_fr: "Timor-Leste",
        flag: 0,
      },
      {
        iso2: "EC",
        iso3: "ECU",
        name: "Ecuador",
        name_fr: "Équateur",
        flag: 61,
      },
      { iso2: "EG", iso3: "EGY", name: "Egypt", name_fr: "Égypte", flag: 58 },
      {
        iso2: "SV",
        iso3: "SLV",
        name: "El Salvador",
        name_fr: "Salvador",
        flag: 59,
      },
      {
        iso2: "GQ",
        iso3: "GNQ",
        name: "Equatorial Guinea",
        name_fr: "Guinée équatoriale",
        flag: 62,
      },
      {
        iso2: "ER",
        iso3: "ERI",
        name: "Eritrea",
        name_fr: "Érythrée",
        flag: 63,
      },
      {
        iso2: "EE",
        iso3: "EST",
        name: "Estonia",
        name_fr: "Estonie",
        alternates: ["Eesti"],
        flag: 64,
      },
      {
        iso2: "SZ",
        iso3: "SWZ",
        name: "Eswatini",
        name_fr: "Eswatini",
        flag: 191,
      },
      {
        iso2: "ET",
        iso3: "ETH",
        name: "Ethiopia",
        name_fr: "Éthiopie",
        flag: 65,
      },
      {
        iso2: "FK",
        iso3: "FLK",
        name: "Falkland Islands",
        name_fr: "Falkland (les Îles)/Malouines (les Îles)",
        flag: 66,
      },
      {
        iso2: "FO",
        iso3: "FRO",
        name: "Faroe Islands",
        name_fr: "Féroé (les Îles)",
        flag: 67,
      },
      { iso2: "FJ", iso3: "FJI", name: "Fiji", name_fr: "Fidji", flag: 68 },
      {
        iso2: "FI",
        iso3: "FIN",
        name: "Finland",
        name_fr: "Finlande",
        alternates: ["Suomi"],
        flag: 69,
      },
      { iso2: "FR", iso3: "FRA", name: "France", name_fr: "France", flag: 70 },
      {
        iso2: "GF",
        iso3: "GUF",
        name: "French Guiana",
        name_fr: "Guyane française ",
        flag: 0,
      },
      {
        iso2: "PF",
        iso3: "PYF",
        name: "French Polynesia",
        name_fr: "Polynésie française",
        flag: 71,
      },
      {
        iso2: "TF",
        iso3: "ATF",
        name: "French Southern Territories",
        name_fr: "Terres australes françaises",
        flag: 0,
      },
      { iso2: "GA", iso3: "GAB", name: "Gabon", name_fr: "Gabon", flag: 72 },
      { iso2: "GM", iso3: "GMB", name: "Gambia", name_fr: "Gambie", flag: 73 },
      {
        iso2: "GE",
        iso3: "GEO",
        name: "Georgia",
        name_fr: "Géorgie",
        flag: 74,
      },
      {
        iso2: "DE",
        iso3: "DEU",
        name: "Germany",
        name_fr: "Allemagne",
        alternates: ["Deutschland"],
        flag: 75,
      },
      { iso2: "GH", iso3: "GHA", name: "Ghana", name_fr: "Ghana", flag: 76 },
      {
        iso2: "GI",
        iso3: "GIB",
        name: "Gibraltar",
        name_fr: "Gibraltar",
        flag: 77,
      },
      {
        iso2: "GR",
        iso3: "GRC",
        name: "Greece",
        name_fr: "Grèce",
        alternates: ["Hellas"],
        flag: 79,
      },
      {
        iso2: "GL",
        iso3: "GRL",
        name: "Greenland",
        name_fr: "Groenland",
        flag: 80,
      },
      {
        iso2: "GD",
        iso3: "GRD",
        name: "Grenada",
        name_fr: "Grenade",
        flag: 81,
      },
      {
        iso2: "GP",
        iso3: "GLP",
        name: "Guadeloupe",
        name_fr: "Guadeloupe",
        flag: 0,
      },
      { iso2: "GU", iso3: "GUM", name: "Guam", name_fr: "Guam", flag: 82 },
      {
        iso2: "GT",
        iso3: "GTM",
        name: "Guatemala",
        name_fr: "Guatemala",
        flag: 83,
      },
      {
        iso2: "GG",
        iso3: "GGY",
        name: "Guernsey",
        name_fr: "Guernesey",
        flag: 84,
      },
      { iso2: "GN", iso3: "GIN", name: "Guinea", name_fr: "Guinée", flag: 85 },
      {
        iso2: "GW",
        iso3: "GNB",
        name: "Guinea-Bissau",
        name_fr: "Guinée-Bissau",
        flag: 86,
      },
      { iso2: "GY", iso3: "GUY", name: "Guyana", name_fr: "Guyana", flag: 87 },
      { iso2: "HT", iso3: "HTI", name: "Haiti", name_fr: "Haïti", flag: 88 },
      {
        iso2: "HM",
        iso3: "HMD",
        name: "Heard Island and McDonald Islands",
        name_fr: "Heard-et-Îles MacDonald (l'Île)",
        flag: 0,
      },
      {
        iso2: "HN",
        iso3: "HND",
        name: "Honduras",
        name_fr: "Honduras",
        flag: 89,
      },
      {
        iso2: "HK",
        iso3: "HKG",
        name: "Hong Kong",
        name_fr: "Hong Kong",
        flag: 90,
      },
      {
        iso2: "HU",
        iso3: "HUN",
        name: "Hungary",
        name_fr: "Hongrie",
        alternates: ["Magyarország"],
        flag: 91,
      },
      {
        iso2: "IS",
        iso3: "ISL",
        name: "Iceland",
        name_fr: "Islande",
        alternates: ["Ísland"],
        flag: 92,
      },
      { iso2: "IN", iso3: "IND", name: "India", name_fr: "Inde", flag: 93 },
      {
        iso2: "ID",
        iso3: "IDN",
        name: "Indonesia",
        name_fr: "Indonésie",
        flag: 94,
      },
      {
        iso2: "IR",
        iso3: "IRN",
        name: "Iran",
        name_fr: "Iran (République Islamique d')",
        flag: 95,
      },
      { iso2: "IQ", iso3: "IRQ", name: "Iraq", name_fr: "Iraq", flag: 96 },
      {
        iso2: "IE",
        iso3: "IRL",
        name: "Ireland",
        name_fr: "Irlande",
        flag: 97,
      },
      {
        iso2: "IM",
        iso3: "IMN",
        name: "Isle of Man",
        name_fr: "Île de Man",
        flag: 98,
      },
      { iso2: "IL", iso3: "ISR", name: "Israel", name_fr: "Israël", flag: 99 },
      {
        iso2: "IT",
        iso3: "ITA",
        name: "Italy",
        name_fr: "Italie",
        alternates: ["Italia"],
        flag: 100,
      },
      {
        iso2: "CI",
        iso3: "CIV",
        name: "Ivory Coast",
        name_fr: "Côte d'Ivoire",
        flag: 49,
      },
      {
        iso2: "JM",
        iso3: "JAM",
        name: "Jamaica",
        name_fr: "Jamaïque",
        flag: 101,
      },
      { iso2: "JP", iso3: "JPN", name: "Japan", name_fr: "Japon", flag: 102 },
      { iso2: "JE", iso3: "JEY", name: "Jersey", name_fr: "Jersey", flag: 103 },
      {
        iso2: "JO",
        iso3: "JOR",
        name: "Jordan",
        name_fr: "Jordanie",
        flag: 104,
      },
      {
        iso2: "KZ",
        iso3: "KAZ",
        name: "Kazakhstan",
        name_fr: "Kazakhstan",
        flag: 105,
      },
      { iso2: "KE", iso3: "KEN", name: "Kenya", name_fr: "Kenya", flag: 106 },
      {
        iso2: "KI",
        iso3: "KIR",
        name: "Kiribati",
        name_fr: "Kiribati",
        flag: 107,
      },
      {
        iso2: "KP",
        iso3: "PRK",
        name: "Korea (the Democratic People's Republic of)",
        name_fr: "Corée (la République populaire démocratique de )",
        flag: 149,
      },
      {
        iso2: "KR",
        iso3: "KOR",
        name: "Korea (the Republic of)",
        name_fr: "Corée (la République de)",
        flag: 185,
      },
      { iso2: "XK", iso3: "XKX", name: "Kosovo", name_fr: "Kosovo", flag: 0 },
      { iso2: "KW", iso3: "KWT", name: "Kuwait", name_fr: "Koweït", flag: 108 },
      {
        iso2: "KG",
        iso3: "KGZ",
        name: "Kyrgyzstan",
        name_fr: "Kirghizistan",
        flag: 109,
      },
      {
        iso2: "LA",
        iso3: "LAO",
        name: "Laos",
        name_fr: "Lao, République démocratique populaire",
        flag: 0,
      },
      {
        iso2: "LV",
        iso3: "LVA",
        name: "Latvia",
        name_fr: "Lettonie",
        flag: 110,
      },
      { iso2: "LB", iso3: "LBN", name: "Lebanon", name_fr: "Liban", flag: 111 },
      {
        iso2: "LS",
        iso3: "LSO",
        name: "Lesotho",
        name_fr: "Lesotho",
        flag: 112,
      },
      {
        iso2: "LR",
        iso3: "LBR",
        name: "Liberia",
        name_fr: "Libéria",
        flag: 113,
      },
      { iso2: "LY", iso3: "LBY", name: "Libya", name_fr: "Libye", flag: 114 },
      {
        iso2: "LI",
        iso3: "LIE",
        name: "Liechtenstein",
        name_fr: "Liechtenstein",
        flag: 115,
      },
      {
        iso2: "LT",
        iso3: "LTU",
        name: "Lithuania",
        name_fr: "Lituanie",
        alternates: ["Lietuva"],
        flag: 116,
      },
      {
        iso2: "LU",
        iso3: "LUX",
        name: "Luxembourg",
        name_fr: "Luxembourg",
        flag: 117,
      },
      { iso2: "MO", iso3: "MAC", name: "Macao", name_fr: "Macao", flag: 118 },
      {
        iso2: "MG",
        iso3: "MDG",
        name: "Madagascar",
        name_fr: "Madagascar",
        flag: 120,
      },
      { iso2: "MW", iso3: "MWI", name: "Malawi", name_fr: "Malawi", flag: 121 },
      {
        iso2: "MY",
        iso3: "MYS",
        name: "Malaysia",
        name_fr: "Malaisie",
        flag: 122,
      },
      {
        iso2: "MV",
        iso3: "MDV",
        name: "Maldives",
        name_fr: "Maldives",
        flag: 123,
      },
      { iso2: "ML", iso3: "MLI", name: "Mali", name_fr: "Mali", flag: 124 },
      { iso2: "MT", iso3: "MLT", name: "Malta", name_fr: "Malte", flag: 125 },
      {
        iso2: "MH",
        iso3: "MHL",
        name: "Marshall Islands",
        name_fr: "Marshall (Îles)",
        flag: 126,
      },
      {
        iso2: "MQ",
        iso3: "MTQ",
        name: "Martinique",
        name_fr: "Martinique",
        flag: 127,
      },
      {
        iso2: "MR",
        iso3: "MRT",
        name: "Mauritania",
        name_fr: "Mauritanie",
        flag: 128,
      },
      {
        iso2: "MU",
        iso3: "MUS",
        name: "Mauritius",
        name_fr: "Maurice",
        flag: 129,
      },
      { iso2: "YT", iso3: "MYT", name: "Mayotte", name_fr: "Mayotte", flag: 0 },
      {
        iso2: "MX",
        iso3: "MEX",
        name: "Mexico",
        name_fr: "Mexique",
        flag: 130,
      },
      {
        iso2: "FM",
        iso3: "FSM",
        name: "Micronesia",
        name_fr: "Micronésie, États fédérés de",
        flag: 131,
      },
      {
        iso2: "MD",
        iso3: "MDA",
        name: "Moldova",
        name_fr: "Moldova , République de",
        flag: 132,
      },
      { iso2: "MC", iso3: "MCO", name: "Monaco", name_fr: "Monaco", flag: 133 },
      {
        iso2: "MN",
        iso3: "MNG",
        name: "Mongolia",
        name_fr: "Mongolie",
        flag: 134,
      },
      {
        iso2: "ME",
        iso3: "MNE",
        name: "Montenegro",
        name_fr: "Monténégro",
        alternates: ["Crna Gora"],
        flag: 0,
      },
      {
        iso2: "MS",
        iso3: "MSR",
        name: "Montserrat",
        name_fr: "Montserrat",
        flag: 135,
      },
      { iso2: "MA", iso3: "MAR", name: "Morocco", name_fr: "Maroc", flag: 136 },
      {
        iso2: "MZ",
        iso3: "MOZ",
        name: "Mozambique",
        name_fr: "Mozambique",
        flag: 137,
      },
      {
        iso2: "MM",
        iso3: "MMR",
        name: "Myanmar",
        name_fr: "Myanmar",
        flag: 33,
      },
      {
        iso2: "NA",
        iso3: "NAM",
        name: "Namibia",
        name_fr: "Namibie",
        flag: 138,
      },
      { iso2: "NR", iso3: "NRU", name: "Nauru", name_fr: "Nauru", flag: 139 },
      { iso2: "NP", iso3: "NPL", name: "Nepal", name_fr: "Népal", flag: 140 },
      {
        iso2: "NL",
        iso3: "NLD",
        name: "Netherlands",
        name_fr: "Pays-Bas",
        alternates: ["Holland"],
        flag: 141,
      },
      {
        iso2: "NC",
        iso3: "NCL",
        name: "New Caledonia",
        name_fr: "Nouvelle-Calédonie",
        flag: 0,
      },
      {
        iso2: "NZ",
        iso3: "NZL",
        name: "New Zealand",
        name_fr: "Nouvelle-Zélande",
        flag: 142,
      },
      {
        iso2: "NI",
        iso3: "NIC",
        name: "Nicaragua",
        name_fr: "Nicaragua",
        flag: 143,
      },
      { iso2: "NE", iso3: "NER", name: "Niger", name_fr: "Niger", flag: 144 },
      {
        iso2: "NG",
        iso3: "NGA",
        name: "Nigeria",
        name_fr: "Nigéria",
        flag: 145,
      },
      { iso2: "NU", iso3: "NIU", name: "Niue", name_fr: "Niue", flag: 146 },
      {
        iso2: "NF",
        iso3: "NFK",
        name: "Norfolk Island",
        name_fr: "Norfolk (l'Île)",
        flag: 147,
      },
      {
        iso2: "MK",
        iso3: "MKD",
        name: "North Macedonia",
        name_fr: "Macédoine (l'ex-République yougoslave de)",
        alternates: ["Poraneshna Jugoslovenska Republika Makedonija"],
        flag: 119,
      },
      {
        iso2: "MP",
        iso3: "MNP",
        name: "Northern Mariana Islands",
        name_fr: "Mariannes du Nord (les Îles)",
        flag: 148,
      },
      {
        iso2: "NO",
        iso3: "NOR",
        name: "Norway",
        name_fr: "Norvège",
        flag: 150,
      },
      { iso2: "OM", iso3: "OMN", name: "Oman", name_fr: "Oman", flag: 151 },
      {
        iso2: "PK",
        iso3: "PAK",
        name: "Pakistan",
        name_fr: "Pakistan",
        flag: 152,
      },
      { iso2: "PW", iso3: "PLW", name: "Palau", name_fr: "Palaos", flag: 153 },
      {
        iso2: "PS",
        iso3: "PSE",
        name: "Palestine",
        name_fr: "Palestine, État de",
        flag: 0,
      },
      { iso2: "PA", iso3: "PAN", name: "Panama", name_fr: "Panama", flag: 154 },
      {
        iso2: "PG",
        iso3: "PNG",
        name: "Papua New Guinea",
        name_fr: "Papouasie-Nouvelle-Guinée",
        flag: 155,
      },
      {
        iso2: "PY",
        iso3: "PRY",
        name: "Paraguay",
        name_fr: "Paraguay",
        flag: 156,
      },
      { iso2: "PE", iso3: "PER", name: "Peru", name_fr: "Pérou", flag: 157 },
      {
        iso2: "PH",
        iso3: "PHL",
        name: "Philippines",
        name_fr: "Philippines",
        flag: 158,
      },
      {
        iso2: "PN",
        iso3: "PCN",
        name: "Pitcairn",
        name_fr: "Pitcairn",
        flag: 0,
      },
      {
        iso2: "PL",
        iso3: "POL",
        name: "Poland",
        name_fr: "Pologne",
        alternates: ["Polska"],
        flag: 159,
      },
      {
        iso2: "PT",
        iso3: "PRT",
        name: "Portugal",
        name_fr: "Portugal",
        flag: 160,
      },
      {
        iso2: "PR",
        iso3: "PRI",
        name: "Puerto Rico",
        name_fr: "Porto Rico",
        flag: 161,
      },
      { iso2: "QA", iso3: "QAT", name: "Qatar", name_fr: "Qatar", flag: 162 },
      { iso2: "RE", iso3: "REU", name: "Réunion", name_fr: "Réunion", flag: 0 },
      {
        iso2: "RO",
        iso3: "ROU",
        name: "Romania",
        name_fr: "Roumanie",
        alternates: ["România"],
        flag: 163,
      },
      {
        iso2: "RU",
        iso3: "RUS",
        name: "Russia",
        name_fr: "Russie (la Fédération de)",
        alternates: ["Rossiya"],
        flag: 164,
      },
      { iso2: "RW", iso3: "RWA", name: "Rwanda", name_fr: "Rwanda", flag: 165 },
      {
        iso2: "MF",
        iso3: "MAF",
        name: "Saint Martin (French)",
        name_fr: "Saint-Martin (partie française)",
        flag: 0,
      },
      { iso2: "WS", iso3: "WSM", name: "Samoa", name_fr: "Samoa", flag: 171 },
      {
        iso2: "SM",
        iso3: "SMR",
        name: "San Marino",
        name_fr: "Saint-Marin",
        flag: 172,
      },
      {
        iso2: "ST",
        iso3: "STP",
        name: "Sao Tome and Principe",
        name_fr: "Sao Tomé-et-Principe",
        flag: 173,
      },
      {
        iso2: "SA",
        iso3: "SAU",
        name: "Saudi Arabia",
        name_fr: "Arabie saoudite",
        flag: 174,
      },
      {
        iso2: "SN",
        iso3: "SEN",
        name: "Senegal",
        name_fr: "Sénégal",
        flag: 175,
      },
      {
        iso2: "RS",
        iso3: "SRB",
        name: "Serbia",
        name_fr: "Serbie",
        alternates: ["Srbija"],
        flag: 0,
      },
      {
        iso2: "SC",
        iso3: "SYC",
        name: "Seychelles",
        name_fr: "Seychelles",
        flag: 176,
      },
      {
        iso2: "SL",
        iso3: "SLE",
        name: "Sierra Leone",
        name_fr: "Sierra Leone",
        flag: 177,
      },
      {
        iso2: "SG",
        iso3: "SGP",
        name: "Singapore",
        name_fr: "Singapour",
        flag: 178,
      },
      {
        iso2: "SX",
        iso3: "SXM",
        name: "Sint Maarten (Dutch)",
        name_fr: "Saint-Martin (partie néerlandaise)",
        flag: 0,
      },
      {
        iso2: "SK",
        iso3: "SVK",
        name: "Slovakia",
        name_fr: "Slovaquie",
        alternates: ["Slovenská republika"],
        flag: 179,
      },
      {
        iso2: "SI",
        iso3: "SVN",
        name: "Slovenia",
        name_fr: "Slovénie",
        alternates: ["Slovenija"],
        flag: 180,
      },
      {
        iso2: "SB",
        iso3: "SLB",
        name: "Solomon Islands",
        name_fr: "Salomon (Îles)",
        flag: 181,
      },
      {
        iso2: "SO",
        iso3: "SOM",
        name: "Somalia",
        name_fr: "Somalie",
        flag: 182,
      },
      {
        iso2: "ZA",
        iso3: "ZAF",
        name: "South Africa",
        name_fr: "Afrique du Sud",
        flag: 183,
      },
      {
        iso2: "GS",
        iso3: "SGS",
        name: "South Georgia and the South Sandwich Islands",
        name_fr: "Géorgie du Sud-et-les Îles Sandwich du Sud",
        flag: 184,
      },
      {
        iso2: "SS",
        iso3: "SSD",
        name: "South Sudan ",
        name_fr: "Soudan du Sud",
        flag: 0,
      },
      {
        iso2: "ES",
        iso3: "ESP",
        name: "Spain",
        name_fr: "Espagne",
        alternates: ["España"],
        flag: 186,
      },
      {
        iso2: "LK",
        iso3: "LKA",
        name: "Sri Lanka",
        name_fr: "Sri Lanka",
        flag: 187,
      },
      {
        iso2: "BL",
        iso3: "BLM",
        name: "St Barthélemy",
        name_fr: "Saint-Barthélemy",
        flag: 0,
      },
      {
        iso2: "SH",
        iso3: "SHN",
        name: "St Helena, Ascension and Tristan da Cunha",
        name_fr: "Sainte-Hélène, Ascension et Tristan da Cunha",
        flag: 166,
      },
      {
        iso2: "KN",
        iso3: "KNA",
        name: "St Kitts and Nevis",
        name_fr: "Saint-Kitts-et-Nevis",
        flag: 167,
      },
      {
        iso2: "LC",
        iso3: "LCA",
        name: "St Lucia",
        name_fr: "Sainte-Lucie",
        flag: 168,
      },
      {
        iso2: "PM",
        iso3: "SPM",
        name: "St Pierre and Miquelon",
        name_fr: "Saint-Pierre-et-Miquelon",
        flag: 169,
      },
      {
        iso2: "VC",
        iso3: "VCT",
        name: "St Vincent",
        name_fr: "Saint-Vincent-et-les-Grenadines",
        flag: 170,
      },
      { iso2: "SD", iso3: "SDN", name: "Sudan", name_fr: "Soudan", flag: 188 },
      {
        iso2: "SR",
        iso3: "SUR",
        name: "Suriname",
        name_fr: "Suriname",
        flag: 189,
      },
      {
        iso2: "SJ",
        iso3: "SJM",
        name: "Svalbard and Jan Mayen",
        name_fr: "Svalbard et l'Île Jan Mayen",
        flag: 190,
      },
      {
        iso2: "SE",
        iso3: "SWE",
        name: "Sweden",
        name_fr: "Suède",
        alternates: ["Sverige"],
        flag: 192,
      },
      {
        iso2: "CH",
        iso3: "CHE",
        name: "Switzerland",
        name_fr: "Suisse",
        alternates: ["Schweiz"],
        flag: 193,
      },
      {
        iso2: "SY",
        iso3: "SYR",
        name: "Syria",
        name_fr: "République arabe syrienne",
        flag: 0,
      },
      {
        iso2: "TW",
        iso3: "TWN",
        name: "Taiwan",
        name_fr: "Taïwan (Province de Chine)",
        flag: 194,
      },
      {
        iso2: "TJ",
        iso3: "TJK",
        name: "Tajikistan",
        name_fr: "Tadjikistan",
        flag: 195,
      },
      {
        iso2: "TZ",
        iso3: "TZA",
        name: "Tanzania",
        name_fr: "Tanzanie, République-Unie de",
        flag: 196,
      },
      {
        iso2: "TH",
        iso3: "THA",
        name: "Thailand",
        name_fr: "Thaïlande",
        flag: 197,
      },
      { iso2: "TG", iso3: "TGO", name: "Togo", name_fr: "Togo", flag: 198 },
      { iso2: "TK", iso3: "TKL", name: "Tokelau", name_fr: "Tokelau", flag: 0 },
      { iso2: "TO", iso3: "TON", name: "Tonga", name_fr: "Tonga", flag: 199 },
      {
        iso2: "TT",
        iso3: "TTO",
        name: "Trinidad and Tobago",
        name_fr: "Trinité-et-Tobago",
        flag: 200,
      },
      {
        iso2: "TN",
        iso3: "TUN",
        name: "Tunisia",
        name_fr: "Tunisie",
        flag: 201,
      },
      {
        iso2: "TR",
        iso3: "TUR",
        name: "Turkey",
        name_fr: "Turquie",
        alternates: ["Türkiye"],
        flag: 202,
      },
      {
        iso2: "TM",
        iso3: "TKM",
        name: "Turkmenistan",
        name_fr: "Turkménistan",
        flag: 203,
      },
      {
        iso2: "TC",
        iso3: "TCA",
        name: "Turks and Caicos Islands",
        name_fr: "Turks-et-Caïcos (les Îles)",
        flag: 204,
      },
      { iso2: "TV", iso3: "TUV", name: "Tuvalu", name_fr: "Tuvalu", flag: 205 },
      {
        iso2: "UG",
        iso3: "UGA",
        name: "Uganda",
        name_fr: "Ouganda",
        flag: 206,
      },
      {
        iso2: "UA",
        iso3: "UKR",
        name: "Ukraine",
        name_fr: "Ukraine",
        alternates: ["Ukraina"],
        flag: 207,
      },
      {
        iso2: "AE",
        iso3: "ARE",
        name: "United Arab Emirates",
        name_fr: "Émirats arabes unis",
        alternates: ["UAE"],
        flag: 208,
      },
      {
        iso2: "GB",
        iso3: "GBR",
        name: "United Kingdom",
        name_fr: "Royaume-Uni",
        alternates: [
          "Britain",
          "England",
          "Great Britain",
          "Northern Ireland",
          "Scotland",
          "UK",
          "Wales",
        ],
        flag: 78,
      },
      {
        iso2: "US",
        iso3: "USA",
        name: "United States",
        name_fr: "États-Unis",
        alternates: ["America", "United States of America"],
        flag: 210,
      },
      {
        iso2: "UM",
        iso3: "UMI",
        name: "United States Minor Outlying Islands",
        name_fr: "Îles mineures éloignées des États-Unis",
        flag: 0,
      },
      {
        iso2: "VI",
        iso3: "VIR",
        name: "United States Virgin Islands",
        name_fr: "Vierges des États-Unis (les Îles)",
        flag: 215,
      },
      {
        iso2: "UY",
        iso3: "URY",
        name: "Uruguay",
        name_fr: "Uruguay",
        flag: 209,
      },
      {
        iso2: "UZ",
        iso3: "UZB",
        name: "Uzbekistan",
        name_fr: "Ouzbékistan",
        flag: 211,
      },
      {
        iso2: "VU",
        iso3: "VUT",
        name: "Vanuatu",
        name_fr: "Vanuatu",
        flag: 212,
      },
      {
        iso2: "VA",
        iso3: "VAT",
        name: "Vatican City",
        name_fr: "Saint-Siège [État de la Cité du Vatican]",
        flag: 0,
      },
      {
        iso2: "VE",
        iso3: "VEN",
        name: "Venezuela",
        name_fr: "Venezuela, République bolivarienne du ",
        flag: 213,
      },
      {
        iso2: "VN",
        iso3: "VNM",
        name: "Viet Nam",
        name_fr: "Viet Nam",
        flag: 214,
      },
      {
        iso2: "WF",
        iso3: "WLF",
        name: "Wallis and Futuna",
        name_fr: "Wallis-et-Futuna ",
        flag: 216,
      },
      {
        iso2: "EH",
        iso3: "ESH",
        name: "Western Sahara",
        name_fr: "Sahara occidental",
        flag: 0,
      },
      { iso2: "YE", iso3: "YEM", name: "Yemen", name_fr: "Yémen", flag: 217 },
      { iso2: "ZM", iso3: "ZMB", name: "Zambia", name_fr: "Zambie", flag: 218 },
      {
        iso2: "ZW",
        iso3: "ZWE",
        name: "Zimbabwe",
        name_fr: "Zimbabwe",
        flag: 219,
      },
    ];

    /** Input field modes.
     * @memberof pca
     * @readonly
     * @enum {number} */
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
  (function (window, undefined) {
    var pca = (window.pca = window.pca || {}),
      document = window.document;

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
  /*3.99 - Updates toolkit to 3.94 and introduces option to simulate react event*/

  (function () {
    var pca = (window.pca = window.pca || {});

    /** Input field modes. Bit set values.
     * @memberof pca
     * @readonly
     * @enum {number} */
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

  let loqateFindUrl;
  let loqateRetrieveUrl;
  const fieldMappings = {
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

  function getElementByName(name, context) {
    context = context || document;
    return context.querySelector(`[name="${name}"]`);
  }

  function normalizeRegionValue(value) {
    return (value || "").toString().trim().toLowerCase();
  }

  function dispatchChange(element) {
    const event = new Event("change", { bubbles: true, cancelable: true });
    element.dispatchEvent(event);
  }

  function mapRegionSelectValue(selectElement, details) {
    if (!selectElement || selectElement.tagName !== "SELECT" || !details) {
      return false;
    }

    const candidates = [];
    if (details.ProvinceName) candidates.push(details.ProvinceName);
    if (details.Province) candidates.push(details.Province);
    if (details.ProvinceCode) candidates.push(details.ProvinceCode);

    if (!candidates.length) {
      return false;
    }

    for (let i = 0; i < candidates.length; i++) {
      const candidate = normalizeRegionValue(candidates[i]);
      if (!candidate) {
        continue;
      }

      for (let optionIndex = 0; optionIndex < selectElement.options.length; optionIndex++) {
        const option = selectElement.options[optionIndex];
        const optionValues = [
          option.text,
          option.getAttribute("data-title"),
          option.getAttribute("data-code"),
          option.getAttribute("data-region-code"),
          option.value,
        ];

        for (let valueIndex = 0; valueIndex < optionValues.length; valueIndex++) {
          const optionValue = normalizeRegionValue(optionValues[valueIndex]);
          if (optionValue && optionValue === candidate) {
            const valueChanged = selectElement.value !== option.value;
            selectElement.value = option.value;
            selectElement.selectedIndex = optionIndex;
            selectElement.setAttribute("value", option.value);
            dispatchChange(selectElement);
            if (!valueChanged) {
              // force validation to re-check even if the underlying value matched
              dispatchChange(selectElement);
            }
            return true;
          }
        }
      }
    }

    return false;
  }

  function mapRegionSelectValueWithRetry(
    selectElement,
    details,
    attempt = 0,
    maxAttempts = 5
  ) {
    if (mapRegionSelectValue(selectElement, details)) {
      return;
    }

    if (attempt >= maxAttempts) {
      return;
    }

    const delay = 150 * Math.pow(2, attempt);
    window.setTimeout(function () {
      mapRegionSelectValueWithRetry(selectElement, details, attempt + 1, maxAttempts);
    }, delay);
  }

  $(document).ready(function () {
    const loqateElement = document.getElementById("loqate-urls");
    const pcaInstances = {};

    if (loqateElement) {
      loqateFindUrl = loqateElement.getAttribute("data-find-url");
      loqateRetrieveUrl = loqateElement.getAttribute("data-retrieve-url");
    } else {
      console.error('Element with ID "loqate-urls" not found');
      return;
    }

    // wait until the address fields are rendered
    setInterval(function () {
      for (const [key, value] of Object.entries(fieldMappings)) {
        const anchorElement = document.querySelector(
          `input[name="${value[0].element}"]`
        );

        // if line1 doesn't exist or is already registered, skip
        if (
          anchorElement === null ||
          document.body.contains(pcaInstances[key]?.anchorElement)
        ) {
          continue;
        }

        const context = anchorElement.closest(".admin__fieldset");
        const addressFieldsWithElements = value.map((field) => {
          return {
            ...field,
            element: getElementByName(field.element, context),
          };
        });

        // clean-up old instances
        if (pcaInstances[key]?.control) {
          pcaInstances[key].control.destroy();
        }

        const control = new pca.Address(addressFieldsWithElements, {
          key: " ",
          simulateReactEvents: true,
          endpoint: {
            literal: true,
            find: loqateFindUrl,
            retrieve: loqateRetrieveUrl,
            unwrapped: true,
          },
        });

        const regionSelectFields = addressFieldsWithElements.filter(
          (field) =>
            field.field === "ProvinceName" &&
            field.element &&
            field.element.tagName === "SELECT"
        );

        if (regionSelectFields.length) {
          control.listen("populate", function (details) {
            regionSelectFields.forEach((regionField) => {
              mapRegionSelectValueWithRetry(regionField.element, details);
            });
          });
        }

        pcaInstances[key] = { anchorElement, control };
      }
    }, 200);
  });
});
