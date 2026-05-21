<?php

use CRM_CivicoNotify_ExtensionUtil as E;

/**
 * Shared form scaffolding for civico_notify CiviRules actions: load existing
 * action params, common fields (channel/template/chat_id), submit handler.
 *
 * Subclasses add their action-specific fields in addExtraFields() and pull
 * extra values out via getExtraValues().
 */
abstract class CRM_CivicoNotify_Form_CivirulesAction_AbstractSendForm extends CRM_CivirulesActions_Form_Form {

  /** @var array<string, string> Channel options. v0.1 ships only Telegram. */
  protected array $channelOptions = ['telegram' => 'Telegram'];

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
   */
  abstract protected function getExtraValues(array $submitted): array;

  public function buildQuickForm() {
    $this->add('select', 'channel', E::ts('Channel'), $this->channelOptions, TRUE);
    $this->add('select', 'template', E::ts('Message template'), $this->getTemplateOptions(), TRUE);
    $this->add('text', 'chat_id', E::ts('Chat ID (optional, falls back to default setting)'), ['size' => 30]);
    $this->add('select', 'parse_mode', E::ts('Parse mode'), [
      'HTML' => 'HTML',
      'MarkdownV2' => 'MarkdownV2',
      '' => E::ts('Plain text'),
    ], FALSE);
    $this->civicoCheckbox('disable_notification', E::ts('Silent message'));
    $this->civicoCheckbox('disable_web_page_preview', E::ts('Disable link previews'));

    $this->addExtraFields();

    $this->addButtons([
      ['type' => 'next', 'name' => E::ts('Save'), 'isDefault' => TRUE],
      ['type' => 'cancel', 'name' => E::ts('Cancel')],
    ]);
  }

  public function setDefaultValues() {
    $defaults = parent::setDefaultValues();
    $params = $this->getActionParameters();
    foreach (['channel', 'template', 'chat_id', 'parse_mode', 'disable_notification', 'disable_web_page_preview'] as $field) {
      if (array_key_exists($field, $params)) {
        $defaults[$field] = $params[$field];
      }
    }
    foreach ($this->extraDefaults() as $key => $value) {
      if (!array_key_exists($key, $defaults) || $defaults[$key] === NULL) {
        $defaults[$key] = $value;
      }
    }
    return $defaults;
  }

  public function postProcess() {
    $values = $this->exportValues();
    $params = [
      'channel' => $values['channel'] ?? 'telegram',
      'template' => $values['template'] ?? '',
      'chat_id' => $values['chat_id'] ?? '',
      'parse_mode' => $values['parse_mode'] ?? 'HTML',
      'disable_notification' => !empty($values['disable_notification']),
      'disable_web_page_preview' => !empty($values['disable_web_page_preview']),
    ];
    $params = array_merge($params, $this->getExtraValues($values));
    $this->ruleAction->action_params = serialize($params);
    $this->ruleAction->save();

    \CRM_Core_Session::setStatus(E::ts('civico_notify action saved.'), '', 'success');
    parent::postProcess();
  }

  /**
   * Subclass extension point: defaults for fields added in addExtraFields().
   */
  protected function extraDefaults(): array {
    return [];
  }

  protected function civicoCheckbox(string $name, string $label): void {
    $this->add('checkbox', $name, $label);
  }

  /**
   * Read action params from the rule_action row (returns an assoc array).
   */
  protected function getActionParameters(): array {
    $raw = $this->ruleAction->action_params ?? '';
    if (!$raw) {
      return [];
    }
    $params = unserialize($raw);
    return is_array($params) ? $params : [];
  }

}
