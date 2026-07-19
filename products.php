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

/*
tato stránka slouží jako API a vrací seznamu názvu produktů
je nutné se ověřit pomocí tokenu
*/

require_once __DIR__ . '/../../config/config.inc.php';
require_once __DIR__ . '/miguel.php';

use Miguel\Utils\MiguelApiDispatcher;

// required thing for PrestaShop validator (needs to be after config.inc.php)
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * @deprecated Use the module front controller instead:
 * index.php?fc=module&module=miguel&controller=api&resource=products
 * Kept for backward compatibility; scheduled for removal in a future major version.
 */

$module = Miguel::createInstance();
$context = Context::getContext();
$context->controller = new FrontController();

header('Content-Type: application/json; charset=UTF-8');
header('User-Agent: ' . $module->getUserAgent());

$dispatcher = new MiguelApiDispatcher($module);
$output = $dispatcher->dispatch('products', 'GET', $_GET, '');

echo json_encode($output, JSON_PRETTY_PRINT);
