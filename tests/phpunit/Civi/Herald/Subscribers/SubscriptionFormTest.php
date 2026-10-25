<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\ChannelFixture;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * Core's custom data form names each record's fields apart; each row and
 * the group channel are checked on their own and the error lands on its
 * element.
 *
 * @group headless
 */
class SubscriptionFormTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * @var array<string, int> Field name => id
   */
  private array $ids = [];

  /**
   * @var array<string, int> Group channel field name => id
   */
  private array $groupIds = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    ChannelFixture::add('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))], TRUE);
    $this->ids = self::fieldIds(Subscriptions::GROUP);
    $this->groupIds = self::fieldIds(Subscriptions::GROUP_DELIVERY);
  }

  /**
   * @return array<string, int>
   */
  private static function fieldIds(string $group): array {
    return array_map(
      static fn (mixed $id): int => (int) $id,
      \Civi\Api4\CustomField::get(checkPermissions: FALSE)
        ->addSelect('id', 'name')
        ->addWhere('custom_group_id:name', '=', $group)
        ->execute()
        ->column('id', 'name'),
    );
  }

  /**
   * @param array<string, mixed> $values Field name => value
   * @param array<string, int>|null $ids Default: the row fields
   * @return array<string, mixed>
   */
  private function fields(array $values, string $suffix, ?array $ids = NULL): array {
    $fields = [];
    foreach ($values as $name => $value) {
      $fields['custom_' . ($ids ?? $this->ids)[$name] . $suffix] = $value;
    }
    return $fields;
  }

  /**
   * @param array<string, mixed> $fields
   * @return array<array-key, mixed>
   */
  private static function validate(string $formName, array $fields): array {
    $errors = [];
    SubscriptionForm::validate($formName, $fields, $errors);
    return $errors;
  }

  public function testHealthyRowsOnTheTabPass(): void {
    $fields =
      $this->fields(['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news' => 1]], '_4')
      + $this->fields(['channel' => 'email', 'recipient' => '', 'topics' => ['news']], '_-1')
      + ['qfKey' => 'x', 'custom_1' => 'other field'];

    $this->assertSame([], self::validate('CRM_Contact_Form_CustomData', $fields));
  }

  public function testEachTabRowIsCheckedOnItsOwn(): void {
    $fields =
      $this->fields(['channel' => 'ops_bot', 'recipient' => '111', 'topics' => ['news' => 1]], '_4')
      + $this->fields(['channel' => 'ops_bot', 'recipient' => '', 'topics' => ['news' => '']], '_-1');

    $this->assertSame(
      ['custom_' . $this->ids['recipient'] . '_-1', 'custom_' . $this->ids['topics'] . '_-1'],
      array_keys(self::validate('CRM_Contact_Form_CustomData', $fields)),
    );
  }

  public function testANewGroupChannelIsChecked(): void {
    $healthy = $this->fields(['channel' => 'ops_bot', 'recipient' => '111'], '_-1', $this->groupIds);
    $this->assertSame([], self::validate('CRM_Contact_Form_CustomData', $healthy));

    $fields = $this->fields(['channel' => 'ops_bot', 'recipient' => ''], '_-1', $this->groupIds);
    $this->assertSame(
      ['custom_' . $this->groupIds['recipient'] . '_-1'],
      array_keys(self::validate('CRM_Contact_Form_CustomData', $fields)),
    );
  }

  public function testTheGroupChannelIsCheckedOnItsTab(): void {
    $healthy = $this->fields(['channel' => 'ops_bot', 'recipient' => '111'], '_7', $this->groupIds);
    $this->assertSame([], self::validate('CRM_Contact_Form_CustomData', $healthy));

    $fields = $this->fields(['channel' => 'ops_bot', 'recipient' => ''], '_7', $this->groupIds);
    $this->assertSame(
      ['custom_' . $this->groupIds['recipient'] . '_7'],
      array_keys(self::validate('CRM_Contact_Form_CustomData', $fields)),
    );
  }

  public function testOtherFormsAreLeftAlone(): void {
    $fields = $this->fields(['channel' => 'ops_bot', 'recipient' => '', 'topics' => []], '_-1');

    $this->assertSame([], self::validate('CRM_Contact_Form_Contact', $fields));
  }

}
