<?php

declare(strict_types = 1);

namespace Civi\Herald\Channel;

use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Email channel — delivers the rendered body through CiviCRM's configured
 * outbound mailer (CRM_Utils_Mail::send), so SMTP settings, mail logging and
 * dev-environment redirection all apply.
 *
 * Config:
 *   email_from     string  From header ("Name <email>"); empty → site default
 *   email_to       string  Recipient address (required)
 *   email_to_name  string  Recipient display name
 *   email_subject  string  Explicit subject. When unset, AbstractSend defaults
 *                          it to the template's rendered msg_subject; final
 *                          fallback is DEFAULT_SUBJECT
 *   email_format   string  'html' (default) or 'text' — which MIME part the
 *                          rendered body becomes; 'text' gets it as PlainText,
 *                          'html' with its line breaks as <br> (HtmlLineBreaks)
 *   email_cc       string  Comma-separated CC addresses
 *   email_bcc      string  Comma-separated BCC addresses
 */
class EmailChannel implements ChannelInterface {

  public const DEFAULT_SUBJECT = 'Notification';

  /**
   * @var (callable(array<string, mixed>): bool)|null */
  private $mailer;

  /**
   * @param (callable(array<string, mixed>): bool)|null $mailer
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
    $to = $config['email_to'] ?? '';
    if (empty($to)) {
      throw new \CRM_Core_Exception('herald: no email_to, neither on the delivery nor on the channel.');
    }

    $from = Cast::toString($config['email_from'] ?? '');
    if ($from === '') {
      $from = \CRM_Core_BAO_Domain::getFromEmail();
    }

    $params = self::buildMailParams($renderedBody, ['email_to' => $to] + $config, $from);

    $mailer = $this->mailer ?? \CRM_Utils_Mail::send(...);
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
      'toEmail' => Cast::toString($config['email_to']),
      'toName' => Cast::toString($config['email_to_name'] ?? ''),
      'subject' => !empty($config['email_subject']) ? Cast::toString($config['email_subject']) : self::DEFAULT_SUBJECT,
    ];
    $isText = ($config['email_format'] ?? 'html') === 'text';
    $params[$isText ? 'text' : 'html'] = $isText ? PlainText::fromHtml($body) : HtmlLineBreaks::apply($body);
    if (!empty($config['email_cc'])) {
      $params['cc'] = Cast::toString($config['email_cc']);
    }
    if (!empty($config['email_bcc'])) {
      $params['bcc'] = Cast::toString($config['email_bcc']);
    }
    return $params;
  }

  /**
   * @return array<string, array{label: string, required: bool, type: string, options?: array<int|string, string>, default?: string, help?: string, scope?: 'channel'|'delivery', secret?: bool, recipient?: bool, bound_to?: string}>
   */
  public function getConfigSchema(): array {
    return [
      'email_from' => [
        'label' => E::ts('Sender ("Name <email>")'),
        'required' => FALSE,
        'type' => 'string',
        'scope' => 'channel',
        'help' => E::ts("If empty, the site's default sender is used."),
      ],
      'email_to' => [
        'label' => E::ts('Recipient address'),
        'required' => TRUE,
        'type' => 'string',
        'recipient' => TRUE,
      ],
      'email_to_name' => [
        'label' => E::ts('Recipient name'),
        'required' => FALSE,
        'type' => 'string',
        'recipient' => TRUE,
      ],
      'email_subject' => ['label' => E::ts('Subject'), 'required' => FALSE, 'type' => 'string'],
      'email_format' => [
        'label' => E::ts('Body format'),
        'required' => FALSE,
        'type' => 'enum',
        'options' => ['html', 'text'],
      ],
      'email_cc' => [
        'label' => E::ts('CC (comma-separated)'),
        'required' => FALSE,
        'type' => 'string',
        'recipient' => TRUE,
      ],
      'email_bcc' => [
        'label' => E::ts('BCC (comma-separated)'),
        'required' => FALSE,
        'type' => 'string',
        'recipient' => TRUE,
      ],
    ];
  }

  /**
   * @return array<string, string>
   */
  public function getSemanticFields(): array {
    return ['subject' => 'email_subject', 'format' => 'email_format'];
  }

}
