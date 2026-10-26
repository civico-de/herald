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

      const focus = (id) => $timeout(() => document.getElementById(id).focus());

      ctrl.monitorText = (row) => {
        if (row.status === "fail") {
          return row.notified ? ts("Failing, reported") : ts("Failing, muted");
        }
        return row.failing ? ts("Failing, not reported yet") : ts("OK");
      };

      ctrl.visibleText = (row) => {
        if (row.visible === null) {
          return ts("No message");
        }
        return row.visible ? ts("Shown") : ts("Hidden");
      };

      const toSnooze = (row) => ({
        pattern: row.pattern,
        until: row.until,
        untilTs: row.until_ts,
        permanent: row.until === "",
        input: row.until_ts ? new Date(row.until_ts * 1000) : null,
      });

      const load = () =>
        crmApi4("Herald", "getMonitorChecks")
          .then((result) => {
            const screen = result[0];
            const labels = Object.fromEntries(
              screen.levels.map((level) => [level.name, level.label]),
            );
            ctrl.checks = screen.checks.map((row) =>
              Object.assign({}, row, {
                thresholdLabel: labels[row.threshold],
                lastNotifiedText: row.last_notified
                  ? CRM.utils.formatDate(new Date(row.last_notified * 1000), null, true)
                  : "",
              }),
            );
            ctrl.levels = screen.levels;
            ctrl.statusUrl = screen.status_url;
            ctrl.minSeverity = screen.min_severity;
            ctrl.exceptions = Object.keys(screen.severity).map((pattern) => ({
              pattern: pattern,
              level: screen.severity[pattern],
            }));
            ctrl.snoozes = screen.snooze.map(toSnooze);
            ctrl.expected = screen.expected.map((key) => ({ key: key }));
            ctrl.loaded = true;
            return ctrl.checks;
          })
          .catch((failure) => {
            ctrl.errors = heraldAdmin.errorList(failure);
          });

      ctrl.addException = () => {
        ctrl.exceptions.push({ pattern: "", level: "error" });
        ctrl.status = ts("Exception added; enter its key and save to apply.");
        focus("herald-exception-pattern-" + (ctrl.exceptions.length - 1));
      };

      ctrl.addSnooze = () => {
        ctrl.snoozes.push({ pattern: "", until: "", untilTs: null, permanent: false, input: null });
        ctrl.status = ts("Snooze added; enter its key and save to apply.");
        focus("herald-snooze-pattern-" + (ctrl.snoozes.length - 1));
      };

      ctrl.addExpected = () => {
        ctrl.expected.push({ key: "" });
        ctrl.status = ts("Expected extension added; enter its key and save to apply.");
        focus("herald-expected-" + (ctrl.expected.length - 1));
      };

      // Focus moves to the add button of the list, which always exists.
      ctrl.remove = (list, index, label, addButtonId) => {
        list.splice(index, 1);
        ctrl.status = ts('"%1" removed; save to apply.', { 1: label || ts("Empty row") });
        focus(addButtonId);
      };

      const snoozeMap = () => {
        const map = {};
        ctrl.snoozes.forEach((row) => {
          if (row.permanent) {
            map[row.pattern] = "";
            return;
          }
          // An untouched snooze goes back as stored.
          const unchanged = row.untilTs && row.input.getTime() === row.untilTs * 1000;
          map[row.pattern] = unchanged ? row.until : isoLocal(row.input);
        });
        return map;
      };

      ctrl.save = (form) => {
        if (form.$invalid || ctrl.snoozes.some((row) => !row.permanent && !row.input)) {
          ctrl.errors = [ts("Enter each snooze end as a complete date and time, or tick No end.")];
          return;
        }
        const severity = {};
        ctrl.exceptions.forEach((row) => {
          severity[row.pattern] = row.level;
        });
        ctrl.saving = true;
        crmStatus(
          { start: ts("Saving..."), success: ts("Monitor settings saved.") },
          crmApi4("Herald", "saveMonitorChecks", {
            minSeverity: ctrl.minSeverity,
            severity: severity,
            snooze: snoozeMap(),
            expected: ctrl.expected.map((row) => row.key.trim()),
          }),
        )
          .then(() => {
            ctrl.saving = false;
            ctrl.errors = [];
            ctrl.status = ts("Monitor settings saved.");
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
