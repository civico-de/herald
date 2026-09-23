<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

/**
 * Email channel — delivers the rendered body through CiviCRM's configured
 * outbound mailer (CRM_Utils_Mail::send), so SMTP settings, mail logging and
 * dev-environment redirection all apply.
 *
 * Per-call config (from action params / channel_config JSON):
 *   email_to       string  Recipient address (required; falls back to setting)
 *   email_to_name  string  Recipient display name
 *   email_subject  string  Explicit subject. When unset, AbstractSend defaults
 *                          it to the template's rendered msg_subject; final
 *                          fallback is DEFAULT_SUBJECT
 *   email_format   string  'html' (default) or 'text' — which MIME part the
 *                          rendered body becomes; 'text' gets it as PlainText
 *   email_cc       string  Comma-separated CC addresses
 *   email_bcc      string  Comma-separated BCC addresses
 *
 * Settings:
 *   herald_email_default_to — fallback recipient when the action sets none
 *   herald_email_from       — From header ("Name <email>"); empty → site default
 */
class EmailChannel implements ChannelInterface {

  public const DEFAULT_SUBJECT = 'Notification';

  /**
   * @var (callable(array<string, mixed>): bool)|null */
  private $mailer;

  /**
   * @param callable|null $mailer
   *   Test-seam: callable(array $mailParams): bool replacing CRM_Utils_Mail::send.
   */
  public function __construct(?callable $mailer = NULL) {
    $this->mailer = $mailer;
  }

  public function getKey(): string {
    return 'email';
  }

  /**
   * @param array<string, mixed> $config
   */
  public function send(string $renderedBody, array $config): void {
    // !empty, not ??: an empty email_to must still fall back to the setting.
    $to = !empty($config['email_to'])
      ? $config['email_to']
      : \Civi::settings()->get('herald_email_default_to');
    if (!$to) {
      throw new \CRM_Core_Exception(
        'herald: email channel requires email_to (action param or herald_email_default_to setting).',
      );
    }

    $from = self::str(\Civi::settings()->get('herald_email_from'));
    if ($from === '') {
      $from = \CRM_Core_BAO_Domain::getFromEmail();
    }

    $params = self::buildMailParams($renderedBody, ['email_to' => $to] + $config, $from);

    $mailer = $this->mailer ?? static fn (array $p): bool => (bool) \CRM_Utils_Mail::send($p);
    if (!$mailer($params)) {
      throw new \CRM_Core_Exception(
        'herald: email delivery failed — CRM_Utils_Mail::send returned FALSE. Check the outbound mail settings.',
      );
    }
  }

  /**
   * Build the CRM_Utils_Mail::send params from channel config. Pure — test-seam.
   *
   * @param array<string, mixed> $config
   * @return array<string, mixed>
   */
  public static function buildMailParams(string $body, array $config, string $from): array {
    $params = [
      'from' => $from,
      'toEmail' => self::str($config['email_to']),
      'toName' => self::str($config['email_to_name'] ?? ''),
      'subject' => !empty($config['email_subject']) ? self::str($config['email_subject']) : self::DEFAULT_SUBJECT,
    ];
    $isText = ($config['email_format'] ?? 'html') === 'text';
    $params[$isText ? 'text' : 'html'] = $isText ? PlainText::fromHtml($body) : $body;
    if (!empty($config['email_cc'])) {
      $params['cc'] = self::str($config['email_cc']);
    }
    if (!empty($config['email_bcc'])) {
      $params['bcc'] = self::str($config['email_bcc']);
    }
    return $params;
  }

  /**
   * Non-scalar config values collapse to '' instead of "Array".
   */
  private static function str(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'email_to' => ['label' => 'Recipient address', 'required' => TRUE, 'type' => 'string'],
      'email_to_name' => ['label' => 'Recipient name', 'required' => FALSE, 'type' => 'string'],
      'email_subject' => ['label' => 'Subject', 'required' => FALSE, 'type' => 'string'],
      'email_format' => [
        'label' => 'Body format',
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['html', 'text'],
      ],
      'email_cc' => ['label' => 'CC (comma-separated)', 'required' => FALSE, 'type' => 'string'],
      'email_bcc' => ['label' => 'BCC (comma-separated)', 'required' => FALSE, 'type' => 'string'],
    ];
  }

  /**
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return ['subject' => 'email_subject', 'format' => 'email_format'];
  }

}
