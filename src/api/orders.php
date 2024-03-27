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
