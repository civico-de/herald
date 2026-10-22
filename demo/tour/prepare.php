<?php

declare(strict_types = 1);

/**
 * Puts a dev site into the state the tour records: synthetic subscribers of
 * the topic news, a rule on contribution changes and a parked alert. What the
 * tour creates on screen is removed first, so every run starts the same.
 * Needs demo/seed.php and demo/digests.php first; run by demo/tour/run.sh.
 */

use Civi\Api4\CiviRulesRule;
use Civi\Api4\CiviRulesRuleAction;
use Civi\Api4\CiviRulesRuleCondition;
use Civi\Api4\Contact;
use Civi\Api4\Email;
use Civi\Api4\Herald;
use Civi\Api4\HeraldBufferedAlert;
use Civi\Api4\HeraldDigest;
use Civi\Herald\Buffer\AlertBuffer;
use Civi\Herald\Buffer\DeadLetter;
use Civi\Herald\Channel\ChannelStore;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

$single = static fn (string $entity, string $name): int => Cast::toInt(civicrm_api4($entity, 'get', [
  'checkPermissions' => FALSE,
  'where' => [['name', '=', $name]],
])->single()['id']);

// Created on screen by the tour.
$topics = \Civi::settings()->get('herald_topics');
$topics = is_array($topics) ? $topics : [];
if (isset($topics['donations'])) {
  Herald::deleteTopic(FALSE)->setTopic('donations')->execute();
}
if (isset((new ChannelStore())->all()['team_mail'])) {
  Herald::deleteChannel(FALSE)->setName('team_mail')->execute();
}

Herald::saveChannel(FALSE)
  ->setName('email')
  ->setIsNew(!isset((new ChannelStore())->all()['email']))
  ->setChannel([
    'type' => 'email',
    'label' => 'Email',
    'active' => TRUE,
    'subscribable' => TRUE,
    'config' => ['email_from' => '"Herald demo" <herald@example.org>', 'email_format' => 'html'],
  ])
  ->execute();

Herald::saveTopic(FALSE)
  ->setTopic('news')
  ->setPreviousName(isset($topics['news']) ? 'news' : '')
  ->setLabel('Newsletter team')
  ->setSubscribable(TRUE)
  ->setRoute(['delivery_mode' => 'subscribers'])
  ->execute();

$subscribers = [];
foreach (['Ada' => 'Lovelace', 'Grace' => 'Hopper', 'Alan' => 'Turing'] as $first => $last) {
  $key = 'herald-tour-' . strtolower($last);
  $existing = Contact::get(FALSE)->addSelect('id')->addWhere('external_identifier', '=', $key)->execute()->first();
  $id =
    $existing['id']
    ?? Contact::create(FALSE)
      ->setValues([
        'contact_type' => 'Individual',
        'first_name' => $first,
        'last_name' => $last,
        'external_identifier' => $key,
      ])
      ->execute()
      ->single()['id'];
  if (Email::get(FALSE)->addWhere('contact_id', '=', $id)->execute()->count() === 0) {
    Email::create(FALSE)
      ->setValues(['contact_id' => $id, 'email' => strtolower($first) . '@example.org', 'is_primary' => TRUE])
      ->execute();
  }
  Contact::update(FALSE)
    ->addWhere('id', '=', $id)
    ->addValue('herald_preferences.preferred_channel', 'email')
    ->addValue('herald_preferences.subscriptions', ['news'])
    ->execute();
  $subscribers[] = $id;
}

CiviRulesRule::delete(FALSE)->addWhere('name', '=', 'herald_tour_donation')->execute();
$rule = CiviRulesRule::create(FALSE)
  ->setValues([
    'name' => 'herald_tour_donation',
    'label' => 'Herald demo: donation completed',
    'trigger_id' => $single('CiviRulesTrigger', 'changed_contribution'),
    'is_active' => TRUE,
  ])
  ->execute()
  ->single();
CiviRulesRuleCondition::create(FALSE)
  ->setValues([
    'rule_id' => $rule['id'],
    'condition_id' => $single('CiviRulesCondition', 'herald_field_transition'),
    'condition_params' => [
      'entity' => 'Contribution',
      'field' => 'contribution_status_id:name',
      'from_operator' => '=',
      'from_value' => 'Pending',
      'to_operator' => '=',
      'to_value' => 'Completed',
    ],
    'is_active' => TRUE,
  ])
  ->execute();
CiviRulesRuleAction::create(FALSE)
  ->setValues([
    'rule_id' => $rule['id'],
    'action_id' => $single('CiviRulesAction', 'herald_send'),
    'action_params' => [
      'template' => 'herald_contribution_alert_completed',
      'delivery_mode' => 'subscribers',
      'subscribers_of' => 'news',
      'aggregate_minutes' => 15,
    ],
    'is_active' => TRUE,
  ])
  ->execute();
$ruleId = $rule['id'];

HeraldBufferedAlert::delete(FALSE)->addWhere('group_hash', '=', 'herald-tour')->execute();
(new AlertBuffer())->add('herald-tour', 15, '<p>Donation of €50 completed</p>', [
  'channel' => 'ntfy',
  'ntfy_topic' => 'donations',
]);
$parked = HeraldBufferedAlert::get(FALSE)
  ->addSelect('id')
  ->addWhere('group_hash', '=', 'herald-tour')
  ->execute()
  ->column('id');
(new DeadLetter())->park(
  array_map(Cast::toInt(...), array_values($parked)),
  'max_attempts',
  'ntfy: HTTP 503 Service Unavailable',
);

$daily = CiviRulesRule::get(FALSE)
  ->addSelect('id')
  ->addWhere('name', '=', 'herald_demo_daily_report')
  ->execute()
  ->single()['id'];
$state = [
  'rule' => $ruleId,
  'ruleAction' => CiviRulesRuleAction::get(FALSE)->addWhere('rule_id', '=', $ruleId)->execute()->single()['id'],
  'ruleCondition' => CiviRulesRuleCondition::get(FALSE)->addWhere('rule_id', '=', $ruleId)->execute()->single()['id'],
  'digestRule' => $daily,
  'digestRuleAction' => CiviRulesRuleAction::get(FALSE)->addWhere('rule_id', '=', $daily)->execute()->single()['id'],
  'digest' => HeraldDigest::get(FALSE)->addWhere('name', '=', 'daily_report')->execute()->single()['id'],
  'subscriber' => $subscribers[0],
  'profile' => $single('UFGroup', CRM_Herald_Upgrader_PreferencesProfile::NAME),
];
fwrite(STDOUT, json_encode($state, JSON_THROW_ON_ERROR) . "\n");
