<?php

require_once __DIR__ . '/../../vendor/autoload.php';

if (file_exists(__DIR__ . '/../../vendor2/PrestaShop/tests-legacy')) {
    require_once __DIR__ . '/../../vendor2/PrestaShop/tests-legacy/bootstrap.php';
} else {
    require_once __DIR__ . '/../../vendor2/PrestaShop/tests/Unit/bootstrap.php';
}

require_once __DIR__ . '/../../vendor2/PrestaShop/config/config.inc.php';
require_once __DIR__ . '/../../vendor2/PrestaShop/config/defines_uri.inc.php';
require_once __DIR__ . '/../../vendor2/PrestaShop/init.php';

require_once __DIR__ . '/../../miguel.php';

$module = new Miguel();
$module->install();

Miguel\Utils\MiguelSettings::reset();
