<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Api4\Event\ValidateValuesEvent;
use Civi\Core\HookInterface;
use Civi\Core\Service\AutoService;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Refuses template edits that break a digest using the template, through
 * APIv4 and through the legacy template form.
 *
 * @service herald.digest_template_validator
 */
final class TemplateValidator extends AutoService implements HookInterface {

  private const TEMPLATE_FIELDS = ['msg_subject', 'msg_html', 'msg_text'];

  public function on_civi_api4_validate(ValidateValuesEvent $e): void {
    if ($e->getEntityName() !== 'MessageTemplate') {
      return;
    }
    foreach (Validator::diffs($e) as $r => [$new, $old]) {
      $id = $old['id'] ?? NULL;
      if ($id === NULL || array_intersect_key($new, array_flip(self::TEMPLATE_FIELDS)) === []) {
        continue;
      }
      $problems = self::problems(Cast::toInt($id), $new + $old);
      if ($problems !== []) {
        $e->addError($r, 'msg_html', 'herald_template', implode(' ', $problems));
      }
    }
  }

  /**
   * The legacy template form saves through the BAO, past APIv4 validation.
   *
   * @param array<array-key, mixed> $fields
   * @param array<array-key, mixed> $files
   * @param array<array-key, mixed> $errors
   */
  public function hook_civicrm_validateForm(
    string $formName,
    array &$fields,
    array &$files,
    \CRM_Core_Form $form,
    array &$errors,
  ): void {
    if ($formName !== 'CRM_Admin_Form_MessageTemplates') {
      return;
    }
    // The form keeps the id of the edited template in its scope.
    $id = $form->get('id');
    if (!is_numeric($id)) {
      return;
    }
    $problems = self::problems((int) $id, Cast::namedOnly($fields));
    if ($problems !== []) {
      $errors['msg_html'] = implode(' ', $problems);
    }
  }

  /**
   * The template's new text against every digest using it. A digest whose
   * sources do not load now is left to fail at send: the text stays editable.
   *
   * @param array<string, mixed> $template
   * @return list<string>
   */
  private static function problems(int $id, array $template): array {
    /** @var list<string> $names */
    $names = \Civi\Api4\HeraldDigest::get(checkPermissions: FALSE)
      ->addWhere('message_template_id', '=', $id)
      ->execute()
      ->column('name');
    $composer = new Composer();
    $problems = [];
    foreach ($names as $name) {
      $digest = ['template' => Definition::templateValues($template)] + Definition::load($name);
      try {
        $values = $composer->values($digest);
      }
      catch (\CRM_Core_Exception) {
        continue;
      }
      try {
        $composer->render($digest, $values);
      }
      catch (DigestProblem $problem) {
        $problems[] = E::ts('Digest "%1": %2', [1 => $digest['title'], 2 => $problem->getMessage()]);
      }
    }
    return $problems;
  }

}
