(function (angular) {
  "use strict";

  const ts = CRM.ts("herald");
  const module = angular.module("heraldDigest", CRM.angRequires("heraldDigest"));
  const failed = (failure) => [
    String((failure && failure.error_message) || ts("The request failed.")),
  ];

  // <div herald-digest-intro>: what a digest is and the order to fill the form in,
  // in CiviCRM's help box.
  module.directive("heraldDigestIntro", function () {
    return {
      restrict: "A",
      scope: {},
      template: `
        <div class="help">
          <p>{{:: text.what }}</p>
          <ol>
            <li ng-repeat="step in text.steps">{{:: step }}</li>
          </ol>
          <p>{{:: text.aggregate }}</p>
        </div>`,
      link: function (scope) {
        scope.text = {
          what: ts(
            "A digest is a recurring report, such as a weekly summary of donations. A CiviRules rule or a scheduled job sends it by its name through herald's channels.",
          ),
          steps: [
            ts(
              "Add a source for each set of figures: a ready-made one, such as yesterday's contributions, or your own search from SearchKit, whose filters pick the rows and whose column headers name the values. A section comes from another extension.",
            ),
            ts(
              "Write the subject and text. Below each source the form lists its placeholders and a snippet to copy.",
            ),
            ts("Check the preview with the current data, then save."),
          ],
          aggregate: ts(
            "For news of every new contribution, no digest is needed: a CiviRules rule sends each one as it comes in, or collects them into one message every few minutes.",
          ),
        };
      },
    };
  });

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

  // <div herald-digest-source-header>: the title of the source in this af-repeat row and
  // a labelled remove button in place of af-repeat's icon-only one.
  module.directive("heraldDigestSourceHeader", [
    "$timeout",
    function ($timeout) {
      return {
        restrict: "A",
        require: "^^afRepeatItem",
        scope: {},
        template: `
          <div class="herald-digest-source-header">
            <h4 class="herald-digest-source-title">{{ title() }}</h4>
            <button type="button" class="btn btn-sm btn-danger" ng-click="remove()" aria-label="{{ removeLabel() }}">
              <i class="crm-i fa-trash" aria-hidden="true"></i> {{:: labels.remove }}
            </button>
          </div>`,
        link: function (scope, element, attrs, repeatItem) {
          scope.labels = { remove: ts("Remove source") };
          const number = () => repeatItem.repeatIndex + 1;
          const name = () => repeatItem.getFieldData().name;
          scope.title = () =>
            name()
              ? ts("Source %1: %2", { 1: number(), 2: name() })
              : ts("Source %1", { 1: number() });
          scope.removeLabel = () => ts("Remove source %1", { 1: name() || number() });
          scope.remove = () => {
            const list = element.closest("[af-repeat]");
            repeatItem.afRepeat.getItems().splice(repeatItem.repeatIndex, 1);
            // The button is gone with its row; keyboard focus moves to "Add source".
            $timeout(() => list.find(".af-repeat-add-btn").last().trigger("focus"));
          };
        },
      };
    },
  ]);

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

  // <div herald-digest-source-kind>: sets the source type in place of its select.
  // "Ready-made" picks one of herald's shipped displays by figures and period; "own
  // search" shows the SearchKit field instead.
  let sourceKindRows = 0;
  module.directive("heraldDigestSourceKind", function () {
    return {
      restrict: "A",
      require: "^^afRepeatItem",
      scope: {},
      template: `
        <fieldset class="herald-digest-source-kind">
          <legend>{{:: text.kind }}</legend>
          <label class="radio-inline" ng-repeat="kind in kinds">
            <input type="radio" name="{{:: id }}-kind" ng-value="kind.key" ng-model="state.kind" ng-change="kindChanged()"> {{:: kind.label }}
          </label>
        </fieldset>
        <div class="herald-digest-ready-made-pick" ng-if="state.kind === 'ready'">
          <div class="af-field">
            <label for="{{:: id }}-figure">{{:: text.figure }} <span class="crm-marker" aria-hidden="true">*</span></label>
            <select id="{{:: id }}-figure" class="form-control crm-form-select" required
              ng-model="state.figure" ng-change="pick()"
              ng-options="key as label for (key, label) in figures">
              <option value="">{{:: text.choose }}</option>
            </select>
          </div>
          <div class="af-field" ng-if="periods().length">
            <label for="{{:: id }}-period">{{:: text.period }} <span class="crm-marker" aria-hidden="true">*</span></label>
            <select id="{{:: id }}-period" class="form-control crm-form-select" required
              ng-model="state.period" ng-change="pick()"
              ng-options="period as settings.periods[period] for period in periods()">
              <option value="">{{:: text.choose }}</option>
            </select>
          </div>
        </div>`,
      link: function (scope, element, attrs, repeatItem) {
        const settings = CRM.heraldDigest.readyMade;
        const displays = settings.displays;
        const source = () => repeatItem.getFieldData();
        const shipped = (id) => displays.find((display) => display.id === Number(id));
        scope.settings = settings;
        scope.id = "herald-source-kind-" + ++sourceKindRows;
        scope.text = {
          kind: ts("Kind of source"),
          figure: ts("Figures"),
          period: ts("Period"),
          choose: ts("- select -"),
        };
        scope.kinds = [
          { key: "ready", label: ts("Ready-made") },
          { key: "own", label: ts("Own search from SearchKit") },
          { key: "section", label: ts("Section from an extension") },
        ];
        scope.figures = {};
        for (const display of displays) {
          scope.figures[display.figure] = settings.figures[display.figure];
        }
        scope.state = { kind: "ready", figure: null, period: null, chosen: false };
        // Loaded sources arrive after the row renders; follow the stored source
        // until the user picks a kind.
        scope.$watchGroup([() => source().type, () => source().search_display_id], ([type, id]) => {
          const current = shipped(id);
          if (current) {
            scope.state.figure = current.figure;
            scope.state.period = current.period || scope.state.period;
          }
          if (!scope.state.chosen) {
            scope.state.kind = type === "section" ? "section" : current || !id ? "ready" : "own";
          }
        });
        scope.periods = () =>
          displays
            .filter((display) => display.figure === scope.state.figure && display.period)
            .map((display) => display.period);
        scope.pick = () => {
          const match = displays.find(
            (display) =>
              display.figure === scope.state.figure &&
              (display.period || null) === (scope.periods().length ? scope.state.period : null),
          );
          source().search_display_id = match ? match.id : null;
        };
        scope.kindChanged = () => {
          scope.state.chosen = true;
          source().type = scope.state.kind === "section" ? "section" : "search";
          if (scope.state.kind === "ready" && !shipped(source().search_display_id)) {
            scope.pick();
          }
        };
        scope.$watch(
          () => scope.state.kind,
          (kind) => element.parent().toggleClass("herald-digest-ready-made", kind === "ready"),
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
            snippet: ts("Snippet for the text"),
            copy: ts("Copy snippet"),
          };
          scope.id = "herald-digest-snippet-" + scope.$id;
          // The textarea reads this once more in the digest that clears the guide.
          scope.rows = () =>
            Math.min(8, ((scope.guide && scope.guide.snippet) || "").split("\n").length);
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

  // One font for text and charts, so the preview reads like the <pre> blocks.
  const PREVIEW_STYLE =
    "<style>body { font: 14px/1.4 ui-monospace, Menlo, Consolas, monospace; color: #222; }" +
    " pre { font: inherit; margin: 0.25em 0 0.75em; }</style>";

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
            <button type="button" class="btn btn-secondary" ng-click="sendTest()" ng-disabled="busy">
              <i class="crm-i fa-envelope" aria-hidden="true"></i> {{:: labels.test }}
            </button>
            <p class="sr-only" aria-live="polite">{{ status }}</p>
            <p ng-if="sentTo" class="alert alert-success">{{ sentTo }}</p>
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
            test: ts("Send a test to me"),
            subject: ts("Subject:"),
            frame: ts("Digest preview"),
          };
          const show = (preview) => {
            scope.preview = preview;
            // Shown in a sandboxed frame without scripts; an interpolated srcdoc stays unrendered.
            scope.shown = preview.body !== null;
            element.find("iframe")[0].srcdoc = PREVIEW_STYLE + (preview.body || "");
            scope.status = preview.problems.length
              ? ts("The digest cannot be composed.")
              : ts("The preview is ready.");
            scope.busy = false;
          };
          const formValues = () => {
            const record = (afForm.getData(attrs.heraldDigestPreview) || [])[0] || {};
            return {
              digest: record.fields || {},
              sources: (record.joins || {}).HeraldDigestSource || [],
            };
          };
          scope.run = () => {
            scope.busy = true;
            scope.sentTo = "";
            scope.status = ts("Composing the preview.");
            crmApi4("Herald", "previewDigest", formValues())
              .then((rows) => show(rows[0]))
              .catch((failure) => show({ subject: null, body: null, problems: failed(failure) }));
          };
          // Emails the digest as the form holds it to the logged-in user, without saving.
          scope.sendTest = () => {
            scope.busy = true;
            scope.sentTo = "";
            scope.status = ts("Sending the test.");
            const showSent = ({ problems, sentTo }) => {
              if (problems.length) {
                scope.preview = { subject: null, body: null, problems: problems };
                scope.shown = false;
              }
              scope.sentTo = sentTo;
              scope.status = sentTo || ts("The digest cannot be composed.");
              scope.busy = false;
            };
            crmApi4("Herald", "sendDigestTest", formValues())
              .then(([sent]) =>
                showSent({
                  problems: sent.problems,
                  sentTo: sent.sent ? ts("The test was sent to %1.", { 1: sent.to }) : "",
                }),
              )
              .catch((failure) => showSent({ problems: failed(failure), sentTo: "" }));
          };
        },
      };
    },
  ]);
})(angular);
