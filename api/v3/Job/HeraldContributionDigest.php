<?php

use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Digest\ContributionStatsService;
use Civi\Herald\Renderer\TemplateRenderer;

/**
 * Job.herald_contribution_digest
 *
 * Runs a contribution digest from a CiviCRM scheduled job (or manually),
 * WITHOUT a CiviRules trigger entity. The SendContributionDigest CiviRules
 * action needs no trigger entity for its work, but CiviRules cron triggers and
 * CiviCRM scheduled jobs both require an entry point — this API3 job action is
 * that entry point. It mirrors CRM_Herald_CivirulesActions_SendContributionDigest
 * (reuses its static buildVars) so digest content stays identical between the
 * CiviRules path and the scheduled path.
 *
 * @param array $spec
 */
function _civicrm_api3_job_herald_contribution_digest_spec(&$spec) {
  $spec['template'] = ['title' => 'Workflow template name (required)', 'api.required' => 1, 'type' => CRM_Utils_Type::T_STRING];
  $spec['channel'] = ['title' => 'Channel key (default telegram)', 'api.default' => 'telegram', 'type' => CRM_Utils_Type::T_STRING];
  $spec['period'] = ['title' => 'Period: yesterday|today|last_7_days|last_30_days', 'api.default' => 'yesterday', 'type' => CRM_Utils_Type::T_STRING];
  $spec['chat_id'] = ['title' => 'Telegram chat id (overrides default_chat_id)', 'type' => CRM_Utils_Type::T_STRING];
  $spec['campaign_id'] = ['title' => 'Campaign id (narrows all queries)', 'type' => CRM_Utils_Type::T_INT];
  $spec['financial_type_id'] = ['title' => 'Financial type id', 'type' => CRM_Utils_Type::T_INT];
  $spec['source_pattern'] = ['title' => 'LIKE pattern on source', 'type' => CRM_Utils_Type::T_STRING];
  $spec['goal'] = ['title' => 'Campaign goal (renders progress)', 'type' => CRM_Utils_Type::T_FLOAT];
  $spec['campaign_title'] = ['title' => 'Campaign title', 'type' => CRM_Utils_Type::T_STRING];
  $spec['show_pending'] = ['title' => 'Include pending-pool block', 'type' => CRM_Utils_Type::T_BOOLEAN, 'api.default' => 1];
  $spec['show_histogram'] = ['title' => 'Include histogram', 'type' => CRM_Utils_Type::T_BOOLEAN, 'api.default' => 1];
  $spec['bucket_count'] = ['title' => 'Target histogram buckets', 'type' => CRM_Utils_Type::T_INT];
  $spec['parse_mode'] = ['title' => 'Telegram parse mode (e.g. HTML)', 'type' => CRM_Utils_Type::T_STRING];
}

/**
 * @param array $params
 * @return array
 * @throws CRM_Core_Exception
 */
function civicrm_api3_job_herald_contribution_digest($params) {
  $template = (string) ($params['template'] ?? '');
  if ($template === '') {
    throw new CRM_Core_Exception('herald: parameter "template" (workflow_name) is required.');
  }

  $stats = new ContributionStatsService();
  $vars = CRM_Herald_CivirulesActions_SendContributionDigest::buildVars($params, $stats);

  $parts = (new TemplateRenderer())->renderParts($template, $vars);
  $config = CRM_Herald_CivirulesActions_AbstractSend::buildChannelConfig($params);

  $channelKey = (string) ($params['channel'] ?? 'telegram');
  ChannelRegistry::default()->get($channelKey)->send($parts['body'], $config);

  return civicrm_api3_create_success([
    [
      'sent' => 1,
      'channel' => $channelKey,
      'template' => $template,
      'count' => $vars['totals']['count'] ?? 0,
      'amount' => $vars['totals']['amount'] ?? 0,
    ],
  ], $params, 'Job', 'herald_contribution_digest');
}
