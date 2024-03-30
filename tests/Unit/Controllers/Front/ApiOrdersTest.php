<?php

use Tests\Unit\Utility\ApiTestCase;

class ApiOrdersTest extends ApiTestCase
{
    public function testGetOrders(): void
    {
        $route = $this->router->generate('module-miguel-orders');
        static::createClient()->request('GET', $route);

        $this->assertResponseIsSuccessful();
    }
}
