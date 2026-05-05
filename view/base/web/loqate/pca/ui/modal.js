/**
 * @fileoverview PCA SDK – pca.Modal: modal dialog window.
 *
 * Creates a full-screen overlay (`<div class="pcafullscreen pcamask">`) with a
 * centred dialog box composed of a configurable header, scrollable content area,
 * and footer.  Used by {@link pca.Address} to present address verification prompts
 * and manual-entry forms.
 *
 * Depends on: {@link module:pca/core}, {@link module:pca/utils/dom}.
 *
 * @module pca/ui/modal
 * @copyright 2009–2025 Postcode Anywhere (Holdings) Ltd.
 */
(function (window, undefined) {
  var pca = (window.pca = window.pca || {}),
    document = window.document;

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

})(window);
