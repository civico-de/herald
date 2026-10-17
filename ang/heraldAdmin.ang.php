<?php

declare(strict_types = 1);

/**
 * The admin screens on civicrm/admin/herald; their saves go through the
 * validating Herald.save* actions.
 */
return [
  'js' => ['ang/heraldAdmin.js', 'ang/heraldAdmin/*.js'],
  'css' => ['ang/heraldAdmin.css'],
  'partials' => ['ang/heraldAdmin'],
  'requires' => ['crmUi', 'crmUtil', 'ngRoute', 'api4'],
  'basePages' => ['civicrm/admin/herald'],
  'settingsFactory' => [\Civi\Herald\Admin\AdminScreens::class, 'angularSettings'],
];
