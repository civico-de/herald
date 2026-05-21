<?php

namespace Civi\CivicoNotify\Api4\Action;

use Civi\Api4\Generic\AbstractAction;
use Civi\Api4\Generic\Result;
use Civi\CivicoNotify\Channel\ChannelRegistry;

/**
 * Send a test message via the configured channel. Useful smoke check after install.
 *
 * Example:
 *   cv api4 CivicoNotify.testTelegram +v 'message="hello from civicokitchen"'
 */
class TestTelegram extends AbstractAction {

  /**
   * Channel key. Defaults to "telegram".
   * @var string
   */
  protected string $channel = 'telegram';

  /**
   * Message body (HTML for telegram parse_mode=HTML).
   * @var string
   */
  protected string $message = '';

  /**
   * Recipient. Falls back to civico_notify_telegram_default_chat_id setting.
   * @var string|null
   */
  protected ?string $chatId = NULL;

  public function _run(Result $result) {
    $body = $this->message !== '' ? $this->message : ('<b>civico_notify smoke test</b> — sent ' . date('c'));

    $config = [];
    if ($this->chatId !== NULL && $this->chatId !== '') {
      $config['chat_id'] = $this->chatId;
    }

    $channel = ChannelRegistry::default()->get($this->channel);
    $channel->send($body, $config);

    $result[] = [
      'channel' => $this->channel,
      'status' => 'sent',
      'message_length' => strlen($body),
    ];
  }

}
