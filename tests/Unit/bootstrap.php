<?php
/**
 * 2024 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2024 Servantes
 *  @license LICENSE.txt
 */
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

require_once __DIR__ . '/DatabaseTestCase.php';

$module = new Miguel();
$module->install();

Miguel\Utils\MiguelSettings::reset();
