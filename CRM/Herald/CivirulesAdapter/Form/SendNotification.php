<?php

declare(strict_types = 1);

use Civi\Herald\Trigger\ContextVars;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_CivirulesActions_Form_Form')) {
  /**
   * Form for the per-event notification action.
   * CiviRules opens its parameter screens as QuickForm forms by route.
   * @mago-expect analysis:civikitchen/legacy-ui-base
   */
  class CRM_Herald_CivirulesAdapter_Form_SendNotification extends CRM_Herald_CivirulesAdapter_Form_AbstractSend {

    protected function getTemplateOptions(): array {
      return CRM_Herald_CivirulesAdapter_Form_SendOptions::templates();
    }

    protected function supportsScopeEntity(): bool {
      return TRUE;
    }

    protected function addExtraFields(): void {
      $this->add('textarea', 'vars', E::ts('Variables (JSON object passed to the template)'), [
        'rows' => 6,
        'cols' => 60,
        'placeholder' => '{"contact": {"display_name": "Foo Bar"}}',
      ]);
    }

    /**
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    protected function getExtraValues(array $submitted): array {
      return ['vars' => ContextVars::decodeVars($submitted)];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraDefaults(): array {
      $vars = $this->getActionParameters()['vars'] ?? [];
      return ['vars' => is_array($vars) ? json_encode($vars, self::JSON_FLAGS) : Cast::toString($vars)];
    }

  }
}
