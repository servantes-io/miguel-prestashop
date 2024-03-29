<?php

use Miguel\Controllers\MiguelApiAbstractFrontController;
use Miguel\Utils\MiguelApiError;
use Miguel\Utils\MiguelApiResponse;

class MiguelApiOrdersModuleFrontController extends MiguelApiAbstractFrontController
{
    protected $methods = ['GET'];

    public function getResponse()
    {
        if (false == Tools::getIsset('updated_since')) {
            return MiguelApiResponse::error(MiguelApiError::argumentNotSet('updated_since'));
        }

        $updated_since = Tools::getValue('updated_since');
        $orders = $this->module->getUpdatedOrders($updated_since);

        return MiguelApiResponse::success($orders, 'orders');
    }
}
