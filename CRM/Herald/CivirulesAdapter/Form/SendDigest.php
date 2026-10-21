<?php

declare(strict_types = 1);

use Civi\Herald\Digest\DigestRules;
use Civi\Herald\Dispatch\Dispatcher;
use Civi\Herald\Topic\Router;
use Civi\Herald\Vendor\Civico\Stdlib\Cast;
use CRM_Herald_ExtensionUtil as E;

// ckconform-optional civirules -- herald works without CiviRules; this class exists only with it
if (class_exists('CRM_CivirulesActions_Form_Form')) {
  /**
   * Form for the digest action: which digest (HeraldDigest) goes out, and how.
   * CiviRules opens its parameter screens as QuickForm forms by route.
   * @mago-expect analysis:civikitchen/legacy-ui-base
   */
  class CRM_Herald_CivirulesAdapter_Form_SendDigest extends CRM_Herald_CivirulesAdapter_Form_AbstractSend {

    /**
     * @return array<string, string>
     */
    protected function getTemplateOptions(): array {
      return [];
    }

    protected function addExtraFields(): void {
      $this->add(
        'select',
        'digest',
        E::ts('Digest'),
        ['' => E::ts('- select digest -')] + self::digestOptions(),
        required: TRUE,
      );
      $this->assign('heraldDigestsUrl', CRM_Utils_System::url('civicrm/admin/herald/digests', 'reset=1'));
    }

    /**
     * @param array<string, mixed> $submitted
     * @return array<string, mixed>
     */
    protected function getExtraValues(array $submitted): array {
      // A topic's route counts too; an unknown topic is reported by SendParams::errors().
      $params = CRM_Herald_CivirulesAdapter_SendParams::of($submitted);
      $topic = Cast::toString($params['topic'] ?? '');
      $problem = $topic === '' || Router::exists($topic)
        ? DigestRules::deliveryProblem(Dispatcher::withTopic($params))
        : NULL;
      if ($problem !== NULL) {
        throw new \CRM_Core_Exception($problem);
      }
      return ['digest' => Cast::toString($submitted['digest'] ?? '')];
    }

    /**
     * @return array<string, mixed>
     */
    protected function extraDefaults(): array {
      return ['digest' => $this->getActionParameters()['digest'] ?? ''];
    }

    /**
     * @return array<string, string> name => title
     */
    private static function digestOptions(): array {
      /** @var list<array{name: string, title: string}> $digests */
      $digests = \Civi\Api4\HeraldDigest::get(checkPermissions: FALSE)
        ->addSelect('name', 'title')
        ->addOrderBy('title')
        ->execute()
        ->getArrayCopy();
      return array_column($digests, 'title', 'name');
    }

  }
}
