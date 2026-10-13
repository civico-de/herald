<?php

declare(strict_types = 1);

/**
 * CiviRules' civix loader aliases this to core's DAO base at runtime; phpstan
 * scans CiviRules without running that loader.
 */
class CRM_Civirules_DAO_Base extends CRM_Core_DAO_Base {}
