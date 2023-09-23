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

header('Content-Type: application/json; charset=UTF-8');

include_once '../../config/config.inc.php';
include_once 'miguel.php';

$module = new Miguel();
$context = Context::getContext();
$context->controller = new FrontController();
$configuration = $module->getCurrentApiConfiguration();
$token = $module->getBearerToken();

$json = Tools::file_get_contents('php://input');
$data = json_decode($json, true);

$output = [
    'result' => false,
    'debug' => 'unexpected state',
];

if (false == array_key_exists('code', $data)) {
    $output['result'] = false;
    $output['debug'] = 'code not set';
} elseif (false == $token) {
    $output['result'] = false;
    $output['debug'] = 'token not set';
} elseif (false == $configuration) {
    $output['result'] = false;
    $output['debug'] = 'configuration not set';
} elseif (0 == $configuration['api_enable']) {
    $output['result'] = false;
    $output['debug'] = 'api disable';
} elseif (1 == $configuration['api_enable']) {
    if ($configuration['token'] == $token) {
        $output['result'] = true;
        $output['debug'] = $module->setOrderStates($data);
    } else {
        $output['result'] = false;
        $output['debug'] = 'token not valid';
    }
}
// $module->logData($output);
echo json_encode($output, JSON_PRETTY_PRINT);
