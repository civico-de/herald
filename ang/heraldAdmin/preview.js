(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");

  angular.module("heraldAdmin").controller("HeraldAdminPreviewCtrl", [
    "$sce",
    "crmApi4",
    "heraldAdmin",
    function ($sce, crmApi4, heraldAdmin) {
      const ctrl = this;
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";
      ctrl.workflow = null;
      ctrl.vars = "{}";

      crmApi4("MessageTemplate", "get", {
        select: ["workflow_name", "msg_title"],
        where: [
          ["workflow_name", "LIKE", "herald_%"],
          ["is_reserved", "=", false],
        ],
        orderBy: { msg_title: "ASC" },
      })
        .then((rows) => {
          ctrl.templates = rows;
          ctrl.loaded = true;
        })
        .catch((failure) => {
          ctrl.errors = heraldAdmin.errorList(failure);
        });

      ctrl.render = () => {
        let vars;
        try {
          vars = JSON.parse(ctrl.vars || "{}");
        } catch (e) {
          ctrl.errors = [ts("Sample values: %1", { 1: e.message })];
          return;
        }
        if (!vars || typeof vars !== "object" || Array.isArray(vars)) {
          ctrl.errors = [ts("Sample values: enter a JSON object.")];
          return;
        }
        if (!ctrl.workflow) {
          ctrl.errors = [ts("Choose a template.")];
          return;
        }
        ctrl.errors = [];
        crmApi4("Herald", "renderTemplate", { workflow: ctrl.workflow, vars: vars })
          .then((rows) => {
            ctrl.body = rows[0].body;
            // Shown in a sandboxed frame without scripts.
            ctrl.trusted = $sce.trustAsHtml(ctrl.body);
            ctrl.status = ts("Template rendered.");
          })
          .catch((failure) => {
            ctrl.errors = heraldAdmin.errorList(failure);
          });
      };
    },
  ]);
})(angular);
