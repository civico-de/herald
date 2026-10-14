(function (angular) {
  "use strict";

  const module = angular.module("heraldDigest", CRM.angRequires("heraldDigest"));

  // <div herald-digest-block>: a block added on the form starts with the schema
  // defaults. afform_default would also overwrite the values of loaded blocks.
  module.directive("heraldDigestBlock", function () {
    return {
      restrict: "A",
      require: "^^afRepeatItem",
      link: function (scope, element, attrs, repeatItem) {
        const block = repeatItem.getFieldData();
        if (block.id) {
          return;
        }
        for (const [field, value] of Object.entries(CRM.heraldDigest.blockDefaults)) {
          if (block[field] === undefined) {
            block[field] = value;
          }
        }
      },
    };
  });

  // <div herald-digest-block-type="contribution search">: rendered, like ng-if, only
  // while the block of the surrounding af-repeat row has one of these types, so the
  // fields of other types neither show nor block the submit.
  module.directive("heraldDigestBlockType", function () {
    return {
      restrict: "A",
      require: "^^afRepeatItem",
      transclude: "element",
      priority: 600,
      terminal: true,
      link: function (scope, element, attrs, repeatItem, transclude) {
        const types = attrs.heraldDigestBlockType.split(" ");
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
})(angular);
