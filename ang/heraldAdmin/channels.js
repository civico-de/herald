(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");

  angular.module("heraldAdmin").controller("HeraldAdminChannelsCtrl", [
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
          .all([crmApi4("Herald", "getChannels", { withUsage: true }), heraldAdmin.channelTypes()])
          .then((loaded) => {
            const types = {};
            loaded[1].forEach((type) => {
              types[type.key] = type.label;
            });
            ctrl.rows = loaded[0].map((row) => ({
              name: row.name,
              label: row.label,
              type: types[row.type] || row.type,
              active: Boolean(row.active),
              subscribable: Boolean(row.subscribable),
              usedBy: row.used_by || [],
              href: "#/channels/edit/" + encodeURIComponent(row.name),
            }));
            ctrl.loaded = true;
            return ctrl.rows;
          })
          .catch((failure) => {
            ctrl.errors = heraldAdmin.errorList(failure);
          });

      const remove = (row) =>
        crmApi4("Herald", "deleteChannel", { name: row.name })
          .then(() => {
            ctrl.errors = [];
            ctrl.status = ts('Deleted the channel "%1".', { 1: row.label });
            return load();
          })
          .catch((failure) => {
            ctrl.errors = heraldAdmin.errorList(failure);
          });

      ctrl.remove = (row) =>
        crmApi4("Contact", "get", {
          select: ["row_count"],
          where: [["herald_preferences.preferred_channel", "=", row.name]],
        })
          .then((chosen) =>
            CRM.confirm({
              title: ts('Delete channel "%1"', { 1: row.label }),
              message: ts(
                "Contacts who chose it as their channel: %1. Until they choose another, subscriber deliveries skip them and group deliveries send them email.",
                { 1: chosen.count },
              ),
            }).on("crmConfirm:yes", () => {
              // The dialog runs outside Angular's digest.
              $timeout(() => remove(row));
            }),
          )
          .catch((failure) => {
            ctrl.errors = heraldAdmin.errorList(failure);
          });

      load();
    },
  ]);
})(angular);
