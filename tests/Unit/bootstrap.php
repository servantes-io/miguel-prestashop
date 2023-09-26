<?php
/**
 * 2023 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Pavel Vejnar <vejnar.p@gmail.com>
 *  @copyright  2022 - 2023 Servantes
 *  @license LICENSE.txt
 */
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
