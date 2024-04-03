<?php
/**
 * 2023 Servantes.
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
tato stránka slouží jako API, pro automatickou změnu stavů v PS
*/

include_once '../../config/config.inc.php';
include_once 'miguel.php';

use Miguel\Utils\MiguelApiError;
use Miguel\Utils\MiguelApiResponse;

// required thing for PrestaShop validator (needs to be after config.inc.php)
if (!defined('_PS_VERSION_')) {
    exit;
}

header('Content-Type: application/json; charset=UTF-8');

$module = new Miguel();
$context = Context::getContext();
$context->controller = new FrontController();

$valid = $module->validateApiAccess();
if ($valid !== true) {
    echo json_encode($valid, JSON_PRETTY_PRINT);
    exit;
}

$json = Tools::file_get_contents('php://input');
$data = json_decode($json, true);

if (false == array_key_exists('code', $data)) {
    $output = MiguelApiResponse::error(MiguelApiError::invalidPayload('code not set'));
} else {
    $output = MiguelApiResponse::success($module->setOrderStates($data), 'result');
}

echo json_encode($output);
