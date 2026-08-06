<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;
use CRM_Herald_ExtensionUtil as E;

/**
 * Shared form scaffolding for herald CiviRules actions: load existing
 * action params, common fields (channel/template/chat_id), submit handler.
 *
 * Subclasses add their action-specific fields in addExtraFields() and pull
 * extra values out via getExtraValues().
 * @mago-expect lint:too-many-methods
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 */
abstract class CRM_Herald_CivirulesAdapter_Form_AbstractSend extends CRM_CivirulesActions_Form_Form {

  /**
   * @var array<string, string> Friendly labels for registered channels. */
  private const CHANNEL_LABELS = [
    'telegram' => 'Telegram',
    'webhook' => 'Generic webhook (Slack/Discord/Mattermost/custom)',
    'ntfy' => 'ntfy.sh',
    'pushover' => 'Pushover',
    'email' => 'Email',
  ];

  /**
   * Workflow templates this action supports. Subclasses can narrow.
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
   * as a flat assoc array merged into the saved action params.
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
      [
        'direct' => E::ts('Direct — send to one recipient configured below'),
        'subscribers' => E::ts('Subscribers — fan out to all contacts opted into a subscription type'),
        'group' => E::ts('Group — fan out to all contacts of a CiviCRM group'),
        'addresses' => E::ts('Address list — send to raw email addresses (no contact linkage)'),
      ],
      TRUE,
    );
    $this->add(
      'select',
      'subscription_type',
      E::ts('Subscription type (only used in subscriber mode)'),
      $this->getSubscriptionTypeOptions(),
      FALSE,
    );
    $this->add(
      'select',
      'group_id',
      E::ts('Group (only used in group mode)'),
      ['' => E::ts('- select group -')] + $this->getGroupOptions(),
      FALSE,
    );
    $this->add(
      'textarea',
      'email_addresses',
      E::ts('Email addresses (address-list mode; comma or newline separated)'),
      [
        'rows' => 3,
        'cols' => 60,
      ],
    );
    $this->heraldCheckbox(
      'respect_suspension',
      E::ts('Respect communication suspension (skip opted-out / on-hold email recipients in fan-outs)'),
    );
    $this->add(
      'select',
      'email_location_type',
      E::ts('Preferred location type for email recipients (fallback: primary address)'),
      ['' => E::ts('- primary -')] + $this->getLocationTypeOptions(),
      FALSE,
    );
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
      FALSE,
    );
    $this->add(
      'select',
      'channel',
      E::ts('Channel (direct mode only — subscribers use their own preferred channel)'),
      $this->getChannelOptions(),
      TRUE,
    );
    $this->add('select', 'template', E::ts('Message template'), $this->getTemplateOptions(), TRUE);
    $this->add('text', 'chat_id', E::ts('Telegram chat ID (optional, falls back to default setting)'), ['size' => 30]);
    $this->add(
      'select',
      'parse_mode',
      E::ts('Telegram parse mode'),
      [
        'HTML' => 'HTML',
        'MarkdownV2' => 'MarkdownV2',
        '' => E::ts('Plain text'),
      ],
      FALSE,
    );
    $this->heraldCheckbox('disable_notification', E::ts('Silent message (Telegram)'));
    $this->heraldCheckbox('disable_web_page_preview', E::ts('Disable link previews (Telegram)'));
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
    $params = $this->getActionParameters();
    foreach ([
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
    ] as $field) {
      if (!array_key_exists($field, $params)) {
        continue;
      }

      $defaults[$field] = $params[$field];
    }
    if (!isset($defaults['delivery_mode'])) {
      $defaults['delivery_mode'] = 'direct';
    }
    if (!empty($params['channel_config'])) {
      $cc = $params['channel_config'];
      $defaults['channel_config'] = is_array($cc)
        ? json_encode($cc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        : self::toStringValue($cc);
    }
    foreach ($this->extraDefaults() as $key => $value) {
      if (!(!array_key_exists($key, $defaults) || $defaults[$key] === NULL)) {
        continue;
      }

      $defaults[$key] = $value;
    }
    return $defaults;
  }

  public function postProcess(): void {
    /** @var array<string, mixed> $values */
    $values = $this->exportValues();
    $params = [
      'delivery_mode' => $values['delivery_mode'] ?? 'direct',
      'subscription_type' => $values['subscription_type'] ?? '',
      'group_id' => (int) self::toStringValue($values['group_id'] ?? ''),
      'email_addresses' => self::toStringValue($values['email_addresses'] ?? ''),
      'respect_suspension' => !empty($values['respect_suspension']),
      'email_location_type' => (int) self::toStringValue($values['email_location_type'] ?? ''),
      'scope_entity' => self::toStringValue($values['scope_entity'] ?? ''),
      'channel' => $values['channel'] ?? 'telegram',
      'template' => $values['template'] ?? '',
      'chat_id' => $values['chat_id'] ?? '',
      'parse_mode' => $values['parse_mode'] ?? 'HTML',
      'disable_notification' => !empty($values['disable_notification']),
      'disable_web_page_preview' => !empty($values['disable_web_page_preview']),
      'aggregate_minutes' => max(0, (int) self::toStringValue($values['aggregate_minutes'] ?? '')),
      'channel_config' => self::parseChannelConfig(self::toStringValue($values['channel_config'] ?? '')),
    ];
    $params = array_merge($params, $this->getExtraValues($values));
    $this->ruleAction->action_params = serialize($params);
    $this->ruleAction->save();

