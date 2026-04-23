/**
 * @fileoverview PCA SDK – core module.
 *
 * Establishes the `window.pca` namespace and provides the foundational
 * infrastructure that all other PCA SDK modules depend on:
 *
 * - **Global constants and lookup tables** (`pca.protocol`, `pca.host`,
 *   `pca.synonyms`, `pca.diacritics`, `pca.hypertext`) used for normalisation.
 * - **Document-ready state machine** (`pca.ready`, `pca._initDocumentReady`)
 *   that defers callbacks until the DOM is fully parsed. Finalised at the end of
 *   {@link module:pca/utils/dom} once `pca.listen`/`pca.ignore` are available.
 * - **`pca.Eventable`** constructor mixin providing `listen`, `ignore`, and
 *   `fire` for publish/subscribe event handling across all SDK objects.
 * - **HTTP request pipeline**: XHR GET/POST, `<iframe>` form-POST (IE≤10 fallback),
 *   and `<script>`-tag JSONP injection, with per-request queuing, caching, and
 *   blocking managed via `pca.requestQueue` / `pca.requestCache`.
 * - **`pca.Request`** constructor and **`pca.fetch`** convenience wrapper.
 * - **`pca.clearBlockingRequests`** to forcibly reset the queue state.
 *
 * @module pca/core
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 * @version 3.99 (custom Loqate/Magento build)
 */
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

    /**
     * Invoked when the document signals it is fully parsed (`DOMContentLoaded`,
     * `readystatechange === "complete"`, or `window.load`).  Marks `ready = true`
     * and drains the `readyList` queue by calling `pca.ready()`.
     * @private
     */
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

    //Listen for document load (called after pca.listen is available, see pca/utils/dom.js).
    pca._initDocumentReady = function () {
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

    /**
     * Sends a service request via `XMLHttpRequest` using the HTTP POST method.
     * The response body is expected to be JSON; it is parsed and forwarded to
     * `request.callback`.  Registers `withCredentials`, `onerror`, and `ontimeout`
     * handlers from the request object.
     * @private
     * @param {pca.Request} request - The configured request to execute.
     */
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

    /**
     * Sends a service request via a hidden `<form>` POST into an `<iframe>`,
     * used as a cross-domain fallback for Internet Explorer ≤10 where XHR CORS
     * is unavailable.  The server writes the response to `window.name` and the
     * iframe is navigated to `about:blank` so the result can be read back.
     * @private
     * @param {pca.Request} request - The configured request to execute.
     */
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

    /**
     * Routes a POST request to the best available transport: `<iframe>` form POST
     * for Internet Explorer ≤10 (detected via `navigator.appName`), XHR for all
     * other environments.
     * @private
     * @param {pca.Request} request - The configured request to execute.
     */
    function postRequest(request) {
      window.navigator.appName === "Microsoft Internet Explorer"
        ? postRequestForm(request)
        : postRequestXHR(request);
    }

    /**
     * Sends a service request via `XMLHttpRequest` GET.  Automatically falls back
     * to a POST request when the constructed URL would exceed `pca.limit` (2000)
     * characters.
     * @private
     * @param {pca.Request} request - The configured request to execute.
     */
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

    /**
     * Sends a service request by injecting a `<script>` tag (JSONP), appending
     * `callback=pca.scriptRequests[n].callback` so the response is routed to the
     * correct handler.  Falls back to a POST request when the script `src` would
     * exceed `pca.limit` bytes.
     * @private
     * @param {pca.Request} request - The configured request to execute.
     */
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

    /**
     * Routes a GET request to the best available transport: JSONP script-tag for
     * Internet Explorer ≤10, XHR for all other environments.
     * @private
     * @param {pca.Request} request - The configured request to execute.
     */
    function getRequest(request) {
      window.navigator.appName === "Microsoft Internet Explorer"
        ? getRequestScript(request)
        : getRequestXHR(request);
    }

    /**
     * Central dispatcher for outgoing service requests.  Handles:
     * - **Blocking**: if `pca.blockRequests` is set, only the latest queued request
     *   is retained; all earlier pending requests are discarded.
     * - **Queuing**: if `pca.waitingRequest` is `true`, the request is pushed onto
     *   `pca.requestQueue` instead of being sent immediately.
     * - **Caching**: if `request.cache` is `true` and a cached response exists, the
     *   callback is invoked asynchronously with the stored data.
     * @private
     * @param {pca.Request} request - The request to dispatch.
     */
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

    /**
     * Called by `request.callback` after a successful HTTP response.  Resets
     * `pca.waitingRequest` / `pca.blockRequests`, unwraps or validates the
     * response `Items` array, invokes `request.success` or `request.error`,
     * stores the response in `pca.requestCache` if caching is enabled, and drains
     * `pca.requestQueue` by processing the next pending request.
     * @private
     * @param {pca.Request} request - The request whose response has been received.
     */
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


})(window);
