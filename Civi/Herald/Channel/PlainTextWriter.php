<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Collects the plain text of an HTML body token by token for PlainText.
 */
final class PlainTextWriter {

  private string $out = '';

  /**
   * @var non-negative-int
   */
  private int $breaks = 0;

  public function result(): string {
    return $this->out;
  }

  public function tag(string $tag): void {
    $name = HtmlTokens::name($tag);
    if ($name === 'br') {
      $this->breaks++;
      return;
    }
    if (HtmlTokens::isBlock($tag)) {
      $this->layout(1);
    }
    if ($name === 'li' && !HtmlTokens::isClosing($tag)) {
      $this->append('- ');
    }
  }

  public function text(string $html, ?string $prev, ?string $next): void {
    [$lead, $core, $trail] = HtmlTokens::edges($html);
    $afterBlock = HtmlTokens::isBlock($prev);
    $beforeBlock = HtmlTokens::isBlock($next);
    if ($core === '' && ($afterBlock || $beforeBlock)) {
      $this->layout(substr_count($html, "\n"));
      return;
    }
    if ($afterBlock) {
      $this->layout(substr_count($lead, "\n"));
      $lead = '';
    }
    $this->append($lead . $core . ($beforeBlock ? '' : $trail));
    if ($beforeBlock) {
      $this->layout(substr_count($trail, "\n"));
    }
  }

  /**
   * Line breaks implied by layout: at most one blank line.
   *
   * @param non-negative-int $newlines
   */
  private function layout(int $newlines): void {
    $this->breaks = max($this->breaks, min(2, $newlines));
  }

  /**
   * Pending breaks go in front of the next text; none lead or trail the result.
   */
  public function append(string $html): void {
    if ($html === '') {
      return;
    }
    $prefix = $this->out === '' ? '' : str_repeat("\n", $this->breaks);
    $this->out .= $prefix . html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $this->breaks = 0;
  }

}
