<?php

use CRM_Herald_ExtensionUtil as E;

/**
 * Workflow MessageTemplates shipped with herald.
 *
 * Loaded by the mgd-php@1 mixin. Each entry creates a MessageTemplate row on
 * `cv ext:enable herald` and is admin-editable via *Mailings →
 * Message Templates*. The `update => unmodified` flag means re-installs only
 * overwrite templates the admin hasn't customised — user edits are preserved.
 *
 * All bodies use Smarty syntax. Channel implementations decide which body to
 * use (Telegram → msg_html with parse_mode=HTML; Email → both).
 *
 * i18n: source strings are English, wrapped in {crmScope
 * extensionKey='herald'} + {ts} so they render in the instance's locale
 * (lcMessages) using the extension's l10n/<locale>/LC_MESSAGES/herald.mo.
 * German ships in-tree (l10n/de_DE); other languages just add a .po/.mo.
 */

$templates = [
  [
    'workflow_name' => 'herald_contribution_digest_daily',
    'msg_title' => E::ts('Herald: Daily contribution digest'),
    'msg_subject' => '{crmScope extensionKey=\'herald\'}{$period.label} — {$totals.count} {ts}donation(s){/ts}, {$totals.amount|crmMoney}{/crmScope}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}📊 <b>{ts 1=$period.label}Daily report %1{/ts}</b>

{ts}Received{/ts}: <b>{$totals.amount|crmMoney}</b> ({$totals.count})
{foreach from=$by_source item=src}• {$src.source}: {$src.total|crmMoney} ({$src.count})
{/foreach}
{if $pending.count > 0}
🏦 {ts}Open pending pool{/ts}: <b>{$pending.amount|crmMoney}</b> ({$pending.count})
{/if}
<pre>{$histogram}</pre>

{if $campaign.goal}{ts}Campaign{/ts} <b>{$campaign.title}</b>: {$campaign.percent}% {ts}of{/ts} {$campaign.goal|crmMoney}{/if}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts 1=$period.label}Daily report %1{/ts}

{ts}Received{/ts}: {$totals.amount|crmMoney} ({$totals.count})
{foreach from=$by_source item=src}- {$src.source}: {$src.total|crmMoney} ({$src.count})
{/foreach}
{if $pending.count > 0}{ts}Open pending pool{/ts}: {$pending.amount|crmMoney} ({$pending.count}){/if}

{$histogram}

{if $campaign.goal}{$campaign.title}: {$campaign.percent}% {ts}of{/ts} {$campaign.goal|crmMoney}{/if}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_digest_weekly',
    'msg_title' => E::ts('Herald: Weekly contribution digest'),
    'msg_subject' => '{crmScope extensionKey=\'herald\'}📈 {$period.label}: {$totals.count} {ts}donation(s){/ts}, {$totals.amount|crmMoney}{/crmScope}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}📈 <b>{ts 1=$period.label}Weekly report %1{/ts}</b>

{ts}Received{/ts}: <b>{$totals.amount|crmMoney}</b> ({$totals.count})
{foreach from=$by_source item=src}• {$src.source}: {$src.total|crmMoney} ({$src.count})
{/foreach}
{if $pending.count > 0}
🏦 {ts}Open pending pool{/ts}: <b>{$pending.amount|crmMoney}</b> ({$pending.count})
{/if}
<pre>{$histogram}</pre>

{if $campaign.goal}{ts}Campaign{/ts} <b>{$campaign.title}</b>: {$campaign.percent}% {ts}of{/ts} {$campaign.goal|crmMoney}{/if}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts 1=$period.label}Weekly report %1{/ts}

{ts}Received{/ts}: {$totals.amount|crmMoney} ({$totals.count})
{foreach from=$by_source item=src}- {$src.source}: {$src.total|crmMoney} ({$src.count})
{/foreach}

{$histogram}

