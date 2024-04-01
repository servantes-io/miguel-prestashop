<?php

use Miguel\Utils\MiguelApiResponse;

/*
tato stránka slouží jako API a vrací seznamu názvu produktů
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

$output = MiguelApiResponse::success($module->getAllProducts(), 'products');
echo json_encode($output, JSON_PRETTY_PRINT);
