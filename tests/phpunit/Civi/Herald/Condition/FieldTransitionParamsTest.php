<?php

declare(strict_types = 1);

namespace Civi\Herald\Condition;

use PHPUnit\Framework\TestCase;

/**
 * @group unit
 */
class FieldTransitionParamsTest extends TestCase {

  /**
   * The snapshot keys a field the way the condition later reads it.
   */
  public function testTheStoredFieldIsReadTrimmed(): void {
    $params = FieldTransitionParams::decode(serialize([
      'entity' => ' Contribution ',
      'field' => ' contribution_status_id ',
      'to_operator' => '=',
      'to_value' => 1,
    ]));
    self::assertSame(
      [
        'entity' => 'Contribution',
        'field' => 'contribution_status_id',
        'from_operator' => '',
        'from_value' => '',
        'to_operator' => '=',
        'to_value' => '1',
      ],
      $params,
    );
  }

  public function testMissingOrNonTextParamsAreEmpty(): void {
    self::assertSame('', FieldTransitionParams::decode(NULL)['entity']);
    self::assertSame('', FieldTransitionParams::decode(['field' => ['status']])['field']);
  }

}
