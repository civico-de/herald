<?php

declare(strict_types = 1);

/**
 * A legacy CiviRules DAO file declares this by class_alias, which phpstan's scan
 * does not see; CiviRules' condition BAO still extends it.
 */
class CRM_Civirules_DAO_RuleCondition extends CRM_Civirules_DAO_CiviRulesRuleCondition {}
