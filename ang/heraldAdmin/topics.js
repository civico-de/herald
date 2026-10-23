(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");

  angular.module("heraldAdmin").controller("HeraldAdminTopicsCtrl", [
    "$q",
    "$timeout",
    "crmApi4",
    "heraldAdmin",
    function ($q, $timeout, crmApi4, heraldAdmin) {
      const ctrl = this;
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";
      ctrl.loaded = false;

      const load = () =>
        $q
          .all([
            heraldAdmin.setting("herald_topics"),
            heraldAdmin.channels(),
            crmApi4("Herald", "getTopicUsage"),
          ])
          .then((loaded) => {
            const map = heraldAdmin.asMap(loaded[0]);
            const usage = {};
            loaded[2].forEach((row) => {
              usage[row.topic] = row;
            });
            const channels = {};
            loaded[1].forEach((channel) => {
              channels[channel.key] = channel.label;
            });
            const modes = {};
            heraldAdmin.modes.forEach((mode) => {
              modes[mode.value] = mode.label;
            });
            ctrl.rows = Object.keys(map).map((name) => {
              const route = heraldAdmin.asMap(map[name]);
              const used = usage[name] || { used_by: [], subscribers: 0 };
              return {
                name: name,
                label: route.label || name,
                subscribable: route.subscribable === true,
                subscribers: used.subscribers,
                href: "#/topics/edit/" + encodeURIComponent(name),
                channel: route.channel ? channels[route.channel] || route.channel : "",
                mode: route.delivery_mode ? modes[route.delivery_mode] || route.delivery_mode : "",
                fallback: name === "default" ? ts("(not set)") : ts("(from default)"),
                aggregate: heraldAdmin.isEmpty(route.aggregate_minutes)
                  ? ""
                  : String(route.aggregate_minutes),
                usedBy: used.used_by,
              };
            });
            ctrl.hasDefault = heraldAdmin.has(map, "default");
            ctrl.loaded = true;
            return ctrl.rows;
          })
          .catch((failure) => {
            ctrl.errors = heraldAdmin.errorList(failure);
          });

      const remove = (row) =>
        crmApi4("Herald", "deleteTopic", { topic: row.name })
          .then(() => {
            ctrl.errors = [];
            ctrl.status = ts('Deleted topic "%1".', { 1: row.name });
            return load();
          })
          .catch((failure) => {
            ctrl.errors = heraldAdmin.errorList(failure);
          });

      ctrl.remove = (row) => {
        CRM.confirm({
          title: ts('Delete topic "%1"', { 1: row.name }),
          message: row.subscribers
            ? ts(
                "Messages to this topic then use the default topic, if there is one. Contacts who subscribed to it: %1. Their subscriptions stay stored but no longer reach anyone.",
                { 1: row.subscribers },
              )
            : ts("Messages to this topic then use the default topic, if there is one."),
        }).on("crmConfirm:yes", () => {
          // The dialog runs outside Angular's digest.
          $timeout(() => remove(row));
        });
      };

      load();
    },
  ]);
})(angular);
