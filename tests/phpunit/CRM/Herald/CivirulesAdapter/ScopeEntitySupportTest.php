<?php

declare(strict_types = 1);

use PHPUnit\Framework\TestCase;

/**
 * scope_entity is only meaningful where the executing action resolves it:
 * Herald.notify translates it into scope_entity_type/id from its trigger
 * context. The contribution alert/digest actions have no such context, so
 * their forms must not offer (or save) the field — a saved-but-dead scope
 * would silently deliver to everyone.
 *
 * @group unit
 */
class CRM_Herald_CivirulesAdapter_ScopeEntitySupportTest extends TestCase {

  private function supportsScope(string $formClass): bool {
    $method = new \ReflectionMethod($formClass, 'supportsScopeEntity');
    $form = (new \ReflectionClass($formClass))->newInstanceWithoutConstructor();
    return (bool) $method->invoke($form);
  }

  public function testOnlyTheNotificationFormOffersScopeEntity(): void {
    $this->assertTrue($this->supportsScope(CRM_Herald_CivirulesAdapter_Form_SendNotification::class));
    $this->assertFalse($this->supportsScope(CRM_Herald_CivirulesAdapter_Form_SendContributionDigest::class));
  }

}
