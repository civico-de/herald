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
        crmApi4("Custom_herald_subscription", "get", {
          select: ["COUNT(DISTINCT entity_id) AS contacts"],
          where: [["channel", "=", row.name]],
        })
          .then((subscribed) =>
            CRM.confirm({
              title: ts('Delete channel "%1"', { 1: row.label }),
              message: ts(
                "Contacts subscribed through it: %1. Their subscriptions on it stop delivering until they pick another channel; group deliveries then send them email.",
                { 1: subscribed[0] ? subscribed[0].contacts : 0 },
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
