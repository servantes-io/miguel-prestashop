<?php

use Tests\Unit\Utility\ApiTestCase;

class ApiOrdersTest extends ApiTestCase
{
    public function testGetOrders(): void
    {
        static::createClient()->request('GET', '/modules/miguel/orders.php');
        $this->assertResponseIsSuccessful();
    }
}
