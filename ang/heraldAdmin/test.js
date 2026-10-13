(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");

  angular.module("heraldAdmin").controller("HeraldAdminTestCtrl", [
    "$q",
    "crmApi4",
    "heraldAdmin",
    function ($q, crmApi4, heraldAdmin) {
      const ctrl = this;
      ctrl.ts = ts;
      ctrl.errors = [];
      ctrl.status = "";
      ctrl.target = "channel";
      ctrl.channel = null;
      ctrl.topic = null;
      ctrl.inputs = {};
      ctrl.subject = "";
      ctrl.format = "text";
      ctrl.message = ts("Test message from Herald.");

      $q.all([heraldAdmin.setting("herald_topics"), heraldAdmin.channels()])
        .then((loaded) => {
          ctrl.topics = Object.keys(heraldAdmin.asMap(loaded[0]));
          ctrl.channels = loaded[1];
          ctrl.loaded = true;
        })
        .catch((failure) => {
          ctrl.errors = heraldAdmin.errorList(failure);
        });

      ctrl.selected = () => (ctrl.channels || []).find((c) => c.key === ctrl.channel);

      // Inputs of the previous channel would land as unknown keys of the next one.
      ctrl.channelChanged = () => {
        const channel = ctrl.selected();
        ctrl.inputs = channel ? heraldAdmin.toInputs(channel.fields, {}) : {};
      };

      const call = () => {
        if (ctrl.target === "topic") {
          if (!ctrl.topic) {
            return $q.reject({ error_message: ts("Choose a topic.") });
          }
          return crmApi4("Herald", "publish", {
            topic: ctrl.topic,
            message: ctrl.message,
            format: ctrl.format,
          }).then((rows) =>
            rows[0].buffered
              ? ts(
                  'Buffered for topic "%1": its route aggregates alerts, so the message goes out with the next flush.',
                  {
                    1: ctrl.topic,
                  },
                )
              : ts('Sent to topic "%1" through %2.', { 1: ctrl.topic, 2: rows[0].channel }),
          );
        }
        const channel = ctrl.selected();
        if (!channel) {
          return $q.reject({ error_message: ts("Choose a channel.") });
        }
        const built = heraldAdmin.fromInputs(channel.fields, ctrl.inputs, {});
        if (built.errors.length) {
          return $q.reject({ error_message: built.errors.join("\n") });
        }
        return crmApi4("Herald", "send", {
          channel: channel.key,
          message: ctrl.message,
          subject: ctrl.subject,
          format: ctrl.format,
          config: built.values,
        }).then(() => ts("Sent through %1.", { 1: channel.label }));
      };

      ctrl.send = () => {
        ctrl.sending = true;
        ctrl.errors = [];
        ctrl.status = ts("Sending...");
        call()
          .then((message) => {
            ctrl.sending = false;
            ctrl.status = message;
          })
          .catch((failure) => {
            ctrl.sending = false;
            ctrl.status = "";
            ctrl.errors = heraldAdmin.errorList(failure);
          });
      };
    },
  ]);
})(angular);
