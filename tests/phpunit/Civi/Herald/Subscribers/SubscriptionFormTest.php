<?php

declare(strict_types = 1);

namespace Civi\Herald\Subscribers;

use Civi\Herald\ChannelFixture;
use Civi\Test\HeadlessInterface;
use Civi\Test\TransactionalInterface;
use PHPUnit\Framework\TestCase;

/**
 * The tab and the profile submit a row's fields under different element
 * names; each row is checked on its own and the error lands on its element.
 *
 * @group headless
 */
class SubscriptionFormTest extends TestCase implements HeadlessInterface, TransactionalInterface {

  /**
   * @var array<string, int> Field name => id
   */
  private array $ids = [];

  public function setUpHeadless() {
    return \Civi\Herald\HeadlessSite::standard()->apply();
  }

  protected function setUp(): void {
    parent::setUp();
    ChannelFixture::add('ops_bot', 'telegram', ['bot_token' => bin2hex(random_bytes(8))], TRUE);
    $this->ids = array_map(
      static fn (mixed $id): int => (int) $id,
      \Civi\Api4\CustomField::get(checkPermissions: FALSE)
        ->addSelect('id', 'name')
        ->addWhere('custom_group_id:name', '=', Subscriptions::GROUP)
        ->execute()
        ->column('id', 'name'),
    );
  }

  /**
   * @param array<string, mixed> $values Field name => value
   * @return array<string, mixed>
   */
  private function fields(array $values, string $suffix): array {
    $fields = [];
    foreach ($values as $name => $value) {
      $fields['custom_' . $this->ids[$name] . $suffix] = $value;
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
      $this->fields([
        'channel' => 'ops_bot',
        'recipient' => '111',
        'topics' => ['news' => 1],
        'for_groups' => '0',
      ], '_4')
      + $this->fields(['channel' => 'email', 'recipient' => '', 'topics' => [], 'for_groups' => '1'], '_-1')
      + ['qfKey' => 'x', 'custom_1' => 'other field'];

    $this->assertSame([], self::validate('CRM_Contact_Form_CustomData', $fields));
  }

  public function testEachTabRowIsCheckedOnItsOwn(): void {
    $fields =
      $this->fields([
        'channel' => 'ops_bot',
        'recipient' => '111',
        'topics' => ['news' => 1],
        'for_groups' => '0',
      ], '_4')
      + $this->fields([
        'channel' => 'ops_bot',
        'recipient' => '',
        'topics' => ['news' => ''],
        'for_groups' => '0',
      ], '_-1');

    $this->assertSame(
      ['custom_' . $this->ids['recipient'] . '_-1', 'custom_' . $this->ids['topics'] . '_-1'],
      array_keys(self::validate('CRM_Contact_Form_CustomData', $fields)),
    );
  }

  public function testTheProfileNamesFieldsWithoutARow(): void {
    $fields = $this->fields([
      'channel' => 'ops_bot',
      'recipient' => ' ',
      'topics' => ['news' => 1],
      'for_groups' => '0',
    ], '');

    $this->assertSame(
      ['custom_' . $this->ids['recipient']],
      array_keys(self::validate('CRM_Profile_Form_Edit', $fields)),
    );
  }

  public function testOtherFormsAreLeftAlone(): void {
    $fields = $this->fields(['channel' => 'ops_bot', 'recipient' => '', 'topics' => [], 'for_groups' => '0'], '');

    $this->assertSame([], self::validate('CRM_Contact_Form_Contact', $fields));
  }

}
