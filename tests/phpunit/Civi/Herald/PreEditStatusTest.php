<?php

declare(strict_types = 1);

namespace Civi\Herald;

use Civi\Api4\Contact;
use Civi\Api4\Contribution;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * hook_civicrm_pre remembers a contribution's stored status before an edit.
 *
 * @group headless
 */
class PreEditStatusTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  /**
   * APIv4 leaves test contributions out of a get unless it fetches by id.
   */
  public function testATestContributionKeepsItsPreviousStatus(): void {
    $contactId = (int) Contact::create(checkPermissions: FALSE)
      ->addValue('first_name', 'Pre')
      ->addValue('last_name', 'Edit')
      ->execute()
      ->first()['id'];
    $contribution = Contribution::create(checkPermissions: FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id:name', 'Donation')
      ->addValue('total_amount', 10)
      ->addValue('contribution_status_id:name', 'Pending')
      ->addValue('is_test', value: TRUE)
      ->execute()
      ->first();
    $params = [];

    herald_civicrm_pre('edit', 'Contribution', $contribution['id'], $params);

    $this->assertSame(
      $contribution['contribution_status_id'],
      StatusTransitionDispatcher::getPreEditStatus((int) $contribution['id']),
    );
  }

}
