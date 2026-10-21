(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");

  angular.module("heraldAdmin").controller("HeraldAdminChannelCtrl", [
    "$q",
    "$routeParams",
    "$location",
    "crmApi4",
    "crmStatus",
    "heraldAdmin",
    function ($q, $routeParams, $location, crmApi4, crmStatus, heraldAdmin) {
      const ctrl = this;
      const editing = $routeParams.name;
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";
      ctrl.isNew = editing === undefined;
      ctrl.clear = {};

      let original = {};

      ctrl.typeOf = () => (ctrl.types || []).find((type) => type.key === ctrl.type);

      // A stored secret never comes back: its field stays empty, and empty keeps it.
      const withSecretHelp = (fields) =>
        fields.map((field) =>
          field.secret && heraldAdmin.has(original, field.key)
            ? Object.assign({}, field, {
                required: false,
                help: [field.help, ts("A value is stored. Leave the field empty to keep it.")]
                  .filter(Boolean)
                  .join(" "),
              })
            : field,
        );

      ctrl.typeChanged = () => {
        const type = ctrl.typeOf();
        const fields = type ? withSecretHelp(type.fields) : [];
        ctrl.channelFields = fields.filter((field) => field.scope === "channel");
        ctrl.defaultFields = fields
          .filter((field) => field.scope === "delivery")
          .map((field) => Object.assign({}, field, { required: false }));
        ctrl.storedSecrets = fields.filter(
          (field) => field.secret && heraldAdmin.has(original, field.key),
        );
        const plain = {};
        Object.keys(original).forEach((key) => {
          if (!fields.some((field) => field.key === key && field.secret)) {
            plain[key] = original[key];
          }
        });
        ctrl.inputs = heraldAdmin.toInputs(fields, plain);
        if (type && !type.subscribable) {
          ctrl.subscribable = false;
        }
      };

      $q.all([crmApi4("Herald", "getChannels"), heraldAdmin.channelTypes()])
        .then((loaded) => {
          ctrl.types = loaded[1];
          const row = loaded[0].find((channel) => channel.name === editing);
          if (!ctrl.isNew && !row) {
            ctrl.errors = [ts('The channel "%1" does not exist.', { 1: editing })];
            return;
          }
          original = row ? heraldAdmin.asMap(row.config) : {};
          ctrl.name = row ? row.name : "";
          ctrl.type = row ? row.type : null;
          ctrl.label = row ? row.label : "";
          ctrl.active = row ? Boolean(row.active) : true;
          ctrl.subscribable = row ? Boolean(row.subscribable) : false;
          ctrl.typeChanged();
          ctrl.loaded = true;
        })
        .catch((failure) => {
          ctrl.errors = heraldAdmin.errorList(failure);
        });

      const buildConfig = () => {
        const fields = ctrl.channelFields.concat(ctrl.defaultFields);
        const plain = {};
        Object.keys(original).forEach((key) => {
          if (!fields.some((field) => field.key === key && field.secret)) {
            plain[key] = original[key];
          }
        });
        const built = heraldAdmin.fromInputs(fields, ctrl.inputs, plain);
        // Left out, a secret keeps its stored value; NULL removes it. A typed value wins.
        ctrl.storedSecrets.forEach((field) => {
          if (ctrl.clear[field.key] && !(field.key in built.values)) {
            built.values[field.key] = null;
          }
        });
        return built;
      };

      ctrl.save = () => {
        const built = buildConfig();
        ctrl.errors = built.errors;
        if (built.errors.length) {
          return;
        }
        ctrl.saving = true;
        crmStatus(
          { start: ts("Saving..."), success: ts("Channel saved.") },
          crmApi4("Herald", "saveChannel", {
            name: ctrl.name,
            isNew: ctrl.isNew,
            channel: {
              type: ctrl.type,
              label: ctrl.label,
              active: ctrl.active,
              subscribable: ctrl.subscribable,
              config: built.values,
            },
          }),
        )
          .then(() => {
            ctrl.saving = false;
            $location.path("/channels");
          })
          .catch((failure) => {
            ctrl.saving = false;
            ctrl.errors = heraldAdmin.errorList(failure);
          });
      };
    },
  ]);
})(angular);
