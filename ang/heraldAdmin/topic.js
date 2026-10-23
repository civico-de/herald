(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");
  // Keys of the topic itself, not of its route (TopicMeta::KEYS).
  const META = ["label", "subscribable"];

  angular.module("heraldAdmin").controller("HeraldAdminTopicCtrl", [
    "$q",
    "$routeParams",
    "$location",
    "crmApi4",
    "crmStatus",
    "heraldAdmin",
    function ($q, $routeParams, $location, crmApi4, crmStatus, heraldAdmin) {
      const ctrl = this;
      const editing = $routeParams.name;
      const deliveryFields = heraldAdmin.deliveryFields();
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";
      ctrl.isNew = editing === undefined;
      ctrl.modes = heraldAdmin.modes;
      ctrl.deliveryFields = deliveryFields;

      let original = {};
      let channels = [];
      let fallback = {};

      const channelFields = (key) => {
        const channel = channels.find((c) => c.key === key);
        return channel ? channel.fields : [];
      };
      const effectiveChannel = () =>
        ctrl.channel || (ctrl.name !== "default" && fallback.channel) || null;
      ctrl.effectiveMode = () =>
        ctrl.mode || (ctrl.name !== "default" && fallback.delivery_mode) || "direct";
      ctrl.isDefault = () => ctrl.name === "default";

      const parseOther = () => {
        const text = (ctrl.other || "").trim();
        if (text === "") {
          return {};
        }
        const parsed = JSON.parse(text);
        if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) {
          throw new Error(ts("enter a JSON object"));
        }
        return parsed;
      };
      const showOther = (values) => {
        ctrl.other = Object.keys(values).length ? JSON.stringify(values, null, 2) : "";
      };

      // Mode-specific fields show for their modes, or wherever they hold a value.
      ctrl.deliveryShown = (field) =>
        !field.modes ||
        field.modes.indexOf(ctrl.effectiveMode()) !== -1 ||
        (!heraldAdmin.isEmpty(ctrl.inputs[field.key]) && ctrl.inputs[field.key] !== false);

      // Values of the old channel's fields move to the JSON box; nothing is dropped unseen.
      ctrl.channelChanged = () => {
        const next = effectiveChannel();
        if (next === ctrl.effective) {
          return;
        }
        let other;
        try {
          other = parseOther();
        } catch (e) {
          ctrl.errors = [ts("Other parameters: %1", { 1: e.message })];
          return;
        }
        const previous = channelFields(ctrl.effective);
        const kept = heraldAdmin.fromInputs(previous, ctrl.inputs, original);
        Object.assign(other, kept.values);
        previous.forEach((field) => {
          delete ctrl.inputs[field.key];
        });
        const fields = channelFields(next);
        const moved = {};
        fields.forEach((field) => {
          if (heraldAdmin.has(other, field.key)) {
            moved[field.key] = other[field.key];
            delete other[field.key];
          }
        });
        Object.assign(ctrl.inputs, heraldAdmin.toInputs(fields, moved));
        showOther(other);
        ctrl.effective = next;
        ctrl.channelFields = fields;
      };

      $q.all([heraldAdmin.setting("herald_topics"), heraldAdmin.channels()])
        .then((loaded) => {
          const map = heraldAdmin.asMap(loaded[0]);
          channels = loaded[1];
          ctrl.channels = channels;
          fallback = heraldAdmin.asMap(map.default);
          if (!ctrl.isNew && !heraldAdmin.has(map, editing)) {
            ctrl.errors = [ts('The topic "%1" does not exist.', { 1: editing })];
            return;
          }
          original = ctrl.isNew ? {} : heraldAdmin.asMap(map[editing]);
          ctrl.name = ctrl.isNew ? "" : editing;
          ctrl.label = typeof original.label === "string" ? original.label : "";
          ctrl.subscribable = original.subscribable === true;
          ctrl.channel = typeof original.channel === "string" ? original.channel : null;
          ctrl.mode = typeof original.delivery_mode === "string" ? original.delivery_mode : null;
          ctrl.effective = effectiveChannel();
          ctrl.channelFields = channelFields(ctrl.effective);
          const shown = deliveryFields.concat(ctrl.channelFields);
          ctrl.inputs = heraldAdmin.toInputs(shown, original);
          const other = {};
          Object.keys(original).forEach((key) => {
            const known =
              META.indexOf(key) !== -1 ||
              key === "channel" ||
              key === "delivery_mode" ||
              shown.some((f) => f.key === key);
            if (!known) {
              other[key] = original[key];
            }
          });
          showOther(other);
          ctrl.loaded = true;
        })
        .catch((failure) => {
          ctrl.errors = heraldAdmin.errorList(failure);
        });

      const buildRoute = () => {
        const fields = deliveryFields.concat(ctrl.channelFields);
        const built = heraldAdmin.fromInputs(fields, ctrl.inputs, original);
        const values = built.values;
        ["channel", "delivery_mode"].forEach((key) => {
          const input = key === "channel" ? ctrl.channel : ctrl.mode;
          if (input !== null && input !== undefined) {
            values[key] = input;
          }
        });
        try {
          const other = parseOther();
          Object.keys(other).forEach((key) => {
            if (heraldAdmin.has(values, key)) {
              built.errors.push(
                ts('Other parameters: "%1" is already set by a field above.', { 1: key }),
              );
            }
            values[key] = other[key];
          });
        } catch (e) {
          built.errors.push(ts("Other parameters: %1", { 1: e.message }));
        }
        Object.keys(values).forEach((key) => {
          if (META.indexOf(key) !== -1) {
            built.errors.push(ts('Other parameters: "%1" is set by a field above.', { 1: key }));
          }
        });
        return { route: heraldAdmin.ordered(values, original), errors: built.errors };
      };

      ctrl.save = () => {
        const built = buildRoute();
        ctrl.errors = built.errors;
        if (built.errors.length) {
          return;
        }
        ctrl.saving = true;
        crmStatus(
          { start: ts("Saving..."), success: ts("Topic saved.") },
          crmApi4("Herald", "saveTopic", {
            topic: ctrl.name,
            route: built.route,
            label: ctrl.label || "",
            subscribable: !ctrl.isDefault() && ctrl.subscribable === true,
            previousName: ctrl.isNew ? "" : editing,
          }),
        )
          .then(() => {
            ctrl.saving = false;
            $location.path("/topics");
          })
          .catch((failure) => {
            ctrl.saving = false;
            ctrl.errors = heraldAdmin.errorList(failure);
          });
      };
    },
  ]);
})(angular);
