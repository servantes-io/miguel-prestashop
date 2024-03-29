<?php

namespace Miguel\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Miguel\Utils\MiguelApiResponse;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;

class MiguelApiOrdersController extends Controller
{
    /**
     * @var \Miguel
     */
    private $module;

    public function __construct()
    {
        $this->module = new \Miguel();
    }

    public function index()
    {
        $updated_since = \Tools::getValue('updated_since');
        $orders = $this->module->getUpdatedOrders($updated_since);

        return $this->json(MiguelApiResponse::success($orders, 'orders'));
    }
}
