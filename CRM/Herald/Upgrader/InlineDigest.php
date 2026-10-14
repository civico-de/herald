<?php

declare(strict_types = 1);

use Civi\Herald\Digest\Composer;
use Civi\Herald\Digest\Definition;
use Civi\Herald\Digest\DigestProblem;
use Civi\Herald\Renderer\TemplateRenderer;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;

/**
 * Digests that older releases kept inside rule action and job parameters
 * become a HeraldDigest with its own message template and sources; the
 * parameters keep only delivery settings and `digest`.
 */
final class CRM_Herald_Upgrader_InlineDigest {

  /**
   * @param array<array-key, mixed> $params
   */
  public static function isInline(array $params): bool {
    return !array_key_exists('digest', $params);
  }

  /**
   * Store the digest of $params as $name. The caller runs it in a transaction:
   * a digest that would not compose throws and leaves nothing behind.
   *
   * @param array<string, mixed> $params
   * @return array<string, mixed> The params with the digest content replaced by `digest`
   * @throws \CRM_Core_Exception with the problems as a DigestProblem
   */
  public static function extract(array $params, string $name, string $title): array {
    $blocks = CRM_Herald_Upgrader_InlineDigestBlocks::parse($params, $name);
    if (is_string($blocks)) {
      throw new DigestProblem([$blocks]);
    }
    $period = CRM_Herald_Upgrader_InlinePeriod::read($params['period'] ?? NULL, $name);
    $label = "Herald: {$title}";
    $sources = [];
    // The header is HTML; a job saved through the form stores it input-encoded.
    $parts = [trim(CRM_Herald_Upgrader_InlineTemplate::literal(TemplateRenderer::decodeInput(Cast::toString(
      $params['header'] ?? '',
    ))))];
    foreach ($blocks as $i => $block) {
      CRM_Herald_Upgrader_InlineBlock::check($block, $name);
      $block['period'] ??= $period;
      [$blockSources, $text] = CRM_Herald_Upgrader_InlineBlock::sources($block, 'block' . ($i + 1), $name, $label);
      $sources = [...$sources, ...$blockSources];
      $blockTitle = Cast::toString($block['title'] ?? '');
      $parts[] =
        ($blockTitle === '' ? '' : '<b>' . CRM_Herald_Upgrader_InlineTemplate::text($blockTitle) . "</b>\n") . $text;
    }
    $templateId = Cast::toInt(
      \Civi\Api4\MessageTemplate::create(checkPermissions: FALSE)
        ->setValues([
          'msg_title' => mb_substr($label, 0, 255),
          'msg_subject' => CRM_Herald_Upgrader_InlineDelivery::subject($params, $blocks, $sources, $title),
          'msg_html' => implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')),
          'is_active' => TRUE,
        ])
        ->execute()
        ->single()['id'],
    );
    $digestId = \Civi\Api4\HeraldDigest::create(checkPermissions: FALSE)
      ->setValues(['name' => $name, 'title' => $title, 'message_template_id' => $templateId])
      ->execute()
      ->single()['id'];
    \Civi\Api4\HeraldDigestSource::save(checkPermissions: FALSE)
      ->setRecords($sources)
      ->setDefaults(['digest_id' => $digestId])
      ->execute();
    $problems = (new Composer())->check(Definition::load($name))['problems'];
    if ($problems !== []) {
      throw new DigestProblem($problems);
    }
    return ['digest' => $name] + CRM_Herald_Upgrader_InlineDelivery::of($params);
  }

  /**
   * @param list<string> $problems
   */
  public static function warn(string $name, array $problems): void {
    \Civi::log('herald')->warning('herald: digest left inline, it cannot run until fixed', [
      'digest' => $name,
      'problems' => $problems,
    ]);
  }

}
