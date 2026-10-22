<?php

declare(strict_types = 1);

/**
 * `subscription_type` becomes `subscribers_of`, and the types it named are
 * kept. Outside subscriber mode the key was never read and goes.
 */
final class CRM_Herald_Upgrader_SubscriberKey {

  /**
   * @var list<string>
   */
  public array $named = [];

  /**
   * @param array<array-key, mixed> $params
   * @return array<array-key, mixed>
   */
  public function __invoke(array $params): array {
    return $this->rewrite($params, $params['delivery_mode'] ?? 'direct');
  }

  /**
   * A rerun after a failed step finds the key already rewritten.
   *
   * @param array<array-key, mixed> $params
   * @return array<array-key, mixed>
   */
  public function rewrite(array $params, mixed $mode): array {
    $type = self::typeOf($params);
    unset($params['subscription_type']);
    if ($mode !== 'subscribers' || !is_string($type) || $type === '') {
      return $params;
    }
    $this->named[] = $type;
    return $params + ['subscribers_of' => $type];
  }

  /**
   * The subscription type the params name, rewritten or not; '' for none.
   *
   * @param array<array-key, mixed> $params
   */
  public static function typeOf(array $params): mixed {
    return CRM_Herald_Upgrader_SubscriptionTypes::of($params) ?? $params['subscribers_of'] ?? '';
  }

}