{if $campaign.goal}{$campaign.title}: {$campaign.percent}% {ts}of{/ts} {$campaign.goal|crmMoney}{/if}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_completed',
    'msg_title' => E::ts('Herald: New completed contribution'),
    'msg_subject' => '✅ {$contribution.total_amount|crmMoney} — {$contribution.source}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}✅ <b>{ts}Donation received{/ts}</b>: {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}
{if $campaign.title}{ts}Campaign{/ts}: {$campaign.title}{if $campaign.goal} ({$campaign.percent}%){/if}{/if}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts}Donation received{/ts}: {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}
{if $campaign.title}{ts}Campaign{/ts}: {$campaign.title}{if $campaign.goal} ({$campaign.percent}%){/if}{/if}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_pending',
    'msg_title' => E::ts('Herald: New pending contribution'),
    'msg_subject' => '{crmScope extensionKey=\'herald\'}🏦 {$contribution.total_amount|crmMoney} {ts}announced{/ts}{/crmScope}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}🏦 <b>{ts}Donation announced{/ts}</b>: {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts}Donation announced{/ts}: {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_status_changed',
    'msg_title' => E::ts('Herald: Contribution status changed'),
    'msg_subject' => '🔄 {$contribution.total_amount|crmMoney}: {$from_status} → {$to_status}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}🔄 <b>{ts}Status changed{/ts}</b>: {$from_status} → {$to_status}
{ts}Amount{/ts}: {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts}Status changed{/ts}: {$from_status} → {$to_status}
{ts}Amount{/ts}: {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket',
    'msg_title' => E::ts('Herald: New ticket/event contribution'),
    'msg_subject' => '🎟 {$contribution.total_amount|crmMoney} — {$contribution.source}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}🎟 <b>{ts}Ticket sold{/ts}</b>: {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts}Ticket sold{/ts}: {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket_pending',
    'msg_title' => E::ts('Herald: Ticket ordered, bank transfer announced'),
    'msg_subject' => '🎟⏳ {$contribution.total_amount|crmMoney} — {$contribution.source}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}🎟⏳ <b>{ts}Ticket ordered{/ts}</b> ({ts}bank transfer{/ts}): {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts}Ticket ordered{/ts} ({ts}bank transfer{/ts}): {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket_paid',
    'msg_title' => E::ts('Herald: Ticket bank transfer received'),
    'msg_subject' => '🎟✅ {$contribution.total_amount|crmMoney} — {$contribution.source}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}🎟✅ <b>{ts}Ticket paid{/ts}</b> ({ts}bank transfer{/ts}): {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts}Ticket paid{/ts} ({ts}bank transfer{/ts}): {$contribution.total_amount|crmMoney}
{ts}Source{/ts}: {$contribution.source}{/crmScope}
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
    'msg_subject' => '{if $status == "fail"}🔴{else}🟢{/if} {$check}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}{if $status == "fail"}🔴 {ts 1=$check}Check <b>%1</b> failed{/ts}{if $detail}: {$detail}{/if}{else}🟢 {ts 1=$check}Check <b>%1</b> ok again{/ts}{/if}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{if $status == "fail"}{ts 1=$check}Check %1 failed{/ts}{if $detail}: {$detail}{/if}{else}{ts 1=$check}Check %1 ok again{/ts}{/if}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_membership_signup_alert',
    'msg_title' => E::ts('Herald: New membership signup'),
    'msg_subject' => '{crmScope extensionKey=\'herald\'}🎉 {ts}New membership{/ts}: {$contact.display_name}{/crmScope}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}🎉 <b>{ts}New membership{/ts}</b>
{$contact.display_name} — {$membership.membership_type}
{ts}Status{/ts}: {$membership.status}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts}New membership{/ts}
{$contact.display_name} — {$membership.membership_type}
{ts}Status{/ts}: {$membership.status}{/crmScope}
TPL,
  ],
  [
    'workflow_name' => 'herald_event_registration_alert',
    'msg_title' => E::ts('Herald: New event registration'),
    'msg_subject' => '🎟️ {$event.title}: {$participant.display_name}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}🎟️ <b>{ts}New event registration{/ts}</b>
{$event.title}
{ts}Participant{/ts}: {$participant.display_name}
{if $participant.role}{ts}Role{/ts}: {$participant.role}{/if}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{ts}New event registration{/ts}
{$event.title}
{ts}Participant{/ts}: {$participant.display_name}
{if $participant.role}{ts}Role{/ts}: {$participant.role}{/if}{/crmScope}
TPL,
  ],
  [
    // Generic, composable digest block. Rendered once per entry in a
    // multi-block digest (Job.herald_contribution_digest `blocks` param); the
    // caller injects {$block_title}. One block per revenue stream (donations,
    // tickets, …) — all filtered/labelled independently, assembled into one message.
    'workflow_name' => 'herald_contribution_digest_block',
    'msg_title' => E::ts('Herald: Contribution digest block (composable)'),
    'msg_subject' => '{$block_title}: {$totals.count} · {$totals.amount|crmMoney}',
    'msg_html' => <<<'TPL'
{crmScope extensionKey='herald'}<b>{$block_title}</b>{if $totals.count > 0}{"\n"}<b>{$totals.amount|crmMoney}</b> · {$totals.count}{if $campaign.goal > 0} · {$campaign.percent}% {ts}of{/ts} {$campaign.goal|crmMoney}{/if}{foreach from=$by_source item=src}{"\n"}• {$src.source}: {$src.total|crmMoney} ({$src.count}){/foreach}{else}{"\n"}<i>{ts}no receipts{/ts}</i>{/if}{if $pending.count > 0}{"\n"}🏦 {ts}open{/ts}: {$pending.amount|crmMoney} ({$pending.count}){/if}{if $histogram}{"\n"}<pre>{$histogram}</pre>{/if}{/crmScope}
TPL,
    'msg_text' => <<<'TPL'
{crmScope extensionKey='herald'}{$block_title}{if $totals.count > 0}{"\n"}{$totals.amount|crmMoney} · {$totals.count}{if $campaign.goal > 0} · {$campaign.percent}% {ts}of{/ts} {$campaign.goal|crmMoney}{/if}{foreach from=$by_source item=src}{"\n"}- {$src.source}: {$src.total|crmMoney} ({$src.count}){/foreach}{else}{"\n"}{ts}no receipts{/ts}{/if}{if $pending.count > 0}{"\n"}{ts}open{/ts}: {$pending.amount|crmMoney} ({$pending.count}){/if}{/crmScope}
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
