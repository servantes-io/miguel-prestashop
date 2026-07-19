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
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2024 Servantes
 *  @license LICENSE.txt
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

use Miguel\Utils\MiguelApiDispatcher;

class MiguelApiModuleFrontController extends ModuleFrontController
{
    /**
     * @var bool no customer login required; the bearer token authenticates
     */
    public $auth = false;

    /**
     * @var bool Miguel always calls over HTTPS
     */
    public $ssl = true;

    public function initContent()
    {
        $resource = Tools::getValue('resource');
        if (!is_string($resource)) {
            $resource = '';
        }

        $dispatcher = new MiguelApiDispatcher($this->module);
        $response = $dispatcher->dispatch(
            $resource,
            isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            $_GET,
            $this->module->readFileContent('php://input')
        );

        // Discard any buffered theme/partial output so the response is pure JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('User-Agent: ' . $this->module->getUserAgent());

        echo json_encode($response);
        exit;
    }

    /**
     * Keep the API reachable while the shop is in maintenance mode.
     * The parent implementation renders the 503 maintenance page and exits.
     */
    protected function displayMaintenancePage()
    {
        // intentionally left blank
    }

    /**
     * Keep the API reachable regardless of geolocation restrictions — the bearer
     * token is the access gate. The parent renders a 403 restricted-country page.
     */
    protected function displayRestrictedCountryPage()
    {
        // intentionally left blank
    }
}
