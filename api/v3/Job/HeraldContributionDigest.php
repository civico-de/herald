<?php

declare(strict_types = 1);

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Digest\DigestVars;
use Civi\Herald\Digest\ExtraSections;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\Renderer\TemplateRenderer;

/**
 * Job.herald_contribution_digest
 *
 * Runs a contribution digest from a CiviCRM scheduled job (or manually),
 * WITHOUT a trigger entity. The digest needs none for its work, but CiviCRM
 * scheduled jobs require an entry point — this API3 job action is that entry
 * point. It shares Civi\Herald\Digest\DigestVars
 * with the CiviRules path, so digest content stays identical on both.
 *
 * @param array $spec
 */
function _civicrm_api3_job_herald_contribution_digest_spec(&$spec) {
  $spec['blocks'] = [
    'title' => 'Composable multi-block digest: JSON array of block configs (each: title, template, campaign_id, financial_type_id, goal, campaign_title, show_pending, show_histogram, period). When given, one message is assembled from all blocks and single-block params below are ignored.',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['header'] = [
    'title' => 'Optional header line prepended to a multi-block digest (e.g. "📊 <b>Tagesreport</b>")',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['sections'] = [
    'title' => 'Extra sections contributed by other extensions via hook_civicrm_heraldDigestSections, comma-separated keys (e.g. "myext.seats"). Appended after the blocks, in the order listed. Multi-block mode only.',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['template'] = ['title' => 'Workflow template name (single-block mode)', 'type' => CRM_Utils_Type::T_STRING];
  $spec['channel'] = [
    'title' => 'Channel key (default telegram)',
    'api.default' => 'telegram',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['period'] = [
    'title' => 'Period: yesterday|today|last_7_days|last_30_days',
    'api.default' => 'yesterday',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['chat_id'] = ['title' => 'Telegram chat id (overrides default_chat_id)', 'type' => CRM_Utils_Type::T_STRING];
  $spec['campaign_id'] = ['title' => 'Campaign id (narrows all queries)', 'type' => CRM_Utils_Type::T_INT];
  $spec['financial_type_id'] = ['title' => 'Financial type id', 'type' => CRM_Utils_Type::T_INT];
  $spec['source_pattern'] = ['title' => 'LIKE pattern on source', 'type' => CRM_Utils_Type::T_STRING];
  $spec['group_by'] = [
    'title' => 'Set to "line_item" to group by line-item label + unit price instead of contribution source (also available per block)',
    'type' => CRM_Utils_Type::T_STRING,
  ];
  $spec['goal'] = ['title' => 'Campaign goal (renders progress)', 'type' => CRM_Utils_Type::T_FLOAT];
  $spec['campaign_title'] = ['title' => 'Campaign title', 'type' => CRM_Utils_Type::T_STRING];
  $spec['show_pending'] = [
    'title' => 'Include pending-pool block',
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'api.default' => 1,
  ];
  $spec['show_histogram'] = ['title' => 'Include histogram', 'type' => CRM_Utils_Type::T_BOOLEAN, 'api.default' => 1];
  $spec['bucket_count'] = ['title' => 'Target histogram buckets', 'type' => CRM_Utils_Type::T_INT];
  $spec['parse_mode'] = ['title' => 'Telegram parse mode (e.g. HTML)', 'type' => CRM_Utils_Type::T_STRING];
  $spec['send_hour'] = [
    'title' => 'Only send during this hour of day (0–23, CiviCRM timezone). Set the job to Hourly frequency; runs outside the hour are skipped. Without this, a Daily job fires at the first cron run after midnight.',
    'type' => CRM_Utils_Type::T_INT,
  ];
  $spec['force'] = [
    'title' => 'Ignore send_hour and send immediately (for manual/ad-hoc runs)',
    'type' => CRM_Utils_Type::T_BOOLEAN,
  ];
}

/**
 * @param array $params
 * @return array
 * @throws CRM_Core_Exception
 * @mago-expect lint:halstead
 * @mago-expect lint:cyclomatic-complexity
 */
function civicrm_api3_job_herald_contribution_digest($params) {
  // Uhrzeit-Gate: CiviCRM-Jobs kennen keine Sendezeit — "Daily" feuert beim
  // ersten Cron-Lauf nach Mitternacht. Mit send_hour läuft der Job Hourly
  // und sendet nur in der gewünschten Stunde (Civi-Zeitzone).
  if (empty($params['force']) && isset($params['send_hour']) && $params['send_hour'] !== '') {
    $tz = new DateTimeZone(
      CRM_Core_Config::singleton()->userSystem->getTimeZoneString() ?: date_default_timezone_get(),
    );
    $nowHour = (int) (new DateTime('now', $tz))->format('G');
    if ($nowHour !== (int) $params['send_hour']) {
      return civicrm_api3_create_success(
        [
          [
            'sent' => 0,
            'skipped' => 'outside send_hour',
            'send_hour' => (int) $params['send_hour'],
            'current_hour' => $nowHour,
          ],
        ],
        $params,
        'Job',
        'herald_contribution_digest',
      );
    }
  }
  $stats = new ContributionStatsService();
  $renderer = new TemplateRenderer();
  $channelKey = (string) ($params['channel'] ?? 'telegram');

  // Channel config: strip composition/query keys, keep only channel-relevant ones.
  $configParams = $params;
  unset(
    $configParams['blocks'],
    $configParams['header'],
    $configParams['sections'],
    $configParams['template'],
    $configParams['period'],
    $configParams['send_hour'],
  );
  $config = Dispatcher::buildChannelConfig($configParams);
  $channel = ChannelRegistry::default()->get($channelKey);

  $blocks = $params['blocks'] ?? NULL;
  if (is_string($blocks) && $blocks !== '') {
    $blocks = json_decode($blocks, TRUE);
  }

  // ---- Multi-block composed digest: one message, N independently-filtered blocks ----
  if (is_array($blocks) && $blocks) {
    $sections = [];
    $header = trim((string) ($params['header'] ?? ''));
    if ($header !== '') {
      $sections[] = $header;
    }
    $totalCount = 0;
    $totalAmount = 0.0;
    foreach ($blocks as $block) {
      if (!is_array($block)) {
        continue;
      }
      // Per-block config inherits the top-level period unless overridden.
      $block += ['period' => $params['period'] ?? 'yesterday'];
      $template = (string) ($block['template'] ?? 'herald_contribution_digest_block');
      $vars = DigestVars::build($block, $stats);
      $vars['block_title'] = $block['title'] ?? '';
      $body = trim($renderer->renderParts($template, $vars)['body']);
      if ($body !== '') {
        $sections[] = $body;
      }
      $totalCount += (int) ($vars['totals']['count'] ?? 0);
      $totalAmount += (float) ($vars['totals']['amount'] ?? 0);
    }
    // Sections from other extensions — numbers no contribution query can answer.
    $extraKeys = ExtraSections::parseKeys($params['sections'] ?? '');
    $extra = ExtraSections::collect($extraKeys, [
      'params' => $params,
      'period' => $params['period'] ?? 'yesterday',
    ]);
    $sections = array_merge($sections, $extra);
    $channel->send(implode("\n\n", $sections), $config);
    return civicrm_api3_create_success(
      [
        [
          'sent' => 1,
          'channel' => $channelKey,
          'blocks' => count($blocks),
          'sections' => count($extra),
          'count' => $totalCount,
          'amount' => $totalAmount,
        ],
      ],
      $params,
      'Job',
      'herald_contribution_digest',
    );
  }

  // ---- Single-block digest (backward compatible) ----
  $template = (string) ($params['template'] ?? '');
  if ($template === '') {
    throw new CRM_Core_Exception('herald: parameter "template" (workflow_name) or "blocks" is required.');
  }
  $vars = DigestVars::build($params, $stats);
  $channel->send($renderer->renderParts($template, $vars)['body'], $config);

  return civicrm_api3_create_success(
    [
      [
        'sent' => 1,
        'channel' => $channelKey,
        'template' => $template,
        'count' => $vars['totals']['count'] ?? 0,
        'amount' => $vars['totals']['amount'] ?? 0,
      ],
    ],
    $params,
    'Job',
    'herald_contribution_digest',
  );
}
