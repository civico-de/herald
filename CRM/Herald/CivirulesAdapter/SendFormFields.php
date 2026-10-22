<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;
use CRM_Herald_ExtensionUtil as E;

/**
 * The channel-type delivery fields of a send action form: the elements, their
 * saved values as defaults, and the form rule over the submitted values.
 */
final class CRM_Herald_CivirulesAdapter_SendFormFields {

  /**
   * The delivery keys of the channel types; blank ones leave the channel's
   * own value. The page script shows those of the chosen channel's type.
   */
  public static function addTo(\CRM_Core_Form $form): void {
    $fields = CRM_Herald_CivirulesAdapter_SendParams::channelFields();
    $typeOf = [];
    foreach ($fields as $key => $field) {
      // The template sets each field under a heading of its first channel type.
      $typeOf[$key] = $field['types'][0];
      $others = array_map(ChannelRegistry::label(...), array_slice($field['types'], 1));
      $label = $field['label'] . ($others === [] ? '' : ' (' . implode(', ', $others) . ')');
      $options = $field['options'] ?? [];
      // A yes/no field offers the channel's default too, so "no" can override a channel's "yes".
      $options = $field['type'] === 'bool' ? ['1' => E::ts('Yes'), '0' => E::ts('No')] : $options;
      match (TRUE) {
        $options !== [] => $form->add('select', $key, $label, ['' => E::ts('- channel default -')]
        + (array_is_list($options) ? array_combine($options, $options) : $options)),
        default => $form->add('text', $key, $label, ['size' => 40]),
      };
    }
    $form->assign('heraldFieldTypes', $typeOf);
    $form->assign('heraldTypeLabels', array_map(ChannelRegistry::label(...), array_combine($typeOf, $typeOf)));
    \Civi::resources()->addVars('heraldSendForm', [
      'fields' => array_map(
        static fn (array $field): array => ['types' => $field['types'], 'recipient' => $field['recipient']],
        $fields,
      ),
      'channelTypes' => CRM_Herald_CivirulesAdapter_SendOptions::channelTypes(),
    ]);
  }

  /**
   * Saved yes/no values as the '1' / '0' of their select.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed>
   */
  public static function defaults(array $params): array {
    foreach (CRM_Herald_CivirulesAdapter_SendParams::channelFields() as $key => $field) {
      if ($field['type'] !== 'bool' || !is_bool($params[$key] ?? NULL)) {
        continue;
      }
      $params[$key] = $params[$key] ? '1' : '0';
    }
    return $params;
  }

  /**
   * @param array<string, mixed> $values
   * @param callable(array<string, mixed>): array<string, mixed> $extraValues
   * @return array<string, string>|true
   */
  public static function errors(array $values, callable $extraValues): array|bool {
    try {
      $extraValues($values);
      $errors = CRM_Herald_CivirulesAdapter_SendParams::errors($values);
    }
    catch (\CRM_Core_Exception $e) {
      $errors = [$e->getMessage()];
    }
    return $errors === [] ? TRUE : ['_qf_default' => implode(' ', $errors)];
  }

}
