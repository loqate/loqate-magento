/**
 * @fileoverview PCA SDK – pca.AutoComplete: keyboard-navigable suggestion dropdown.
 *
 * Binds a {@link pca.List} dropdown to one or more `<input>` fields.  Handles:
 * - Keyboard navigation (arrow keys, Enter, Escape, Tab).
 * - ARIA `aria-activedescendant` updates for screen-reader compatibility.
 * - Dynamic positioning (above or below the anchor field).
 * - Stealth mode: fires events without displaying the list.
 * - Country/flag toggle for the {@link pca.Address} country selector.
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/utils/dom},
 * {@link module:pca/ui/list}.
 *
 * @module pca/ui/autocomplete
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

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

})(window);
