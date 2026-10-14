<?php

declare(strict_types = 1);

namespace Civi\Herald\Digest;

use Civi\Herald\Renderer\TemplateRenderer;
use CRM_Herald_ExtensionUtil as E;

/**
 * Fills a digest's message template with the values of its sources
 * ({$name.value}, {$name.rows}): search displays and extension sections.
 *
 * @phpstan-import-type Digest from Definition
 */
final class Composer {

  /**
   * @var \Closure(int): array<string, mixed>
   */
  private \Closure $searchValues;

  /**
   * @param (callable(int): array<string, mixed>)|null $searchValues SearchDisplay id → source values
   */
  public function __construct(
    private readonly TemplateRenderer $renderer = new TemplateRenderer(),
    ?callable $searchValues = NULL,
  ) {
    $this->searchValues = $searchValues === NULL ? SearchSource::values(...) : $searchValues(...);
  }

  /**
   * @param Digest $digest
   * @return array{body: string, subject: string, sources: int}
   * @throws DigestProblem
   * @throws \CRM_Core_Exception when a search cannot run or an extension's section fails
   */
  public function compose(array $digest): array {
    return $this->render($digest, $this->values($digest));
  }

  /**
   * The message, or why it cannot be composed; for the digest form and its preview.
   *
   * @param Digest $digest
   * @return array{subject: ?string, body: ?string, problems: list<string>}
   */
  public function check(array $digest): array {
    try {
      $message = $this->compose($digest);
      return ['subject' => $message['subject'], 'body' => $message['body'], 'problems' => []];
    }
    catch (DigestProblem $e) {
      $problems = $e->problems();
    }
    catch (\CRM_Core_Exception $e) {
      $problems = [E::ts('The digest cannot be composed: %1', [1 => $e->getMessage()])];
    }
    return ['subject' => NULL, 'body' => NULL, 'problems' => $problems];
  }

  /**
   * The values of each source by its name.
   *
   * @param Digest $digest
   * @return array<string, array<string, mixed>>
   * @throws DigestProblem
   * @throws \CRM_Core_Exception when a search cannot run or an extension's section fails
   */
  public function values(array $digest): array {
    $problems = DigestRules::sendProblems($digest);
    if ($problems !== []) {
      throw new DigestProblem($problems);
    }
    $values = [];
    foreach ($digest['sources'] as $source) {
      $values[$source['name']] = $source['type'] === 'section'
        ? ExtraSections::answer((string) $source['section_key'], ['digest' => $digest['name']])
        : ($this->searchValues)((int) $source['search_display_id']);
    }
    return $values;
  }

  /**
   * Subject and body; without a subject in the template the title is the subject.
   *
   * @param Digest $digest
   * @param array<string, array<string, mixed>> $values
   * @return array{body: string, subject: string, sources: int}
   * @throws DigestProblem
   */
  public function render(array $digest, array $values): array {
    $template = $digest['template'] ?? throw new DigestProblem([DigestRules::noTemplate($digest)]);
    HistogramModifier::register();
    $used =
      $template['msg_subject']
      . "\n"
      . (trim($template['msg_html']) !== '' ? $template['msg_html'] : $template['msg_text']);
    $parts = TemplateCheck::render($used, fn (): array => $this->renderer->renderMessage($template, $values));
    $subject = trim((string) $parts['subject']);
    return [
      'body' => trim($parts['body']),
      'subject' => $subject !== '' ? $subject : $digest['title'],
      'sources' => count($digest['sources']),
    ];
  }

}
