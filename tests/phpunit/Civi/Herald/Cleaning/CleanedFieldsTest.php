<?php

declare(strict_types = 1);

namespace Civi\Herald\Cleaning;

use Civi\Api4\Contribution;
use Civi\Api4\Herald;
use Civi\Herald\Admin\AdminTestCase;
use Civi\Herald\Trigger\ContributionVars;

/**
 * Herald.saveCleanedFields and the herald_clean_* fields it adds.
 *
 * @group headless
 */
class CleanedFieldsTest extends AdminTestCase {

  private const SOURCE = [
    'entity' => 'Contribution',
    'field' => 'source',
    'rules' => [
      ['match' => 'Bank TX', 'type' => 'contains', 'label' => 'Transfer'],
      ['match' => 'Online', 'type' => 'prefix', 'label' => 'Web'],
      ['match' => '^SEPA\s', 'type' => 'regex', 'label' => 'Direct debit'],
      ['match' => '100%', 'type' => 'contains', 'label' => 'Full'],
    ],
    'fallback' => '',
  ];

  protected function tearDown(): void {
    \Civi::settings()->set(CleanedFields::SETTING, []);
    \Civi::cache('metadata')->clear();
    parent::tearDown();
  }

  public function testEntriesAreStoredInTheSettingShape(): void {
    $this->assertSame([self::SOURCE], self::call('saveCleanedFields', ['fields' => [self::SOURCE]]));
    $this->assertSame([self::SOURCE], \Civi::settings()->get(CleanedFields::SETTING));

    self::call('saveCleanedFields', ['fields' => []]);
    $this->assertSame([], \Civi::settings()->get(CleanedFields::SETTING));
  }

  public function testInvalidEntriesAreRejected(): void {
    \Civi::settings()->set(CleanedFields::SETTING, [self::SOURCE]);
    $this->assertRejected(
      'saveCleanedFields',
      [
        'fields' => [
          ['entity' => 'NoSuchThing', 'field' => 'source', 'rules' => []],
          ['entity' => 'Contribution', 'field' => 'total_amount', 'rules' => []],
          self::SOURCE,
          [
            'entity' => 'Contribution',
            'field' => 'source',
            'rules' => [['match' => '(unclosed', 'type' => 'regex', 'label' => 'x'], 'not a rule'],
          ],
        ],
      ],
      [
        'Field 1: choose a record type.',
        'Field 2: choose a text field of Contribution.',
        'Field 4: Contribution.source is already cleaned in field 3.',
        'Field 4: Rule 1: the regular expression is invalid: ',
        'Field 4: Rule 2: enter the text to match.',
        'Field 4: Rule 2: choose how to match',
        'Field 4: Rule 2: enter the name to show.',
      ],
    );
    $this->assertSame([self::SOURCE], \Civi::settings()->get(CleanedFields::SETTING));
  }

  public function testSearchesGroupByTheCleanedField(): void {
    self::call('saveCleanedFields', ['fields' => [self::SOURCE]]);
    foreach ([
      'Bank TX 4711',
      'bank tx 4712',
      'Online form',
      'SEPA 2026-10',
      '100% match',
      '100 percent',
      'Shop',
      '',
    ] as $source) {
      $this->contribution($source);
    }

    $groups = Contribution::get(FALSE)
      ->addSelect('herald_clean_source', 'COUNT(id) AS n')
      ->addGroupBy('herald_clean_source')
      ->execute()
      ->column('n', 'herald_clean_source');
    ksort($groups);

    $this->assertSame(
      ['' => 1, '100 percent' => 1, 'Direct debit' => 1, 'Full' => 1, 'Shop' => 1, 'Transfer' => 2, 'Web' => 1],
      $groups,
    );
  }

  public function testTheFallbackNamesEveryOtherValueButNotAnEmptyOne(): void {
    self::call('saveCleanedFields', ['fields' => [['fallback' => 'Other'] + self::SOURCE]]);
    $shop = $this->contribution('Shop');
    $empty = $this->contribution('');

    $this->assertSame(['source' => 'Other'], CleanedValues::of('Contribution', $shop));
    // Core stores an empty source as NULL.
    $this->assertSame(['source' => NULL], CleanedValues::of('Contribution', $empty));
  }

  public function testNotificationsShowTheCleanedValue(): void {
    self::call('saveCleanedFields', ['fields' => [self::SOURCE]]);
    $id = $this->contribution('Bank TX 4711');

    $vars = ContributionVars::build(['id' => $id, 'source' => 'Bank TX 4711', 'contribution_status_id' => 1], NULL);

    $this->assertSame('Transfer', $vars['contribution']['source']);
  }

  public function testABrokenStoredRuleIsLeftOutAndTheOthersApply(): void {
    $broken = self::SOURCE;
    array_unshift($broken['rules'], ['match' => '(unclosed', 'type' => 'regex', 'label' => 'x']);
    \Civi::settings()->set(CleanedFields::SETTING, [$broken]);
    \Civi::cache('metadata')->clear();

    $this->assertSame(
      ['source' => 'Transfer'],
      CleanedValues::of('Contribution', $this->contribution('Bank TX 1')),
    );
  }

  public function testTheCleanedFieldNeedsThePermissionOfTheRawField(): void {
    self::call('saveCleanedFields', [
      'fields' => [['entity' => 'Contact', 'field' => 'api_key', 'rules' => [], 'fallback' => '']],
    ]);
    $contact = (int) civicrm_api4('Contact', 'create', [
      'checkPermissions' => FALSE,
      'values' => ['contact_type' => 'Individual', 'first_name' => 'Demo', 'api_key' => bin2hex(random_bytes(8))],
    ])->first()['id'];
    $permissions = \CRM_Core_Config::singleton()->userPermissionClass;
    $saved = $permissions->permissions;
    $permissions->permissions = ['access CiviCRM', 'view all contacts'];
    try {
      $row = civicrm_api4('Contact', 'get', [
        'select' => ['id', 'herald_clean_api_key'],
        'where' => [['id', '=', $contact]],
      ])->first();
    }
    finally {
      $permissions->permissions = $saved;
    }
    $this->assertSame($contact, $row['id'] ?? NULL);
    $this->assertArrayNotHasKey('herald_clean_api_key', $row);
  }

  public function testADeletedRecordKeepsTheTriggerValues(): void {
    self::call('saveCleanedFields', ['fields' => [self::SOURCE]]);
    $id = $this->contribution('Bank TX 4711');
    Contribution::delete(FALSE)->addWhere('id', '=', $id)->execute();

    $this->assertSame([], CleanedValues::of('Contribution', $id));
  }

  public function testOnlyAdministratorsMayWrite(): void {
    $this->assertAdminOnly(static fn () => Herald::saveCleanedFields()->execute());
  }

  private ?int $contact = NULL;

  private function contribution(string $source): int {
    $this->contact ??= (int) civicrm_api4('Contact', 'create', [
      'checkPermissions' => FALSE,
      'values' => ['contact_type' => 'Individual', 'first_name' => 'Demo'],
    ])->first()['id'];
    return (int) Contribution::create(FALSE)
      ->setValues([
        'contact_id' => $this->contact,
        'financial_type_id' => 1,
        'total_amount' => 10,
        'source' => $source,
        'receive_date' => 'now',
      ])
      ->execute()
      ->first()['id'];
  }

}
