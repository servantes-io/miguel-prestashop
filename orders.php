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
tato stránka slouží jako API a vrací objednávky za zvolené období
je nutné se ověřit pomocí tokenu
*/
header('Content-Type: application/json; charset=UTF-8');

include_once '../../config/config.inc.php';
include_once 'miguel.php';

// required thing for PrestaShop validator (needs to be after config.inc.php)
if (!defined('_PS_VERSION_')) {
    exit;
}

$module = new Miguel();
$context = Context::getContext();
$context->controller = new FrontController();

$valid = $module->validateApiAccess();
if ($valid !== true) {
    echo json_encode($valid, JSON_PRETTY_PRINT);
    exit;
}

if (false == Tools::getIsset('updated_since')) {
    return MiguelApiResponse::error(MiguelApiError::argumentNotSet('updated_since'));
}
$output = MiguelApiResponse::success($module->getUpdatedOrders(Tools::getValue('updated_since')), 'orders');

echo json_encode($output, JSON_PRETTY_PRINT);
