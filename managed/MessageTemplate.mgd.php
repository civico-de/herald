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
 */

$templates = [
  [
    'workflow_name' => 'herald_contribution_digest_daily',
    'msg_title' => E::ts('Herald: Daily contribution digest'),
    'msg_subject' => '{$period.label} — {$totals.count} Spende(n), {$totals.amount|crmMoney}',
    'msg_html' => <<<'TPL'
📊 <b>Tagesreport {$period.label}</b>

Eingegangen: <b>{$totals.amount|crmMoney}</b> ({$totals.count})
{foreach from=$by_source item=src}• {$src.source}: {$src.total|crmMoney} ({$src.count})
{/foreach}
{if $pending.count > 0}
🏦 Offene Pending-Pool: <b>{$pending.amount|crmMoney}</b> ({$pending.count})
{/if}
<pre>{$histogram}</pre>

{if $campaign.goal}Campaign <b>{$campaign.title}</b>: {$campaign.percent}% von {$campaign.goal|crmMoney}{/if}
TPL,
    'msg_text' => <<<'TPL'
Tagesreport {$period.label}

Eingegangen: {$totals.amount|crmMoney} ({$totals.count})
{foreach from=$by_source item=src}- {$src.source}: {$src.total|crmMoney} ({$src.count})
{/foreach}
{if $pending.count > 0}Pending-Pool: {$pending.amount|crmMoney} ({$pending.count}){/if}

{$histogram}

{if $campaign.goal}{$campaign.title}: {$campaign.percent}% von {$campaign.goal|crmMoney}{/if}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_digest_weekly',
    'msg_title' => E::ts('Herald: Weekly contribution digest'),
    'msg_subject' => '📈 {$period.label}: {$totals.count} Spende(n), {$totals.amount|crmMoney}',
    'msg_html' => <<<'TPL'
📈 <b>Wochenreport {$period.label}</b>

Eingegangen: <b>{$totals.amount|crmMoney}</b> ({$totals.count})
{foreach from=$by_source item=src}• {$src.source}: {$src.total|crmMoney} ({$src.count})
{/foreach}
{if $pending.count > 0}
🏦 Offene Pending-Pool: <b>{$pending.amount|crmMoney}</b> ({$pending.count})
{/if}
<pre>{$histogram}</pre>

{if $campaign.goal}Campaign <b>{$campaign.title}</b>: {$campaign.percent}% von {$campaign.goal|crmMoney}{/if}
TPL,
    'msg_text' => <<<'TPL'
Wochenreport {$period.label}

Eingegangen: {$totals.amount|crmMoney} ({$totals.count})
{foreach from=$by_source item=src}- {$src.source}: {$src.total|crmMoney} ({$src.count})
{/foreach}

{$histogram}

{if $campaign.goal}{$campaign.title}: {$campaign.percent}% von {$campaign.goal|crmMoney}{/if}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_completed',
    'msg_title' => E::ts('Herald: New completed contribution'),
    'msg_subject' => '✅ {$contribution.total_amount|crmMoney} — {$contribution.source}',
    'msg_html' => <<<'TPL'
✅ <b>Spende eingegangen</b>: {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
{if $campaign.title}Campaign: {$campaign.title}{if $campaign.goal} ({$campaign.percent}%){/if}{/if}
TPL,
    'msg_text' => <<<'TPL'
Spende eingegangen: {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
{if $campaign.title}Campaign: {$campaign.title}{if $campaign.goal} ({$campaign.percent}%){/if}{/if}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_pending',
    'msg_title' => E::ts('Herald: New pending contribution'),
    'msg_subject' => '🏦 {$contribution.total_amount|crmMoney} angekündigt',
    'msg_html' => <<<'TPL'
🏦 <b>Spende angekündigt</b>: {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
    'msg_text' => <<<'TPL'
Spende angekündigt: {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_status_changed',
    'msg_title' => E::ts('Herald: Contribution status changed'),
    'msg_subject' => '🔄 {$contribution.total_amount|crmMoney}: {$from_status} → {$to_status}',
    'msg_html' => <<<'TPL'
🔄 <b>Status geändert</b>: {$from_status} → {$to_status}
Betrag: {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
    'msg_text' => <<<'TPL'
Status geändert: {$from_status} → {$to_status}
Betrag: {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket',
    'msg_title' => E::ts('Herald: New ticket/event contribution'),
    'msg_subject' => '🎟 {$contribution.total_amount|crmMoney} — {$contribution.source}',
    'msg_html' => <<<'TPL'
🎟 <b>Ticket verkauft</b>: {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
    'msg_text' => <<<'TPL'
Ticket verkauft: {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket_pending',
    'msg_title' => E::ts('Herald: Ticket ordered, bank transfer announced'),
    'msg_subject' => '🎟⏳ {$contribution.total_amount|crmMoney} — {$contribution.source}',
    'msg_html' => <<<'TPL'
🎟⏳ <b>Ticket bestellt</b> (Überweisung): {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
    'msg_text' => <<<'TPL'
Ticket bestellt (Überweisung): {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
  ],
  [
    'workflow_name' => 'herald_contribution_alert_ticket_paid',
    'msg_title' => E::ts('Herald: Ticket bank transfer received'),
    'msg_subject' => '🎟✅ {$contribution.total_amount|crmMoney} — {$contribution.source}',
    'msg_html' => <<<'TPL'
🎟✅ <b>Ticket bezahlt</b> (Überweisung): {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
    'msg_text' => <<<'TPL'
Ticket bezahlt (Überweisung): {$contribution.total_amount|crmMoney}
Quelle: {$contribution.source}
TPL,
  ],
  [
    'workflow_name' => 'herald_group_subscription_alert',
    'msg_title' => E::ts('Herald: New group/newsletter subscription'),
    // Privacy: never leak full names into the chat — first 2 chars + ****.
    'msg_subject' => '📰 {$group.title}: {$contact.display_name|truncate:6:"****":true}',
    'msg_html' => <<<'TPL'
📰 <b>Neue Anmeldung</b>: {$group.title}
{$contact.display_name|truncate:6:"****":true}
TPL,
    'msg_text' => <<<'TPL'
Neue Anmeldung: {$group.title}
{$contact.display_name|truncate:6:"****":true}
TPL,
  ],
  [
    'workflow_name' => 'herald_membership_signup_alert',
    'msg_title' => E::ts('Herald: New membership signup'),
    'msg_subject' => '🎉 Neue Mitgliedschaft: {$contact.display_name}',
    'msg_html' => <<<'TPL'
🎉 <b>Neue Mitgliedschaft</b>
{$contact.display_name} — {$membership.membership_type}
Status: {$membership.status}
TPL,
    'msg_text' => <<<'TPL'
Neue Mitgliedschaft
{$contact.display_name} — {$membership.membership_type}
Status: {$membership.status}
TPL,
  ],
  [
    'workflow_name' => 'herald_event_registration_alert',
    'msg_title' => E::ts('Herald: New event registration'),
    'msg_subject' => '🎟️ {$event.title}: {$participant.display_name}',
    'msg_html' => <<<'TPL'
🎟️ <b>Neue Event-Registrierung</b>
{$event.title}
Teilnehmer: {$participant.display_name}
{if $participant.role}Rolle: {$participant.role}{/if}
TPL,
    'msg_text' => <<<'TPL'
Neue Event-Registrierung
{$event.title}
Teilnehmer: {$participant.display_name}
{if $participant.role}Rolle: {$participant.role}{/if}
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
<b>{$block_title}</b>{if $totals.count > 0}{"\n"}<b>{$totals.amount|crmMoney}</b> · {$totals.count}{if $campaign.goal > 0} · {$campaign.percent}% von {$campaign.goal|crmMoney}{/if}{foreach from=$by_source item=src}{"\n"}• {$src.source}: {$src.total|crmMoney} ({$src.count}){/foreach}{else}{"\n"}<i>keine Eingänge</i>{/if}{if $pending.count > 0}{"\n"}🏦 offen: {$pending.amount|crmMoney} ({$pending.count}){/if}{if $histogram}{"\n"}<pre>{$histogram}</pre>{/if}
TPL,
    'msg_text' => <<<'TPL'
{$block_title}{if $totals.count > 0}{"\n"}{$totals.amount|crmMoney} · {$totals.count}{if $campaign.goal > 0} · {$campaign.percent}% von {$campaign.goal|crmMoney}{/if}{foreach from=$by_source item=src}{"\n"}- {$src.source}: {$src.total|crmMoney} ({$src.count}){/foreach}{else}{"\n"}keine Eingänge{/if}{if $pending.count > 0}{"\n"}offen: {$pending.amount|crmMoney} ({$pending.count}){/if}
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
