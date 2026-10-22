<?php

declare(strict_types = 1);

use CRM_Herald_ExtensionUtil as E;

/**
 * Upgrade steps from 1011 on; core also finds the inherited upgrade_* methods.
 */
abstract class CRM_Herald_Upgrader_Steps extends CRM_Herald_Upgrader_EarlySteps {

  /**
   * Digests move out of rule action and job parameters into HeraldDigest
   * records with their own subject and text; the reconcile registers the Schedule
   * trigger and the shipped searches the migrated digests use.
   */
  public function upgrade_1011(): bool {
    $this->ctx->log->info('herald: creating the digest tables');
    E::schema()->createEntityTable('schema/HeraldDigest.entityType.php');
    E::schema()->createEntityTable('schema/HeraldDigestSource.entityType.php');
    CRM_Herald_Upgrader_Reconcile::run();
    $actions = CRM_Herald_Upgrader_InlineDigestActions::migrate();
    $jobs = CRM_Herald_Upgrader_InlineDigestJobs::migrate();
    $this->ctx->log->info("herald: moved the digests of {$actions} rule action(s) and {$jobs} job(s) into records");
    return TRUE;
  }

  /**
   * Digests keep their subject and text themselves instead of in a message template.
   */
  public function upgrade_1012(): bool {
    $moved = CRM_Herald_Upgrader_DigestText::move();
    $this->ctx->log->info("herald: moved the text of {$moved} digest(s) out of their message templates");
    return TRUE;
  }

  /**
   * The contribution-source rules become a cleaned field.
   */
  public function upgrade_1013(): bool {
    $moved = CRM_Herald_Upgrader_CleanedSource::move();
    $this->ctx->log->info("herald: moved the source rules of {$moved} domain(s) into cleaned fields");
    return TRUE;
  }

  /**
   * The channel settings become named channels, and every rule action, job,
   * route and buffered alert names one.
   */
  public function upgrade_1014(): bool {
    $moved = CRM_Herald_Upgrader_NamedChannels::migrate();
    $this->ctx->log->info(
      "herald: created {$moved['channels']} channel(s) and rewrote {$moved['deliveries']} delivery setting(s)",
    );
    return TRUE;
  }

  /**
   * Subscription types become subscribable topics, and deliveries to
   * subscribers name the topic.
   */
  public function upgrade_1015(): bool {
    $moved = CRM_Herald_Upgrader_TopicSubscriptions::migrate();
    $this->ctx->log->info(
      "herald: created {$moved['topics']} topic(s) from subscription types and rewrote {$moved['deliveries']} delivery setting(s)",
    );
    return TRUE;
  }

  /**
   * The self-service profile opens as a standalone form.
   */
  public function upgrade_1016(): bool {
    $this->ctx->log->info('herald: offering the herald_my_preferences profile as a standalone form');
    CRM_Herald_Upgrader_PreferencesProfile::ensure();
    return TRUE;
  }

}
