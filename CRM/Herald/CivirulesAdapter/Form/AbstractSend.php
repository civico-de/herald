<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_CivirulesActions_Form_Form')) {
  /**
   * Shared form scaffolding for herald CiviRules actions: load existing
   * action params, the delivery fields (a topic, or a channel and the keys of
   * the channel types), submit handler.
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
        'send_via',
        E::ts('Send'),
        [
          'own' => E::ts('With a delivery of its own'),
          'topic' => E::ts('Through a topic'),
        ],
        required: TRUE,
      );
      $this->add(
        'select',
        'topic',
        E::ts('Topic (its route sets channel, recipients and aggregation)'),
        ['' => E::ts('- select topic -')] + CRM_Herald_CivirulesAdapter_SendOptions::topics(),
      );
      $this->add(
        'select',
        'delivery_mode',
        E::ts('Delivery mode'),
        CRM_Herald_CivirulesAdapter_SendOptions::deliveryModes(),
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
        E::ts('Channel (direct and address-list mode — subscribers use their own preferred channel)'),
        ['' => E::ts('- select channel -')] + CRM_Herald_CivirulesAdapter_SendOptions::channels(),
      );
      // A digest brings its own template.
      $templates = $this->getTemplateOptions();
      if ($templates !== []) {
        $this->add('select', 'template', E::ts('Message template'), $templates, required: TRUE);
      }
      CRM_Herald_CivirulesAdapter_SendFormFields::addTo($this);
      $this->add(
        'text',
        'aggregate_minutes',
        E::ts(
          'Aggregate window in minutes (0 = send immediately; > 0 collects alerts and sends one combined message per window to prevent spam)',
        ),
        ['size' => 5],
      );

      $this->addExtraFields();
      $this->addFormRule(
        fn (array $values): array|bool => CRM_Herald_CivirulesAdapter_SendFormFields::errors(
          Cast::namedOnly($values),
          $this->getExtraValues(...),
        ),
      );

      $this->addButtons([
        ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
        ['type' => 'cancel', 'name' => E::ts('Cancel')],
      ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function setDefaultValues() {
      /** @var array<string, mixed> $defaults */
      $defaults = parent::setDefaultValues();
      $params = array_filter(
        Cast::namedOnly($this->getActionParameters()),
        fn (mixed $value, int|string $key): bool => is_scalar($value) && $this->elementExists((string) $key),
        ARRAY_FILTER_USE_BOTH,
      );
      $params = CRM_Herald_CivirulesAdapter_SendFormFields::defaults($params);
      $defaults =
        $params
        + $defaults
        + [
          'send_via' => empty($params['topic']) ? 'own' : 'topic',
          'delivery_mode' => 'direct',
        ];
      return array_filter($defaults, static fn (mixed $value): bool => $value !== NULL) + $this->extraDefaults();
    }

    public function postProcess(): void {
      /** @var array<string, mixed> $values */
      $values = $this->exportValues();
      $params = CRM_Herald_CivirulesAdapter_SendParams::of($values);
      if (!$this->supportsScopeEntity()) {
        unset($params['scope_entity']);
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
