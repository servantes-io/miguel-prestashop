<?php

namespace Tests\Unit;

use Miguel;
use Miguel\Utils\MiguelApiDispatcher;
use Miguel\Utils\MiguelSettings;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class OrderResourceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');
        $_SERVER['Authorization'] = 'Bearer 1234';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);
        parent::tearDown();
    }

    public function testReturnsSingleOrderByCode()
    {
        $order = $this->entityCreator->createOrder();
        $order->reference = 'ABCTEST';
        $order->save();

        $product = new Product();
        $product->name = 'Test product';
        $product->reference = '9788024271101';
        $product->save();

        $orderDetail = $this->entityCreator->createOrderDetail($order, $product);
        $orderDetail->product_quantity = 2;
        $orderDetail->save();

        $response = (new MiguelApiDispatcher(new Miguel()))->dispatch('order', 'GET', ['code' => 'ABCTEST'], '');

        $this->assertTrue($response->getResult());
        $this->assertSame('order', $response->getDataKey());

        $data = $response->getData();
        $this->assertSame('ABCTEST', $data['code']);
        $this->assertSame((int) $order->id, $data['id']);
        $this->assertSame(2, $data['products'][0]['quantity']);
        $this->assertIsArray($data['billing_address']);
    }
}
