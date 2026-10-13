(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");
  const pad = (n) => String(n).padStart(2, "0");

  // ISO 8601 with the browser's offset, so the server reads the instant the admin picked.
  const isoLocal = (date) => {
    const offset = -date.getTimezoneOffset();
    const abs = Math.abs(offset);
    return (
      date.getFullYear() +
      "-" +
      pad(date.getMonth() + 1) +
      "-" +
      pad(date.getDate()) +
      "T" +
      pad(date.getHours()) +
      ":" +
      pad(date.getMinutes()) +
      ":00" +
      (offset < 0 ? "-" : "+") +
      pad(Math.floor(abs / 60)) +
      ":" +
      pad(abs % 60)
    );
  };

  angular.module("heraldAdmin").controller("HeraldAdminMonitorCtrl", [
    "$timeout",
    "crmApi4",
    "crmStatus",
    "heraldAdmin",
    function ($timeout, crmApi4, crmStatus, heraldAdmin) {
      const ctrl = this;
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";
      ctrl.newName = "";

      ctrl.statusText = (row) => {
        if (row.status === "fail") {
          return ts("Failing");
        }
        return row.status === "ok" ? ts("OK") : ts("Not observed yet");
      };

      const toRow = (row) =>
        Object.assign({}, row, {
          snoozeInput: row.snoozed_until_ts ? new Date(row.snoozed_until_ts * 1000) : null,
          lastNotifiedDate: row.last_notified ? new Date(row.last_notified * 1000) : null,
        });

      const load = () =>
        crmApi4("Herald", "getMonitorChecks")
          .then((rows) => {
            ctrl.rows = rows.map(toRow);
            ctrl.loaded = true;
            return ctrl.rows;
          })
          .catch((failure) => {
            ctrl.errors = heraldAdmin.errorList(failure);
          });

      ctrl.clearSnooze = (row, index) => {
        row.snoozeInput = null;
        ctrl.status = ts('Snooze of "%1" cleared; save to apply.', { 1: row.name });
        $timeout(() => document.getElementById("herald-snooze-" + index).focus());
      };

      ctrl.addExpected = () => {
        const name = ctrl.newName.trim();
        if (name === "") {
          ctrl.errors = [ts("Enter the name of the check to expect.")];
          return;
        }
        const existing = ctrl.rows.find((row) => row.name === name);
        if (existing) {
          existing.expected = true;
        } else {
          ctrl.rows.push(
            toRow({ name: name, provided: false, expected: true, status: "", snoozed_until: "" }),
          );
        }
        ctrl.errors = [];
        ctrl.newName = "";
        ctrl.status = ts('"%1" is marked as expected; save to apply.', { 1: name });
      };

      ctrl.save = (form) => {
        if (form.$invalid) {
          ctrl.errors = [ts("Enter each snooze as a complete date and time, or clear it.")];
          return;
        }
        const expected = ctrl.rows.filter((row) => row.expected).map((row) => row.name);
        const snooze = {};
        ctrl.rows.forEach((row) => {
          if (!row.snoozeInput) {
            return;
          }
          // An untouched snooze goes back as stored.
          const unchanged =
            row.snoozed_until_ts && row.snoozeInput.getTime() === row.snoozed_until_ts * 1000;
          snooze[row.name] = unchanged ? row.snoozed_until : isoLocal(row.snoozeInput);
        });
        ctrl.saving = true;
        crmStatus(
          { start: ts("Saving..."), success: ts("Monitor checks saved.") },
          crmApi4("Herald", "saveMonitorChecks", { expected: expected, snooze: snooze }),
        )
          .then(() => {
            ctrl.saving = false;
            ctrl.errors = [];
            ctrl.status = ts("Monitor checks saved.");
            return load();
          })
          .catch((failure) => {
            ctrl.saving = false;
            ctrl.errors = heraldAdmin.errorList(failure);
          });
      };

      load();
    },
  ]);
})(angular);