    \CRM_Core_Session::setStatus(E::ts('herald action saved.'), '', 'success');
    parent::postProcess();
  }

  /**
   * Resolve the channel dropdown from ChannelRegistry::default(). Channels
   * that don't have a label fall back to their key.
   *
   * @return array<string, string>
   */
  protected function getChannelOptions(): array {
    $opts = [];
    foreach (ChannelRegistry::default()->listKeys() as $key) {
      $opts[$key] = self::CHANNEL_LABELS[$key] ?? $key;
    }
    return $opts ?: ['telegram' => 'Telegram'];
  }

  /**
   * Subscription-type dropdown — populated from the
   * herald_subscription_type option group. Returns an empty list when
   * the option group hasn't been installed yet (form still saves; subscriber
   * mode just has no targets).
   *
   * @return array<string, string>
   */
  protected function getSubscriptionTypeOptions(): array {
    try {
      $rows = \Civi\Api4\OptionValue::get(FALSE)
        ->addSelect('value', 'label')
        ->addWhere('option_group_id:name', '=', 'herald_subscription_type')
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('weight', 'ASC')
        ->execute()
        ->getArrayCopy();
    }
    catch (\Throwable $e) {
      return ['' => E::ts('— none configured —')];
    }
    $opts = ['' => E::ts('— none —')];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $value = $row['value'] ?? NULL;
      if (!is_string($value) && !is_int($value)) {
        continue;
      }
      $label = $row['label'] ?? NULL;
      $opts[(string) $value] = is_string($label) ? $label : (string) $value;
    }
    return $opts;
  }

  /**
   * Group dropdown for group-mode delivery (active, non-hidden groups).
   *
   * @return array<int|string, string>
   */
  protected function getGroupOptions(): array {
    try {
      $rows = \Civi\Api4\Group::get(FALSE)
        ->addSelect('id', 'title')
        ->addWhere('is_active', '=', TRUE)
        ->addWhere('is_hidden', '=', FALSE)
        ->addOrderBy('title', 'ASC')
        ->execute()
        ->getArrayCopy();
    }
    catch (\Throwable $e) {
      return [];
    }
    return self::idLabelOptions($rows, 'title');
  }

  /**
   * Location-type dropdown for email recipient resolution.
   *
   * @return array<int|string, string>
   */
  protected function getLocationTypeOptions(): array {
    try {
      $rows = \Civi\Api4\LocationType::get(FALSE)
        ->addSelect('id', 'display_name')
        ->addWhere('is_active', '=', TRUE)
        ->addOrderBy('display_name', 'ASC')
        ->execute()
        ->getArrayCopy();
    }
    catch (\Throwable $e) {
      return [];
    }
    return self::idLabelOptions($rows, 'display_name');
  }

  /**
   * Map APIv4 rows to an id => label dropdown option list. PHP folds the
   * numeric-string keys to int, hence the int|string key type.
   *
   * @param array<array-key, mixed> $rows
   * @return array<int|string, string>
   */
  private static function idLabelOptions(array $rows, string $labelKey): array {
    $opts = [];
    foreach ($rows as $row) {
      if (!is_array($row) || !is_numeric($row['id'] ?? NULL)) {
        continue;
      }
      $label = $row[$labelKey] ?? NULL;
      $opts[(string) (int) $row['id']] = is_string($label) ? $label : (string) (int) $row['id'];
    }
    return $opts;
  }

  /**
   * Decode the channel_config textarea into an assoc array (or empty array
   * for blank input). Throws on malformed JSON so the admin gets a clear
   * error instead of silently saving an empty config.
   *
   * @return array<array-key, mixed>
   */
  public static function parseChannelConfig(string $raw): array {
    if (trim($raw) === '') {
      return [];
    }
    $decoded = json_decode($raw, TRUE);
    if (!is_array($decoded)) {
      throw new \CRM_Core_Exception(E::ts('Channel-specific config must be a JSON object: %1', [
        1 => json_last_error_msg(),
      ]));
    }
    return $decoded;
  }

  /**
   * Subclass extension point: defaults for fields added in addExtraFields().
   *
   * @return array<string, mixed>
   */
  protected function extraDefaults(): array {
    return [];
  }

  protected function heraldCheckbox(string $name, string $label): void {
    $this->add('checkbox', $name, $label);
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

  /**
   * Normalise a mixed form/action-param value to a string. QuickForm
   * text/select fields and previously-saved action params are always
   * scalar in practice; this documents that assumption with a real check
   * instead of casting mixed directly (falls back to '' for anything
   * non-scalar, matching how (string) already treats NULL).
   */
  protected static function toStringValue(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

}
