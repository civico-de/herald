(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");

  angular.module("heraldAdmin").controller("HeraldAdminSourcesCtrl", [
    "$timeout",
    "crmApi4",
    "crmStatus",
    "heraldAdmin",
    function ($timeout, crmApi4, crmStatus, heraldAdmin) {
      const ctrl = this;
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";
      ctrl.rules = [];
      ctrl.types = [
        { value: "contains", label: ts("contains") },
        { value: "prefix", label: ts("starts with") },
        { value: "regex", label: ts("regular expression") },
      ];

      // Focus the first of the ids that exists once Angular has redrawn the rows.
      const focus = (...ids) =>
        $timeout(() => {
          const element = ids.map((id) => document.getElementById(id)).find(Boolean);
          if (element) {
            element.focus();
          }
        });

      heraldAdmin
        .setting("herald_source_normalization")
        .then((rules) => {
          ctrl.rules = Array.isArray(rules) ? angular.copy(rules) : [];
          ctrl.loaded = true;
        })
        .catch((failure) => {
          ctrl.errors = heraldAdmin.errorList(failure);
        });

      ctrl.add = () => {
        ctrl.rules.push({ match: "", type: "contains", label: "" });
        focus("herald-rule-match-" + (ctrl.rules.length - 1));
      };

      ctrl.remove = (index) => {
        ctrl.rules.splice(index, 1);
        ctrl.status = ts("Rule %1 removed.", { 1: index + 1 });
        focus(
          ctrl.rules.length
            ? "herald-rule-match-" + Math.min(index, ctrl.rules.length - 1)
            : "herald-rule-add",
        );
      };

      ctrl.move = (index, delta) => {
        const target = index + delta;
        const rule = ctrl.rules.splice(index, 1)[0];
        ctrl.rules.splice(target, 0, rule);
        ctrl.status = ts("Rule moved to position %1.", { 1: target + 1 });
        focus(
          "herald-rule-" + (delta < 0 ? "up-" : "down-") + target,
          "herald-rule-" + (delta < 0 ? "down-" : "up-") + target,
        );
      };

      ctrl.save = () => {
        ctrl.saving = true;
        const rules = ctrl.rules.map((r) => ({ match: r.match, type: r.type, label: r.label }));
        crmStatus(
          { start: ts("Saving..."), success: ts("Source rules saved.") },
          crmApi4("Herald", "saveSourceRules", { rules: rules }),
        )
          .then((saved) => {
            ctrl.saving = false;
            ctrl.errors = [];
            ctrl.rules = angular.copy(saved);
            ctrl.status = ts("Source rules saved.");
          })
          .catch((failure) => {
            ctrl.saving = false;
            ctrl.errors = heraldAdmin.errorList(failure);
          });
      };
    },
  ]);
})(angular);
