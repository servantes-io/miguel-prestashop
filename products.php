<?php
/**
* 2007-2022 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2022 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

/* 
tato stránka slouží jako API a vrací seznamu názvu produktů
je nutné se ověřit pomocí tokenu
*/

header("Content-Type: application/json; charset=UTF-8");

include_once('../../config/config.inc.php');
include_once('miguel.php');

$module = new Miguel();
$context = Context::getContext();
$context->controller = new FrontController();
$configuration = $module->getCurrentApiConfiguration();
$token = $module->getBearerToken();

$output = array(
    'result' => false,
    'debug' => 'unexpected state'
);

if($token == false) {
    $output['result'] = false;
    $output['debug'] = 'token not set';
}
else if($configuration == false) {
    $output['result'] = false;
    $output['debug'] = 'configuration not set';
}
else if($configuration['api_enable'] == 0) {
    $output['result'] = false;
    $output['debug'] = 'api disable';
}
else if($configuration['api_enable'] == 1) {
    if($configuration['token'] == $token) {
        $output['result'] = true;
        $output['debug'] = '';
        $output['products'] = $module->getAllProducts();
    }
    else {
        $output['result'] = false;
        $output['debug'] = 'token not valid';
    }
}

echo json_encode($output, JSON_PRETTY_PRINT);












