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

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Central routing/validation for the Miguel public API. Shared by the module
 * front controller and the legacy direct-access scripts. Never echoes: it
 * returns a MiguelApiResponse that the caller serializes.
 */
class MiguelApiDispatcher
{
    /**
     * @var \Miguel
     */
    private $module;

    public function __construct(\Miguel $module)
    {
        $this->module = $module;
    }

    /**
     * @param string $resource one of: orders, products, order-state-callback
     * @param string $method HTTP method (GET/POST)
     * @param array $get query parameters
     * @param string $rawBody raw request body (for POST resources)
     *
     * @return MiguelApiResponse
     */
    public function dispatch(string $resource, string $method, array $get, string $rawBody): MiguelApiResponse
    {
        $valid = $this->module->validateApiAccess();
        if ($valid !== true) {
            return $valid;
        }

        switch ($resource) {
            case 'orders':
                if ($method !== 'GET') {
                    return MiguelApiResponse::error(MiguelApiError::methodNotAllowed($method));
                }
                if (!isset($get['updated_since'])) {
                    return MiguelApiResponse::error(MiguelApiError::argumentNotSet('updated_since'));
                }

                return MiguelApiResponse::success($this->module->getUpdatedOrders($get['updated_since']), 'orders');

            case 'products':
                if ($method !== 'GET') {
                    return MiguelApiResponse::error(MiguelApiError::methodNotAllowed($method));
                }

                return MiguelApiResponse::success($this->module->getAllProducts(), 'products');

            case 'order-state-callback':
                if ($method !== 'POST') {
                    return MiguelApiResponse::error(MiguelApiError::methodNotAllowed($method));
                }
                $data = json_decode($rawBody, true);
                if (null === $data) {
                    return MiguelApiResponse::error(MiguelApiError::invalidPayload('payload is required'));
                }
                if (!is_array($data) || !array_key_exists('code', $data)) {
                    return MiguelApiResponse::error(MiguelApiError::invalidPayload('code not set'));
                }

                return MiguelApiResponse::success($this->module->setOrderStates($data), 'result');

            default:
                return MiguelApiResponse::error(MiguelApiError::resourceNotFound((string) $resource));
        }
    }
}
