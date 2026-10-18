<?php

declare(strict_types = 1);

namespace Civi\Herald\Api4\Action;

use Civi\Api4\Generic\Result;
use Civi\Herald\Channel\ChannelRegistry;
use Civi\Herald\Digest\Composer;
use Civi\Herald\Digest\Definition;
use Civi\Herald\Dispatch\ChannelSubject;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

/**
 * Composes a digest from the values the digest form holds, like
 * previewDigest, and emails it to the logged-in user only. A digest that
 * cannot be composed is not sent; `problems` says why.
 */
class SendDigestTest extends PreviewDigest {

  public function _run(Result $result): void {
    $preview = (new Composer())->check(Definition::of($this->digest, $this->checkedSources()));
    if ($preview['body'] === NULL) {
      $result[] = ['sent' => FALSE, 'to' => NULL, 'problems' => $preview['problems']];
      return;
    }
    $to = self::userEmail();
    $channel = ChannelRegistry::default()->get('email');
    $channel->send(
      $preview['body'],
      ChannelSubject::forChannel($channel, ChannelSubject::withSubject(['email_to' => $to], $preview['subject'])),
    );
    $result[] = ['sent' => TRUE, 'to' => $to, 'problems' => []];
  }

  private static function userEmail(): string {
    $contactId = \CRM_Core_Session::getLoggedInContactID();
    $email = $contactId === NULL
      ? NULL
      : \Civi\Api4\Email::get(FALSE)
        ->addSelect('email')
        ->addWhere('contact_id', '=', $contactId)
        ->addOrderBy('is_primary', 'DESC')
        ->execute()
        ->first();
    $address = Cast::toString($email['email'] ?? '');
    if ($address === '') {
      throw new \CRM_Core_Exception(E::ts('Your contact has no email address for the test.'));
    }
    return $address;
  }

}
