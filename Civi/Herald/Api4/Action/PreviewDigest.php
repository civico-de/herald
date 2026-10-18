<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\HtmlLineBreaks;
use Civi\Herald\Digest\Composer;
use Civi\Herald\Digest\Definition;

/**
 * Composes a digest from the values the digest form holds, with today's data,
 * and sends nothing. The body comes as email shows it; `problems` says why
 * there is none.
 *
 * Example:
 *   cv api4 Herald.previewDigest 'digest={"name":"daily","title":"Daily","msg_html":"{$donations.sum}"}' \
 *       'sources=[{"name":"donations","type":"search","search_display_id":12}]'
 */
class PreviewDigest extends AbstractAction {

  /**
   * HeraldDigest values: name, title, msg_subject, msg_html.
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $digest = [];

  /**
   * HeraldDigestSource values, in the order of the form.
   * @var array
   * @phpstan-var list<array<string, mixed>>
   */
  protected array $sources = [];

  public function _run(Result $result): void {
    $preview = (new Composer())->check(Definition::of($this->digest, $this->checkedSources()));
    $body = $preview['body'];
    $result[] = ['body' => $body === NULL ? NULL : HtmlLineBreaks::apply($body)] + $preview;
  }

  /**
   * @return list<array<string, mixed>>
   */
  protected function checkedSources(): array {
    $sources = array_values($this->sources);
    if (array_filter($sources, static fn (mixed $source): bool => !is_array($source)) !== []) {
      throw new \CRM_Core_Exception(sprintf(
        'Herald.%s: each source is an object of HeraldDigestSource values.',
        $this->getActionName(),
      ));
    }
    return $sources;
  }

}
