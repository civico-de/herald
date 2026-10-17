(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");

  angular.module("heraldAdmin").controller("HeraldAdminCleaningCtrl", [
    "$q",
    "$timeout",
    "crmApi4",
    "crmStatus",
    "heraldAdmin",
    function ($q, $timeout, crmApi4, crmStatus, heraldAdmin) {
      const ctrl = this;
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";
      ctrl.fields = [];
      ctrl.entities = [];
      ctrl.types = [
        { value: "contains", label: ts("contains") },
        { value: "prefix", label: ts("starts with") },
        { value: "regex", label: ts("regular expression") },
      ];
      const textFields = {};

      // Focus the first of the ids that exists once Angular has redrawn the rows.
      const focus = (...ids) =>
        $timeout(() => {
          const element = ids.map((id) => document.getElementById(id)).find(Boolean);
          if (element) {
            element.focus();
          }
        });

      const loadFields = (entity) => {
        if (!entity || textFields[entity]) {
          return $q.resolve();
        }
        textFields[entity] = [];
        return crmApi4(entity, "getFields", {
          action: "get",
          select: ["name", "title"],
          where: [
            ["type", "=", "Field"],
            ["data_type", "IN", ["String", "Text"]],
          ],
          orderBy: { title: "ASC" },
        }).then((rows) => {
          textFields[entity] = rows.map((row) => ({ name: row.name, title: row.title }));
          return textFields[entity];
        });
      };

      $q.all([
        heraldAdmin.setting("herald_cleaned_fields"),
        crmApi4("Entity", "get", {
          select: ["name", "title_plural"],
          where: [["type", "CONTAINS", "DAOEntity"]],
          orderBy: { title_plural: "ASC" },
        }),
      ])
        .then(([fields, entities]) => {
          ctrl.fields = Array.isArray(fields) ? angular.copy(fields) : [];
          ctrl.entities = entities.map((row) => ({ name: row.name, title: row.title_plural }));
          return $q.all(ctrl.fields.map((entry) => loadFields(entry.entity)));
        })
        .then(() => {
          ctrl.loaded = true;
        })
        .catch((failure) => {
          ctrl.errors = heraldAdmin.errorList(failure);
        });

      ctrl.fieldsOf = (entity) => textFields[entity] || [];

      ctrl.legend = (entry, index) => {
        const entity = ctrl.entities.find((e) => e.name === entry.entity);
        const field = ctrl.fieldsOf(entry.entity).find((f) => f.name === entry.field);
        return entity && field
          ? entity.title + ": " + field.title
          : ts("Field %1", { 1: index + 1 });
      };

      ctrl.entityChanged = (entry) => {
        entry.field = "";
        loadFields(entry.entity);
      };

      ctrl.addField = () => {
        ctrl.fields.push({ entity: "", field: "", rules: [], fallback: "" });
        focus("herald-clean-" + (ctrl.fields.length - 1) + "-entity");
      };

      ctrl.removeField = (index) => {
        ctrl.fields.splice(index, 1);
        ctrl.status = ts("Field %1 removed.", { 1: index + 1 });
        focus(
          ctrl.fields.length
            ? "herald-clean-" + Math.min(index, ctrl.fields.length - 1) + "-entity"
            : "herald-clean-add",
        );
      };

      ctrl.addRule = (b) => {
        const rules = ctrl.fields[b].rules;
        rules.push({ match: "", type: "contains", label: "" });
        focus("herald-clean-" + b + "-match-" + (rules.length - 1));
      };

      ctrl.removeRule = (b, index) => {
        const rules = ctrl.fields[b].rules;
        rules.splice(index, 1);
        ctrl.status = ts("Rule %1 removed.", { 1: index + 1 });
        focus(
          rules.length
            ? "herald-clean-" + b + "-match-" + Math.min(index, rules.length - 1)
            : "herald-clean-" + b + "-add",
        );
      };

      ctrl.move = (b, index, delta) => {
        const rules = ctrl.fields[b].rules;
        const target = index + delta;
        rules.splice(target, 0, rules.splice(index, 1)[0]);
        ctrl.status = ts("Rule moved to position %1.", { 1: target + 1 });
        const prefix = "herald-clean-" + b + "-";
        focus(
          prefix + (delta < 0 ? "up-" : "down-") + target,
          prefix + (delta < 0 ? "down-" : "up-") + target,
        );
      };

      ctrl.save = () => {
        ctrl.saving = true;
        const fields = ctrl.fields.map((entry) => ({
          entity: entry.entity || "",
          field: entry.field || "",
          rules: entry.rules.map((r) => ({ match: r.match, type: r.type, label: r.label })),
          fallback: entry.fallback || "",
        }));
        crmStatus(
          { start: ts("Saving..."), success: ts("Cleaned fields saved.") },
          crmApi4("Herald", "saveCleanedFields", { fields: fields }),
        )
          .then((saved) => {
            ctrl.saving = false;
            ctrl.errors = [];
            ctrl.fields = angular.copy(saved);
            ctrl.status = ts("Cleaned fields saved.");
          })
          .catch((failure) => {
            ctrl.saving = false;
            ctrl.errors = heraldAdmin.errorList(failure);
          });
      };
    },
  ]);
})(angular);
