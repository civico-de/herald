<?php

declare(strict_types = 1);

use Civi\Herald\Subscribers\SubscriptionScope;
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

    /**
     * Help shown below a field, by element name.
     * @var array<string, string>
     */
    protected array $descriptions = [];

    /**
     * Fields whose defaults suit most rules; the form lists them last, under Advanced,
     * followed by the channel types' keys that are no recipient.
     */
    private const ADVANCED = ['respect_suspension', 'email_location_type', 'scope_entity', 'aggregate_minutes', 'vars'];

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
        E::ts('Topic'),
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
        'subscribers_of',
        E::ts('Subscribers of topic'),
        CRM_Herald_CivirulesAdapter_SendOptions::subscribableTopics(),
      );
      $this->add(
        'select',
        'group_id',
        E::ts('Group'),
        CRM_Herald_CivirulesAdapter_SendOptions::groups(),
      );
      $this->add(
        'textarea',
        'email_addresses',
        E::ts('Email addresses'),
        ['rows' => 3, 'cols' => 60],
      );
      $this->add(
        'checkbox',
        'respect_suspension',
        E::ts('Respect communication suspension'),
      );
      $this->add(
        'select',
        'email_location_type',
        E::ts('Email location type'),
        CRM_Herald_CivirulesAdapter_SendOptions::locationTypes(),
      );
      if ($this->supportsScopeEntity()) {
        $this->add(
          'select',
          'scope_entity',
          E::ts('Subscription scope'),
          ['' => E::ts('- none (all subscriptions) -')] + SubscriptionScope::entities(),
        );
      }
      $this->add(
        'select',
        'channel',
        E::ts('Channel'),
        ['' => E::ts('- select channel -')] + CRM_Herald_CivirulesAdapter_SendOptions::channels(),
      );
      // A digest brings its own template.
      $templates = $this->getTemplateOptions();
      if ($templates !== []) {
        $this->add('select', 'template', E::ts('Message template'), $templates, required: TRUE);
      }
      $presentation = CRM_Herald_CivirulesAdapter_SendFormFields::addTo($this);
      $this->add(
        'text',
        'aggregate_minutes',
        E::ts('Aggregate window in minutes'),
        ['size' => 5],
      );

      $this->descriptions = [
        'topic' => E::ts('Its route sets the channel, the recipients and the aggregation.'),
        'email_addresses' => E::ts('Separated by commas or new lines. The addresses are not linked to contacts.'),
        'respect_suspension' => E::ts('Skips contacts who opted out of email or whose address is on hold.'),
        'email_location_type' => E::ts(
          'Which email address of a contact to use; without one of this type, the primary one.',
        ),
        'scope_entity' => E::ts(
          'Reaches only the subscriptions scoped to the record that fired the rule, plus unscoped ones.',
        ),
        'aggregate_minutes' => E::ts(
          '0 sends each alert at once; a window collects alerts and sends them as one message.',
        ),
      ];
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
      CRM_Herald_CivirulesAdapter_FormElements::assign($this, [...self::ADVANCED, ...$presentation]);
      $this->assign('heraldDescriptions', $this->descriptions);
      $advanced = array_values(array_filter(self::ADVANCED, $this->elementExists(...)));
      $this->assign('heraldAdvanced', array_fill_keys($advanced, TRUE));
      $this->assign('heraldAdvancedLabel', E::ts('Advanced'));
      \Civi::resources()->addVars('heraldSendForm', ['advanced' => $advanced]);
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
