<?php

declare(strict_types = 1);

namespace Civi\Herald\Dispatch;

/**
 * A fan-out resolved to nobody: deactivated subscription type, emptied group,
 * blank address list.
 *
 * Its own class because this is a PERMANENT fault — retrying re-resolves the
 * same empty list forever, so the buffer flush parks such an item on the first
 * occurrence instead of counting attempts.
 */
class NoRecipientsException extends \CRM_Core_Exception {}
