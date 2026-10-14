<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Workflow MessageTemplates shipped with herald.
 *
 * Loaded by the mgd-php@1 mixin. Each entry creates a MessageTemplate row on
 * `cv ext:enable herald` and is admin-editable via *Mailings →
 * Message Templates*. MessageTemplate is not an APIv4 ManagedEntity, so core
 * treats `update => unmodified` as `always`: whenever a declaration here
 * changes, the next reconcile overwrites that template, admin edits included.
 *
 * All bodies use Smarty syntax. The body is msg_html (msg_text only when
 * msg_html is NULL); string vars are HTML-escaped for it.
 *
 * i18n: source strings are English, wrapped in {crmScope
 * extensionKey='herald'} + {ts} so they render in the instance's locale
 * (lcMessages) using the extension's l10n/<locale>/LC_MESSAGES/herald.mo.
 * German ships in-tree (l10n/de_DE); other languages just add a .po/.mo.
 */

$templates = [
  [
    'workflow_name' => 'herald_contribution_alert_completed',
    'msg_title' => E::ts('Herald: New completed contribution'),
    'msg_subject' => '✅ {$contribution.total_amount|crmMoney}{if !empty($contribution.source)} — {$contribution.source}{/if}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}✅ <b>{ts}Donation received{/ts}</b>: {$contribution.total_amount|crmMoney}{if !empty($contribution.source)}{"\n"}{ts}Source{/ts}: {$contribution.source}{/if}
      {if $campaign.title}{ts}Campaign{/ts}: {$campaign.title}{if $campaign.goal} ({$campaign.percent}%){/if}{/if}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Donation received{/ts}: {$contribution.total_amount|crmMoney}{if !empty($contribution.source)}{"\n"}{ts}Source{/ts}: {$contribution.source}{/if}
      {if $campaign.title}{ts}Campaign{/ts}: {$campaign.title}{if $campaign.goal} ({$campaign.percent}%){/if}{/if}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_pending',
    'msg_title' => E::ts('Herald: New pending contribution'),
    'msg_subject' => '{crmScope extensionKey=\'herald\'}🏦 {$contribution.total_amount|crmMoney} {ts}announced{/ts}{/crmScope}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🏦 <b>{ts}Donation announced{/ts}</b>: {$contribution.total_amount|crmMoney}{if !empty($contribution.source)}{"\n"}{ts}Source{/ts}: {$contribution.source}{/if}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Donation announced{/ts}: {$contribution.total_amount|crmMoney}{if !empty($contribution.source)}{"\n"}{ts}Source{/ts}: {$contribution.source}{/if}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_status_changed',
    'msg_title' => E::ts('Herald: Contribution status changed'),
    'msg_subject' => '🔄 {$contribution.total_amount|crmMoney}: {$from_status} → {$to_status}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🔄 <b>{ts}Status changed{/ts}</b>: {$from_status} → {$to_status}
      {ts}Amount{/ts}: {$contribution.total_amount|crmMoney}{if !empty($contribution.source)}{"\n"}{ts}Source{/ts}: {$contribution.source}{/if}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Status changed{/ts}: {$from_status} → {$to_status}
      {ts}Amount{/ts}: {$contribution.total_amount|crmMoney}{if !empty($contribution.source)}{"\n"}{ts}Source{/ts}: {$contribution.source}{/if}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket',
    'msg_title' => E::ts('Herald: New ticket/event contribution'),
    'msg_subject' => '🎟 {$contribution.total_amount|crmMoney}{if !empty($contribution.source)} — {$contribution.source}{/if}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🎟 <b>{ts}Ticket sold{/ts}</b>: {$contribution.total_amount|crmMoney}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Ticket sold{/ts}: {$contribution.total_amount|crmMoney}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket_pending',
    'msg_title' => E::ts('Herald: Ticket ordered, bank transfer announced'),
    'msg_subject' => '🎟⏳ {$contribution.total_amount|crmMoney}{if !empty($contribution.source)} — {$contribution.source}{/if}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🎟⏳ <b>{ts}Ticket ordered{/ts}</b> ({ts}bank transfer{/ts}): {$contribution.total_amount|crmMoney}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Ticket ordered{/ts} ({ts}bank transfer{/ts}): {$contribution.total_amount|crmMoney}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket_paid',
    'msg_title' => E::ts('Herald: Ticket bank transfer received'),
    'msg_subject' => '🎟✅ {$contribution.total_amount|crmMoney}{if !empty($contribution.source)} — {$contribution.source}{/if}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🎟✅ <b>{ts}Ticket paid{/ts}</b> ({ts}bank transfer{/ts}): {$contribution.total_amount|crmMoney}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Ticket paid{/ts} ({ts}bank transfer{/ts}): {$contribution.total_amount|crmMoney}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_group_subscription_alert',
    'msg_title' => E::ts('Herald: New group/newsletter subscription'),
    // Privacy: masked_name / masked_email show 2 chars per word, then one
    // '*' per hidden character (see Civi\Herald\Privacy\Mask).
    'msg_subject' => '{crmScope extensionKey=\'herald\'}📰 {$group.title}: {$contact.masked_name}{/crmScope}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}📰 <b>{ts}New subscription{/ts}</b>: {$group.title}
      {$contact.masked_name} — {$contact.masked_email}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}New subscription{/ts}: {$group.title}
      {$contact.masked_name} — {$contact.masked_email}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_monitor_alert',
    'msg_title' => E::ts('Herald: Monitor check transition'),
    'msg_subject' => '{if $status == "fail"}{if $renotify}⏰{else}🔴{/if}{else}🟢{/if} {$check}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}{if $status == "fail"}{if $renotify}⏰ {ts 1=$check}Check <b>%1</b> still failing{/ts}{else}🔴 {ts 1=$check}Check <b>%1</b> failed{/ts}{/if}{if $detail}: {$detail}{/if}{else}🟢 {ts 1=$check}Check <b>%1</b> ok again{/ts}{/if}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{if $status == "fail"}{if $renotify}{ts 1=$check}Check %1 still failing{/ts}{else}{ts 1=$check}Check %1 failed{/ts}{/if}{if $detail}: {$detail}{/if}{else}{ts 1=$check}Check %1 ok again{/ts}{/if}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_membership_signup_alert',
    'msg_title' => E::ts('Herald: New membership signup'),
    // Privacy: masked_name only — see herald_group_subscription_alert.
    'msg_subject' => '{crmScope extensionKey=\'herald\'}🎉 {ts}New membership{/ts}{if !empty($contact.masked_name)}: {$contact.masked_name}{/if}{/crmScope}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🎉 <b>{ts}New membership{/ts}</b>{if !empty($contact.masked_name)}{"\n"}{$contact.masked_name}{/if}{if !empty($membership.membership_type)}{if !empty($contact.masked_name)} — {else}{"\n"}{/if}{$membership.membership_type}{/if}{if !empty($membership.status)}{"\n"}{ts}Status{/ts}: {$membership.status}{/if}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}New membership{/ts}{if !empty($contact.masked_name)}{"\n"}{$contact.masked_name}{/if}{if !empty($membership.membership_type)}{if !empty($contact.masked_name)} — {else}{"\n"}{/if}{$membership.membership_type}{/if}{if !empty($membership.status)}{"\n"}{ts}Status{/ts}: {$membership.status}{/if}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_event_registration_alert',
    'msg_title' => E::ts('Herald: New event registration'),
    // Privacy: masked_name only — see herald_group_subscription_alert.
    'msg_subject' => '🎟️ {$event.title}: {$contact.masked_name}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🎟️ <b>{ts}New event registration{/ts}</b>
      {$event.title}
      {ts}Participant{/ts}: {$contact.masked_name}
      {if !empty($participant.role)}{ts}Role{/ts}: {$participant.role}{/if}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}New event registration{/ts}
      {$event.title}
      {ts}Participant{/ts}: {$contact.masked_name}
      {if !empty($participant.role)}{ts}Role{/ts}: {$participant.role}{/if}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_event_cancellation_alert',
    'msg_title' => E::ts('Herald: Event registration cancelled'),
    // Privacy: masked_name only — see herald_group_subscription_alert.
    'msg_subject' => '❌ {$event.title}: {$contact.masked_name}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}❌ <b>{ts}Event registration cancelled{/ts}</b>
      {$event.title}
      {ts}Participant{/ts}: {$contact.masked_name}
      {if !empty($participant.role)}{ts}Role{/ts}: {$participant.role}{/if}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Event registration cancelled{/ts}
      {$event.title}
      {ts}Participant{/ts}: {$contact.masked_name}
      {if !empty($participant.role)}{ts}Role{/ts}: {$participant.role}{/if}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_event_created_alert',
    'msg_title' => E::ts('Herald: New event created'),
    'msg_subject' => '{crmScope extensionKey=\'herald\'}🆕 {ts}New event{/ts}: {$event.title}{/crmScope}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🆕 <b>{ts}New event{/ts}</b>
      {$event.title}{if !empty($event.start_date)} — {$event.start_date|crmDate}{/if}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}New event{/ts}: {$event.title}{if !empty($event.start_date)} — {$event.start_date|crmDate}{/if}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_event_deactivated_alert',
    'msg_title' => E::ts('Herald: Event deactivated'),
    'msg_subject' => '{crmScope extensionKey=\'herald\'}🚫 {ts}Event deactivated{/ts}: {$event.title}{/crmScope}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🚫 <b>{ts}Event deactivated{/ts}</b>
      {$event.title}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Event deactivated{/ts}: {$event.title}{/crmScope}
      TPL,
  ],
  [
    'workflow_name' => 'herald_event_deleted_alert',
    'msg_title' => E::ts('Herald: Event deleted'),
    'msg_subject' => '{crmScope extensionKey=\'herald\'}🗑 {ts}Event deleted{/ts}: {$event.title}{/crmScope}',
    'msg_html' => <<<'TPL'
      {crmScope extensionKey='herald'}🗑 <b>{ts}Event deleted{/ts}</b>
      {$event.title}{/crmScope}
      TPL,
    'msg_text' => <<<'TPL'
      {crmScope extensionKey='herald'}{ts}Event deleted{/ts}: {$event.title}{/crmScope}
      TPL,
  ],
];

$managed = [];
foreach ($templates as $tpl) {
  $managed[] = [
    'name' => $tpl['workflow_name'],
    'entity' => 'MessageTemplate',
    'cleanup' => 'unused',
    'update' => 'unmodified',
    'params' => [
      'version' => 4,
      'values' => [
        'msg_title' => $tpl['msg_title'],
        'msg_subject' => $tpl['msg_subject'],
        'msg_html' => $tpl['msg_html'],
        'msg_text' => $tpl['msg_text'],
        'workflow_name' => $tpl['workflow_name'],
        'is_default' => TRUE,
        'is_reserved' => FALSE,
        'is_active' => TRUE,
      ],
    ],
  ];
}

return $managed;
