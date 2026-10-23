<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Api4\CustomField;
use Civi\Api4\OptionValue;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The scope type on a subscription offers the entities the send action can scope to.
 *
 * @group headless
 */
class ScopeEntityFieldTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  public function setUpHeadless(): \Civi\Test\CiviEnvBuilder {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  public function testTheScopeTypeIsAListOfTheActionsEntities(): void {
    $field = CustomField::get(checkPermissions: FALSE)
      ->addSelect('html_type', 'option_group_id:name')
      ->addWhere('custom_group_id:name', '=', 'herald_subscription')
      ->addWhere('name', '=', 'source_entity_type')
      ->execute()
      ->single();
    $this->assertSame('Select', $field['html_type']);
    $this->assertSame(SubscriptionScope::OPTION_GROUP, $field['option_group_id:name']);

    $options = OptionValue::get(checkPermissions: FALSE)
      ->addWhere('option_group_id:name', '=', SubscriptionScope::OPTION_GROUP)
      ->addOrderBy('weight')
      ->execute()
      ->column('label', 'value');
    $this->assertSame(SubscriptionScope::entities(), $options);
  }

}
