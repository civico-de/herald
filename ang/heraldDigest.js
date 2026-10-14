(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");
  const module = angular.module("heraldDigest", CRM.angRequires("heraldDigest"));
  const failed = (failure) => [
    String((failure && failure.error_message) || ts("The request failed.")),
  ];

  // <div herald-digest-source>: a source added on the form starts with the schema
  // defaults. afform_default would also overwrite the values of loaded sources.
  module.directive("heraldDigestSource", function () {
    return {
      restrict: "A",
      require: "^^afRepeatItem",
      link: function (scope, element, attrs, repeatItem) {
        const source = repeatItem.getFieldData();
        if (source.id) {
          return;
        }
        for (const [field, value] of Object.entries(CRM.heraldDigest.sourceDefaults)) {
          if (source[field] === undefined) {
            source[field] = value;
          }
        }
      },
    };
  });

  // <div herald-digest-source-type="search">: rendered, like ng-if, only while the
  // source of the surrounding af-repeat row has one of these types, so the fields of
  // other types neither show nor block the submit.
  module.directive("heraldDigestSourceType", function () {
    return {
      restrict: "A",
      require: "^^afRepeatItem",
      transclude: "element",
      priority: 600,
      terminal: true,
      link: function (scope, element, attrs, repeatItem, transclude) {
        const types = attrs.heraldDigestSourceType.split(" ");
        let shown = null;
        scope.$watch(
          () => types.includes(repeatItem.getFieldData().type),
          (show) => {
            if (show && !shown) {
              transclude((clone, childScope) => {
                shown = { clone: clone, scope: childScope };
                element.after(clone);
              });
            } else if (!show && shown) {
              shown.scope.$destroy();
              shown.clone.remove();
              shown = null;
            }
          },
        );
      },
    };
  });

  // <div herald-digest-placeholders="HeraldDigest1">: the placeholders of the source
  // in this af-repeat row and a snippet that uses them, refreshed as the source changes.
  module.directive("heraldDigestPlaceholders", [
    "$timeout",
    "crmApi4",
    function ($timeout, crmApi4) {
      return {
        restrict: "A",
        require: ["^^afRepeatItem", "^^afForm"],
        scope: {},
        template: `
          <div class="herald-digest">
            <div aria-live="polite">
              <ul ng-if="guide.problems.length" class="alert alert-warning">
                <li ng-repeat="problem in guide.problems">{{ problem }}</li>
              </ul>
            </div>
            <div ng-if="guide.placeholders.length">
              <p><strong>{{:: labels.placeholders }}</strong></p>
              <ul class="herald-digest-placeholders">
                <li ng-repeat="p in guide.placeholders"><code>{{ p.placeholder }}</code> {{ p.label }}</li>
              </ul>
            </div>
            <div ng-if="guide.snippet">
              <label for="{{:: id }}">{{:: labels.snippet }}</label>
              <textarea id="{{:: id }}" class="crm-form-textarea herald-digest-snippet" readonly rows="{{ rows() }}" ng-model="guide.snippet"></textarea>
              <button type="button" class="btn btn-secondary" ng-click="copy()">
                <i class="crm-i fa-clipboard" aria-hidden="true"></i> {{:: labels.copy }}
              </button>
              <span class="herald-digest-copied" aria-live="polite">{{ copied }}</span>
            </div>
          </div>`,
        link: function (scope, element, attrs, [repeatItem, afForm]) {
          scope.labels = {
            placeholders: ts("Placeholders of this source"),
            snippet: ts("Snippet for the template"),
            copy: ts("Copy snippet"),
          };
          scope.id = "herald-digest-snippet-" + scope.$id;
          scope.rows = () => Math.min(8, scope.guide.snippet.split("\n").length);
          scope.copy = () => {
            const say = (message) => scope.$evalAsync(() => (scope.copied = message));
            // The clipboard exists only on https and localhost.
            (navigator.clipboard
              ? navigator.clipboard.writeText(scope.guide.snippet)
              : Promise.reject()
            ).then(
              () => say(ts("Snippet copied.")),
              () => say(ts("Copying failed; select the text instead.")),
            );
          };
          const digestName = () => {
            const record = (afForm.getData(attrs.heraldDigestPlaceholders) || [])[0];
            return (record && record.fields && record.fields.name) || "";
          };
          let pending = null;
          let request = 0;
          scope.$watch(
            () => {
              const source = repeatItem.getFieldData();
              return [source.name, source.type, source.search_display_id, source.section_key];
            },
            ([name, type, display, key]) => {
              $timeout.cancel(pending);
              const current = ++request;
              scope.guide = null;
              scope.copied = "";
              if (!name || !(type === "search" ? display : key)) {
                return;
              }
              // Waits for a pause in typing before asking.
              pending = $timeout(() => {
                const source = {
                  name: name,
                  type: type,
                  search_display_id: display,
                  section_key: key,
                };
                const show = (guide) => {
                  if (current === request) {
                    scope.guide = guide;
                  }
                };
                crmApi4("Herald", "getDigestPlaceholders", { source: source, digest: digestName() })
                  .then((rows) => show(rows[0]))
                  .catch((failure) =>
                    show({ placeholders: [], snippet: "", problems: failed(failure) }),
                  );
              }, 400);
            },
            true,
          );
        },
      };
    },
  ]);

  // <div herald-digest-preview="HeraldDigest1">: composes the digest as the form
  // holds it, with today's data; nothing is saved or sent.
  module.directive("heraldDigestPreview", [
    "crmApi4",
    function (crmApi4) {
      return {
        restrict: "A",
        require: "^^afForm",
        scope: {},
        template: `
          <div class="herald-digest">
            <button type="button" class="btn btn-secondary" ng-click="run()" ng-disabled="busy">
              <i class="crm-i fa-eye" aria-hidden="true"></i> {{:: labels.preview }}
            </button>
            <p class="sr-only" aria-live="polite">{{ status }}</p>
            <ul ng-if="preview.problems.length" class="alert alert-warning">
              <li ng-repeat="problem in preview.problems">{{ problem }}</li>
            </ul>
            <div ng-show="shown">
              <p><strong>{{:: labels.subject }}</strong> {{ preview.subject }}</p>
              <iframe class="herald-digest-preview" sandbox="" title="{{:: labels.frame }}"></iframe>
            </div>
          </div>`,
        link: function (scope, element, attrs, afForm) {
          scope.labels = {
            preview: ts("Preview with today's data"),
            subject: ts("Subject:"),
            frame: ts("Digest preview"),
          };
          const show = (preview) => {
            scope.preview = preview;
            // Shown in a sandboxed frame without scripts; an interpolated srcdoc stays unrendered.
            scope.shown = preview.body !== null;
            element.find("iframe")[0].srcdoc = preview.body || "";
            scope.status = preview.problems.length
              ? ts("The digest cannot be composed.")
              : ts("The preview is ready.");
            scope.busy = false;
          };
          scope.run = () => {
            const record = (afForm.getData(attrs.heraldDigestPreview) || [])[0] || {};
            scope.busy = true;
            scope.status = ts("Composing the preview.");
            crmApi4("Herald", "previewDigest", {
              digest: record.fields || {},
              sources: (record.joins || {}).HeraldDigestSource || [],
            })
              .then((rows) => show(rows[0]))
              .catch((failure) => show({ subject: null, body: null, problems: failed(failure) }));
          };
        },
      };
    },
  ]);
})(angular);
