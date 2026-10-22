<?php

declare(strict_types = 1);

use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * The names of a form's visible elements in the order they were added, for
 * the templates that lay out every labelled field; hidden ones go to $form.hidden.
 */
final class CRM_Herald_CivirulesAdapter_FormElements {

  public static function assign(CRM_Core_Form $form): void {
    $names = [];
    foreach ($form->_elements as $element) {
      if (!$element instanceof HTML_QuickForm_element || $element->getType() === 'hidden') {
        continue;
      }
      $names[] = Cast::toString($element->getName());
    }
    $form->assign('elementNames', $names);
  }

}
