(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");

  angular.module("heraldAdmin").controller("HeraldAdminHostsCtrl", [
    "crmApi4",
    "crmStatus",
    "heraldAdmin",
    function (crmApi4, crmStatus, heraldAdmin) {
      const ctrl = this;
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";

      heraldAdmin
        .setting("herald_webhook_allowed_hosts")
        .then((hosts) => {
          ctrl.text = (Array.isArray(hosts) ? hosts : []).join("\n");
          ctrl.loaded = true;
        })
        .catch((failure) => {
          ctrl.errors = heraldAdmin.errorList(failure);
        });

      ctrl.save = () => {
        ctrl.saving = true;
        const hosts = (ctrl.text || "").split("\n");
        crmStatus(
          { start: ts("Saving..."), success: ts("Allowed hosts saved.") },
          crmApi4("Herald", "saveAllowedHosts", { hosts: hosts }),
        )
          .then((saved) => {
            ctrl.saving = false;
            ctrl.errors = [];
            ctrl.text = saved.map((row) => row.host).join("\n");
            ctrl.status = saved.length
              ? ts("Allowed hosts saved: %1.", { 1: saved.length })
              : ts("The allowlist is empty: webhooks may call any public address.");
          })
          .catch((failure) => {
            ctrl.saving = false;
            ctrl.errors = heraldAdmin.errorList(failure);
          });
      };
    },
  ]);
})(angular);
