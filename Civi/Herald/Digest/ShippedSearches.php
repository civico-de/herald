<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * The ready-made digest sources herald ships (managed/DigestSearches.mgd.php):
 * what each display counts and for which period, so the form can offer them by
 * name instead of as a SearchKit display.
 */
final class ShippedSearches {

  /**
   * Period => APIv4 relative date filter. The headless install signature hashes
   * only the .mgd.php file, so after a change here run `ck test-reset`.
   */
  public const PERIODS = [
    'Today' => 'this.day',
    'Yesterday' => 'previous.day',
    'This_Week' => 'current.week',
    'Last_Week' => 'previous.week',
    'Last_7_Days' => 'ending.week',
    'This_Month' => 'current.month',
    'Last_Month' => 'previous.month',
    'This_Year' => 'current.year',
  ];

  /**
   * Figure => display name, with %s for the period; a name without %s has none.
   */
  public const FIGURES = [
    'contributions' => 'Herald_Digest_Contributions_%s_Table',
    'contributions_by_form' => 'Herald_Digest_Contributions_%s_By_Form_Table',
    'contributions_by_financial_type' => 'Herald_Digest_Contributions_%s_By_Financial_Type_Table',
    'line_items' => 'Herald_Digest_Line_Items_%s_Table',
    'contribution_amounts' => 'Herald_Digest_Contribution_Amounts_%s_Table',
    'contributions_pending' => 'Herald_Digest_Contributions_Pending_Table',
    'activities' => 'Herald_Digest_Activities_%s_Table',
    'activities_overdue' => 'Herald_Digest_Activities_Overdue_Table',
  ];

  /**
   * @return array<string, string>
   */
  public static function periodLabels(): array {
    return [
      'Today' => E::ts('today'),
      'Yesterday' => E::ts('yesterday'),
      'This_Week' => E::ts('this week'),
      'Last_Week' => E::ts('last week'),
      'Last_7_Days' => E::ts('last 7 days'),
      'This_Month' => E::ts('this month'),
      'Last_Month' => E::ts('last month'),
      'This_Year' => E::ts('this year'),
    ];
  }

  /**
   * @return array<string, string>
   */
  public static function figureLabels(): array {
    return [
      'contributions' => E::ts('Contributions: sum and count'),
      'contributions_by_form' => E::ts('Contributions by form'),
      'contributions_by_financial_type' => E::ts('Contributions by financial type'),
      'line_items' => E::ts('Line items sold'),
      'contribution_amounts' => E::ts('Single contribution amounts'),
      'contributions_pending' => E::ts('Pending contributions, whatever their age'),
      'activities' => E::ts('Activities by type and status'),
      'activities_overdue' => E::ts('Overdue activities by type'),
    ];
  }

  /**
   * The shipped displays this site has, e.g. none for contributions without CiviContribute.
   *
   * @return list<array{figure: string, period: ?string, id: int}>
   */
  public static function available(): array {
    $wanted = [];
    foreach (self::FIGURES as $figure => $name) {
      foreach (str_contains($name, '%s') ? array_keys(self::PERIODS) : [NULL] as $period) {
        $wanted[sprintf($name, $period ?? '')] = ['figure' => $figure, 'period' => $period];
      }
    }
    $ids = \Civi\Api4\SearchDisplay::get(checkPermissions: FALSE)
      ->addSelect('id', 'name')
      ->addWhere('name', 'IN', array_keys($wanted))
      ->execute()
      ->column('id', 'name');
    $available = [];
    foreach ($wanted as $name => $entry) {
      if (!isset($ids[$name])) {
        continue;
      }
      $available[] = $entry + ['id' => Cast::toInt($ids[$name])];
    }
    return $available;
  }

}
