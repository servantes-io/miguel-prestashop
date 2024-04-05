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
if (file_exists(__DIR__ . '/../../vendor/autoload.php')) {
    require_once __DIR__ . '/../../vendor/autoload.php';
}

if (file_exists(dirname(__DIR__, 2) . '/vendor2/PrestaShop')) {
    // Installed in vendor2 folder next to miguel.php
    define('MIGUEL_PS_ROOT', dirname(__DIR__, 2) . '/vendor2/PrestaShop');
} else {
    // Installed in PrestaShop's modules/ folder
    define('MIGUEL_PS_ROOT', dirname(__DIR__, 4));
}

if (file_exists(MIGUEL_PS_ROOT . '/tests-legacy')) {
    require_once MIGUEL_PS_ROOT . '/tests-legacy/bootstrap.php';
} else {
    require_once MIGUEL_PS_ROOT . '/tests/Unit/bootstrap.php';
}

require_once MIGUEL_PS_ROOT . '/config/config.inc.php';
require_once MIGUEL_PS_ROOT . '/config/defines_uri.inc.php';
require_once MIGUEL_PS_ROOT . '/init.php';

require_once __DIR__ . '/../../miguel.php';

require_once __DIR__ . '/Utility/DatabaseTestCase.php';
require_once __DIR__ . '/Utility/ContextMocker.php';

$module = new Miguel();
$module->install();

Miguel\Utils\MiguelSettings::reset();
