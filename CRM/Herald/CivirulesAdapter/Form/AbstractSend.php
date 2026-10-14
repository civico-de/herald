<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_CivirulesActions_Form_Form')) {
  /**
   * Shared form scaffolding for herald CiviRules actions: load existing
   * action params, common fields (channel/template/chat_id), submit handler.
   *
   * Subclasses add their action-specific fields in addExtraFields() and pull
   * extra values out via getExtraValues().
   * CiviRules opens its parameter screens as QuickForm forms by route.
   * @mago-expect analysis:civikitchen/legacy-ui-base
   */
  abstract class CRM_Herald_CivirulesAdapter_Form_AbstractSend extends CRM_CivirulesActions_Form_Form {

    protected const JSON_FLAGS =
      JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    /**
     * Stored params the form shows as they are.
     */
    private const SHOWN = [
      'channel',
      'template',
      'chat_id',
      'parse_mode',
      'disable_notification',
      'disable_web_page_preview',
      'delivery_mode',
      'subscription_type',
      'group_id',
      'email_addresses',
      'respect_suspension',
      'email_location_type',
      'scope_entity',
      'aggregate_minutes',
    ];

    /**
     * Workflow templates this action supports; [] for an action without a template field.
     * @return array<string, string> workflow_name => label
     */
    abstract protected function getTemplateOptions(): array;

    /**
     * Add fields specific to the subclass. Called from buildQuickForm() after
     * the shared channel/template/chat_id fields.
     */
    abstract protected function addExtraFields(): void;

    /**
     * Pull subclass-specific values out of the submitted form data, returned
     * as a flat assoc array merged into the saved action params. A value that
     * cannot be saved throws, which the form shows as its error.
     *
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    abstract protected function getExtraValues(array $submitted): array;

    public function buildQuickForm(): void {
      $this->add(
        'select',
        'delivery_mode',
        E::ts('Delivery mode'),
        CRM_Herald_CivirulesAdapter_SendOptions::deliveryModes(),
        required: TRUE,
      );
      $this->add(
        'select',
        'subscription_type',
        E::ts('Subscription type (only used in subscriber mode)'),
        CRM_Herald_CivirulesAdapter_SendOptions::subscriptionTypes(),
      );
      $this->add(
        'select',
        'group_id',
        E::ts('Group (only used in group mode)'),
        CRM_Herald_CivirulesAdapter_SendOptions::groups(),
      );
      $this->add(
        'textarea',
        'email_addresses',
        E::ts('Email addresses (address-list mode; comma or newline separated)'),
        ['rows' => 3, 'cols' => 60],
      );
      $this->add(
        'checkbox',
        'respect_suspension',
        E::ts('Respect communication suspension (skip opted-out / on-hold email recipients in fan-outs)'),
      );
      $this->add(
        'select',
        'email_location_type',
        E::ts('Preferred location type for email recipients (fallback: primary address)'),
        CRM_Herald_CivirulesAdapter_SendOptions::locationTypes(),
      );
      if ($this->supportsScopeEntity()) {
        $this->add(
          'select',
          'scope_entity',
          E::ts(
            'Subscription scope entity (subscriber mode: only reach subscriptions scoped to the trigger\'s entity instance, plus unscoped ones)',
          ),
          [
            '' => E::ts('- none (all subscriptions) -'),
            'Event' => E::ts('Event'),
            'Membership' => E::ts('Membership'),
            'Participant' => E::ts('Participant'),
          ],
        );
      }
      $this->add(
        'select',
        'channel',
        E::ts('Channel (direct mode only — subscribers use their own preferred channel)'),
        CRM_Herald_CivirulesAdapter_SendOptions::channels(),
        required: TRUE,
      );
      // A digest brings its own template.
      $templates = $this->getTemplateOptions();
      if ($templates !== []) {
        $this->add('select', 'template', E::ts('Message template'), $templates, required: TRUE);
      }
      $this->add('text', 'chat_id', E::ts('Telegram chat ID (optional, falls back to default setting)'), [
        'size' => 30,
      ]);
      $this->add('select', 'parse_mode', E::ts('Telegram parse mode'), [
        'HTML' => 'HTML',
        'MarkdownV2' => 'MarkdownV2',
        '' => E::ts('Plain text'),
      ]);
      $this->add('checkbox', 'disable_notification', E::ts('Silent message (Telegram)'));
      $this->add('checkbox', 'disable_web_page_preview', E::ts('Disable link previews (Telegram)'));
      $this->add(
        'text',
        'aggregate_minutes',
        E::ts(
          'Aggregate window in minutes (0 = send immediately; > 0 collects alerts and sends one combined message per window to prevent spam)',
        ),
        ['size' => 5],
      );
      $this->add('textarea', 'channel_config', E::ts('Channel-specific config (JSON, optional)'), [
        'rows' => 5,
        'cols' => 60,
        'placeholder' => '{"webhook_url": "https://hooks.slack.com/...", "webhook_body_format": "slack"}',
      ]);

      $this->addExtraFields();
      $this->addFormRule($this->errorsIn(...));

      $this->addButtons([
        ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
        ['type' => 'cancel', 'name' => E::ts('Cancel')],
      ]);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>|true
     */
    private function errorsIn(array $values): array|bool {
      try {
        $this->getExtraValues($values);
        $errors = CRM_Herald_CivirulesAdapter_SendParams::errors($values);
      }
      catch (\CRM_Core_Exception $e) {
        $errors = [$e->getMessage()];
      }
      return $errors === [] ? TRUE : ['_qf_default' => implode(' ', $errors)];
    }

    /**
     * @return array<string, mixed>
     */
    public function setDefaultValues() {
      /** @var array<string, mixed> $defaults */
      $defaults = parent::setDefaultValues();
      $params = $this->getActionParameters();
      $defaults = array_intersect_key($params, array_flip(self::SHOWN)) + $defaults + ['delivery_mode' => 'direct'];
      $config = $params['channel_config'] ?? [];
      if ($config !== [] && $config !== '') {
        $defaults['channel_config'] = is_array($config)
          ? json_encode($config, self::JSON_FLAGS)
          : Cast::toString($config);
      }
      return array_filter($defaults, static fn (mixed $value): bool => $value !== NULL) + $this->extraDefaults();
    }

    public function postProcess(): void {
      /** @var array<string, mixed> $values */
      $values = $this->exportValues();
      $params = CRM_Herald_CivirulesAdapter_SendParams::of($values);
      if (!$this->supportsScopeEntity()) {
        $params['scope_entity'] = '';
      }
      if ($this->elementExists('template')) {
        $params['template'] = trim(Cast::toString($values['template'] ?? ''));
      }
      $this->ruleAction->action_params = serialize(array_merge($params, $this->getExtraValues($values)));
      $this->ruleAction->save();

      \CRM_Core_Session::setStatus(E::ts('herald action saved.'), '', 'success');
      parent::postProcess();
    }

    /**
     * Whether this action offers the scope_entity field. Only Herald.notify
     * resolves scope_entity against a trigger context (Event/Membership/
     * Participant payloads); the contribution alert/digest actions have no such
     * context, so offering the field there would save a dead parameter.
     */
    protected function supportsScopeEntity(): bool {
      return FALSE;
    }

    /**
     * Subclass extension point: defaults for fields added in addExtraFields().
     *
     * @return array<string, mixed>
     */
    protected function extraDefaults(): array {
      return [];
    }

    /**
     * Read action params from the rule_action row (returns an assoc array).
     *
     * @return array<array-key, mixed>
     */
    protected function getActionParameters(): array {
      $raw = $this->ruleAction->action_params ?? '';
      if (!$raw) {
        return [];
      }
      $params = unserialize($raw, ['allowed_classes' => FALSE]);
      return is_array($params) ? $params : [];
    }

  }
}
