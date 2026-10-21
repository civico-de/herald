(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");
  const module = angular.module("heraldAdmin", CRM.angRequires("heraldAdmin"));

  module.config([
    "$routeProvider",
    function ($routeProvider) {
      const page = (name, controller) => ({
        templateUrl: "~/heraldAdmin/" + name + ".html",
        controller: controller,
        controllerAs: "$ctrl",
      });
      $routeProvider
        .when("/", page("index", "HeraldAdminIndexCtrl"))
        .when("/channels", page("channels", "HeraldAdminChannelsCtrl"))
        .when("/channels/new", page("channel", "HeraldAdminChannelCtrl"))
        .when("/channels/edit/:name", page("channel", "HeraldAdminChannelCtrl"))
        .when("/topics", page("topics", "HeraldAdminTopicsCtrl"))
        .when("/topics/new", page("topic", "HeraldAdminTopicCtrl"))
        .when("/topics/edit/:name", page("topic", "HeraldAdminTopicCtrl"))
        .when("/cleaning", page("cleaning", "HeraldAdminCleaningCtrl"))
        .when("/hosts", page("hosts", "HeraldAdminHostsCtrl"))
        .when("/monitor", page("monitor", "HeraldAdminMonitorCtrl"))
        .when("/test", page("test", "HeraldAdminTestCtrl"))
        .when("/preview", page("preview", "HeraldAdminPreviewCtrl"));
    },
  ]);

  const isEmpty = (value) => value === null || value === undefined || value === "";
  const has = (object, key) => Object.prototype.hasOwnProperty.call(object, key);

  // Settings of type Array arrive as [] when empty, also where a map is meant.
  const asMap = (value) =>
    value && typeof value === "object" && !Array.isArray(value) ? value : {};

  const optionList = (options) => {
    if (Array.isArray(options)) {
      return options.map((value) => ({ value: String(value), label: String(value) }));
    }
    return Object.keys(options || {}).map((value) => ({ value: value, label: options[value] }));
  };

  /**
   * Input value for a stored parameter, by field type.
   */
  const toInput = (field, value) => {
    if (field.tristate) {
      if (value === true || value === 1 || value === "1") {
        return "1";
      }
      return value === false || value === 0 || value === "0" ? "0" : "";
    }
    if (field.type === "bool") {
      return value === true || value === 1 || value === "1";
    }
    if (isEmpty(value)) {
      return field.type === "enum" ? null : "";
    }
    if (field.type === "object") {
      return JSON.stringify(value, null, 2);
    }
    return String(value);
  };

  /**
   * Stored value for an input: {set: false} drops the key. An unchanged input
   * keeps the original value, so a round trip stores exactly what was there.
   */
  const fromInput = (field, input, original, hadKey) => {
    if (field.tristate) {
      if (hadKey && toInput(field, original) === input) {
        return { set: true, value: original };
      }
      return input === "" ? { set: false } : { set: true, value: input === "1" };
    }
    if (field.type === "bool") {
      if (hadKey && toInput(field, original) === input) {
        return { set: true, value: original };
      }
      return input || hadKey ? { set: true, value: Boolean(input) } : { set: false };
    }
    if (isEmpty(input)) {
      return hadKey && isEmpty(original) ? { set: true, value: original } : { set: false };
    }
    if (hadKey && toInput(field, original) === input) {
      return { set: true, value: original };
    }
    if (field.type === "int" && /^-?\d+$/.test(input.trim())) {
      return { set: true, value: parseInt(input, 10) };
    }
    if (field.type === "object") {
      try {
        return { set: true, value: JSON.parse(input) };
      } catch (e) {
        return { error: ts('"%1" is not valid JSON: %2', { 1: field.label, 2: e.message }) };
      }
    }
    return { set: true, value: input };
  };

  module.factory("heraldAdmin", [
    "$q",
    "crmApi4",
    function ($q, crmApi4) {
      const svc = {
        ts: ts,
        asMap: asMap,
        setting: (name) =>
          crmApi4("Setting", "get", { select: [name] }).then((rows) =>
            rows.length ? rows[0].value : null,
          ),
        // The API joins validation messages with line breaks.
        errorList: (failure) =>
          String((failure && failure.error_message) || ts("The request failed."))
            .split("\n")
            .filter(Boolean),
        // The channel types with all their keys; scope "channel" keys are set only on a channel.
        channelTypes: () =>
          crmApi4("Herald", "getChannelTypes").then((rows) =>
            rows.map((row) => ({
              key: row.key,
              label: row.label,
              semantic: asMap(row.semantic_fields),
              subscribable: Boolean(row.subscribable),
              fields: Object.keys(asMap(row.config_schema)).map((key) => {
                const spec = row.config_schema[key];
                return {
                  key: key,
                  label: spec.label,
                  type: spec.type,
                  required: Boolean(spec.required),
                  help: spec.help || "",
                  options: optionList(spec.options),
                  scope: spec.scope || "delivery",
                  secret: Boolean(spec.secret),
                  recipient: Boolean(spec.recipient),
                };
              }),
            })),
          ),
        // The configured channels, each with the delivery keys of its type: those a
        // route, rule or test may set over the channel's own, so none is required
        // and a yes/no field can also leave the channel's value.
        channels: () =>
          $q.all([crmApi4("Herald", "getChannels"), svc.channelTypes()]).then((loaded) => {
            const types = {};
            loaded[1].forEach((type) => {
              types[type.key] = type;
            });
            return loaded[0].map((row) => {
              const type = types[row.type] || { label: row.type, fields: [] };
              return {
                key: row.name,
                label:
                  row.label +
                  " (" +
                  type.label +
                  ")" +
                  (row.active ? "" : " " + ts("(switched off)")),
                type: row.type,
                active: Boolean(row.active),
                fields: type.fields
                  .filter((field) => field.scope === "delivery")
                  .map((field) =>
                    Object.assign({}, field, { required: false, tristate: field.type === "bool" }),
                  ),
              };
            });
          }),
        toInputs: (fields, source) => {
          const inputs = {};
          fields.forEach((field) => {
            inputs[field.key] = toInput(field, source[field.key]);
          });
          return inputs;
        },
        /**
         * Parameters from inputs; returns {values, errors}.
         */
        fromInputs: (fields, inputs, original) => {
          const values = {};
          const errors = [];
          fields.forEach((field) => {
            const out = fromInput(
              field,
              inputs[field.key],
              original[field.key],
              has(original, field.key),
            );
            if (out.error) {
              errors.push(out.error);
            } else if (out.set) {
              values[field.key] = out.value;
            }
          });
          return { values: values, errors: errors };
        },
        // Keys in their original order first, new keys after them.
        ordered: (values, original) => {
          const out = {};
          Object.keys(original)
            .concat(Object.keys(values))
            .forEach((key) => {
              if (has(values, key) && !has(out, key)) {
                out[key] = values[key];
              }
            });
          return out;
        },
        has: has,
        isEmpty: isEmpty,
        modes: [
          { value: "direct", label: ts("Direct: the recipient set on the route or the channel") },
          { value: "subscribers", label: ts("Subscribers of a notification type") },
          { value: "group", label: ts("Members of a group") },
          { value: "addresses", label: ts("A list of email addresses") },
        ],
        // Dispatcher parameters every route may carry; channel keys come from Herald.getChannelTypes.
        deliveryFields: () => [
          {
            key: "subscription_type",
            label: ts("Subscription type"),
            type: "string",
            modes: ["subscribers"],
            help: ts("Name of the notification type contacts subscribe to."),
          },
          { key: "group_id", label: ts("Group ID"), type: "int", modes: ["group"] },
          {
            key: "email_addresses",
            label: ts("Email addresses"),
            type: "string",
            modes: ["addresses"],
            help: ts("Separate the addresses with commas, semicolons or line breaks."),
          },
          {
            key: "respect_suspension",
            label: ts("Respect communication suspension"),
            type: "bool",
            modes: ["subscribers", "group"],
          },
          {
            key: "email_location_type",
            label: ts("Email location type ID"),
            type: "int",
            modes: ["subscribers", "group"],
          },
          {
            key: "aggregate_minutes",
            label: ts("Aggregate window in minutes"),
            type: "int",
            help: ts(
              "0 sends each alert at once; a window collects alerts and sends them together.",
            ),
          },
        ],
      };
      return svc;
    },
  ]);

  module.directive("heraldFields", function () {
    return {
      restrict: "E",
      scope: { fields: "<", inputs: "=", prefix: "@", shown: "&?" },
      templateUrl: "~/heraldAdmin/fields.html",
      controller: [
        "$scope",
        function ($scope) {
          $scope.ts = ts;
          $scope.isShown = (field) => !$scope.shown || $scope.shown({ field: field });
        },
      ],
    };
  });

  module.controller("HeraldAdminIndexCtrl", [
    function () {
      this.ts = ts;
      this.links = CRM.heraldAdmin.links;
    },
  ]);
})(angular);
