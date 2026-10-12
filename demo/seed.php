<?php

declare(strict_types = 1);

/**
 * Idempotent synthetic Herald demo data.
 *
 * Run inside a provisioned demo with:
 *   cv scr /var/www/html/ext/herald/demo/seed.php
 *
 * The demo deliberately routes topics to internal CiviCRM activities. This
 * makes every notification observable without configuring an outbound mailer
 * or an external webhook and exercises the same Herald.publish contract that
 * production integrations use.
 */

use Civi\Api4\Contact;

$externalIdentifier = 'civico-showroom-coordinator';
/** @var array{id: int}|null $coordinator */
$coordinator = Contact::get(checkPermissions: FALSE)
  ->addSelect('id')
  ->addWhere('external_identifier', '=', $externalIdentifier)
  ->execute()
  ->first();

if ($coordinator === NULL) {
  /** @var array{id: int} $coordinator */
  $coordinator = Contact::create(checkPermissions: FALSE)
    ->addValue('contact_type', 'Individual')
    ->addValue('first_name', 'Demo')
    ->addValue('last_name', 'Koordination')
    ->addValue('external_identifier', $externalIdentifier)
    ->execute()
    ->single();
}
$coordinatorId = $coordinator['id'];

$activityRoute = [
  'channel' => 'activity',
  'activity_assignee_contact_id' => $coordinatorId,
  'activity_source_contact_id' => $coordinatorId,
  'activity_type' => 'herald_notification',
  'activity_status' => 'Scheduled',
  'activity_format' => 'html',
];

$configured = \Civi::settings()->get('herald_topics');
$topics = is_array($configured) ? $configured : [];
foreach (['default', 'members', 'finance', 'ops'] as $topic) {
  if (isset($topics[$topic]) && is_array($topics[$topic])) {
    continue;
  }
  $topics[$topic] = $activityRoute;
}
\Civi::settings()->set('herald_topics', $topics);
\Civi::settings()->set('herald_monitor_topic', 'ops');

fwrite(STDOUT, sprintf(
  "herald demo ready: coordinator=%d topics=%s\n",
  $coordinatorId,
  implode(',', ['default', 'members', 'finance', 'ops']),
));
