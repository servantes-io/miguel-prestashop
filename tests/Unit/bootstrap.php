<?php

require_once __DIR__ . '/../../vendor/autoload.php';

define('MIGUEL_PS_ROOT_DIR', dirname(__DIR__, 4));

if (file_exists(MIGUEL_PS_ROOT_DIR . '/tests-legacy')) {
    require_once MIGUEL_PS_ROOT_DIR . '/tests-legacy/bootstrap.php';
} else {
    require_once MIGUEL_PS_ROOT_DIR . '/tests/Unit/bootstrap.php';
}

require_once MIGUEL_PS_ROOT_DIR . '/config/config.inc.php';
require_once MIGUEL_PS_ROOT_DIR . '/config/defines_uri.inc.php';
require_once MIGUEL_PS_ROOT_DIR . '/init.php';

require_once __DIR__ . '/../../miguel.php';

$module = new Miguel();
$module->install();

Miguel\Utils\MiguelSettings::reset();
