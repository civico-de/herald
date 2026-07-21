<?php

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\ChannelRegistry;

/**
 * Deliver an already-rendered message through one of herald's channels.
 *
 * This is the supported entry point for OTHER extensions. It is string-
 * addressed on purpose: a caller reaches herald through
 * civicrm_api4('Herald', 'send', ...) and never needs to name a herald class,
 * so neither side has to defeat static analysis to keep the dependency
 * optional. `cv api4 Herald.getActions` then makes the parameter contract
 * machine-readable (getFields describes entity FIELDS, of which this
 * service-style entity has none).
 *
 * Rendering is the caller's job — herald does not own the caller's templates.
 * Use Herald.renderTemplate first if the message comes from a MessageTemplate.
 *
 * The `subject` and `format` params are channel-agnostic: each channel
 * declares (getSemanticFields()) which of its own config keys they correspond
 * to, and a channel without an equivalent silently drops them. Everything in
 * `config` is passed through verbatim; keys the channel does not declare in
 * getConfigSchema() are reported back as `unknown_config_keys` rather than
 * dropped, so a typo is visible without changing delivery behaviour.
 *
 * Example:
 *   cv api4 Herald.send +v 'channel=ntfy' +v 'message="disk almost full"' \
 *       +v 'subject="[ops] storage"' +v 'config={"ntfy_priority":4}'
 */
class Send extends AbstractAction {

  /**
   * Channel key as registered in ChannelRegistry (see Herald.getChannels).
   * @var string
   */
  protected string $channel = '';

  /**
   * The fully-rendered message body.
   * @var string
   */
  protected string $message = '';

  /**
   * Per-call channel config; keys per the channel's getConfigSchema().
   *
   * Plain `@var array` is deliberate: for classes under Civi\Api4 that extend
   * an *Action, CiviCRM core parses this annotation at RUNTIME to type the API
   * parameter, and it does not understand generics — `@var array<string, mixed>`
   * makes the call fail with "Unknown parameter type". The generic lives in a
   * separate @phpstan-var so static analysis loses nothing.
   *
   * @var array
   * @phpstan-var array<string, mixed>
   */
  protected array $config = [];

  /**
   * Channel-agnostic headline. Mapped onto the channel's own subject/title
   * config key; ignored by channels that have no such concept.
   * @var string
   */
  protected string $subject = '';

  /**
   * Channel-agnostic body format: 'text' or 'html'. Empty = let the channel
   * apply its own default.
   * @var string
   */
  protected string $format = '';

  public function _run(Result $result): void {
    if ($this->channel === '') {
      throw new \CRM_Core_Exception('Herald.send: "channel" parameter is required.');
    }
    if ($this->message === '') {
      throw new \CRM_Core_Exception('Herald.send: "message" parameter is required.');
    }
    if ($this->format !== '' && !in_array($this->format, ['text', 'html'], TRUE)) {
      throw new \CRM_Core_Exception('Herald.send: "format" must be "text" or "html".');
    }

    // Throws CRM_Core_Exception for an unregistered key — an unknown channel is
    // a configuration error, not a delivery outcome.
    $channel = ChannelRegistry::default()->get($this->channel);

    $schema = $channel->getConfigSchema();
    $unknown = array_values(array_diff(array_keys($this->config), array_keys($schema)));

    $config = self::applySemantics($this->config, $channel->getSemanticFields(), $this->subject, $this->format);

    $channel->send($this->message, $config);

    $result[] = [
      'channel' => $this->channel,
      'sent' => TRUE,
      'message_length' => strlen($this->message),
      'unknown_config_keys' => $unknown,
    ];
  }

  /**
   * Translate the semantic params into the channel's own config keys. Pure —
   * test-seam. An explicit entry in $config always wins: the caller naming the
   * channel's real key knows more than the generic semantic did.
   *
   * @param array<string, mixed> $config
   * @param array<string, string> $semanticFields
   * @return array<string, mixed>
   */
  public static function applySemantics(array $config, array $semanticFields, string $subject, string $format): array {
    $values = ['subject' => $subject, 'format' => $format];
    foreach ($values as $semantic => $value) {
      if ($value === '' || !isset($semanticFields[$semantic])) {
        continue;
      }
      $key = $semanticFields[$semantic];
      if (!isset($config[$key])) {
        $config[$key] = $value;
      }
    }
    return $config;
  }

}
