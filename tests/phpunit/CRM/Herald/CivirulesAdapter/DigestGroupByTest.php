<?php

declare(strict_types = 1);

use Civi\Test\HeadlessInterface;
use PHPUnit\Framework\TestCase;

/**
 * The digest action form replaces the stored parameter bag on save, and
 * exportValues() only returns registered fields, so group_by must be one.
 *
 * @group headless
 */
class CRM_Herald_CivirulesAdapter_DigestGroupByTest extends TestCase implements HeadlessInterface {

  public function setUpHeadless() {
    return \Civi\Test::headless()->install('org.civicoop.civirules')->installMe(__DIR__)->apply();
  }

  /**
   * Runs the submitted values through the same steps as postProcess().
   *
   * @param array<string, mixed> $submitted
   * @return array<string, mixed>
   */
  private function savedValues(array $submitted): array {
    $form = new CRM_Herald_CivirulesAdapter_Form_SendContributionDigest();
    (new \ReflectionMethod($form, 'addExtraFields'))->invoke($form);
    $form->_submitValues = $submitted;
    $values = (new \ReflectionMethod($form, 'getExtraValues'))->invoke($form, $form->exportValues());
    $this->assertIsArray($values);
    return $values;
  }

  public function testLineItemGroupingSurvivesSave(): void {
    $this->assertSame('line_item', $this->savedValues(['group_by' => 'line_item'])['group_by']);
  }

  public function testUnknownGroupingFallsBackToSource(): void {
    $this->assertSame('', $this->savedValues(['group_by' => 'bogus'])['group_by']);
    $this->assertSame('', $this->savedValues([])['group_by']);
  }

}
