<?php

namespace Tests\Unit\Controllers\Front;

require_once dirname(__DIR__, 4) . '/controllers/front/ApiOrders.php';

use Tests\Unit\Utility\ApiTestCase;

class ApiOrdersTest extends ApiTestCase
{
    public function testGetOrders2(): void
    {
        $this->expectOutputString('{"orders":[]}');

        $route = $this->router->generate('module_miguel_orders');
        static::createClient()->request('GET', $route);

        $this->assertResponseIsSuccessful();
    }

    public function testGetOrders(): void
    {
        $this->expectOutputString('{"orders":[]}');

        $sut = new \MiguelApiOrdersModuleFrontController();
        $sut->displayAjax();

        $this->assertResponseIsSuccessful();
    }
}
