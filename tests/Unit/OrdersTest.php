<?php

namespace Tests\Unit;

use Miguel;
use Miguel\Utils\MiguelSettings;
use Order;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class OrdersTest extends DatabaseTestCase
{
    private $previousErrorReportingSetting;

    protected function setUp(): void
    {
        parent::setUp();

        unset($_GET['updated_since']);
        unset($_SERVER['Authorization']);

        $this->previousErrorReportingSetting = error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);

        // Suppress output to console
        if (method_exists($this, 'setOutputCallback'))
        {
            $this->setOutputCallback(function() {});
        }
    }

    protected function tearDown(): void
    {
        error_reporting($this->previousErrorReportingSetting);

        parent::tearDown();
    }

    public function testWithoutArgument()
    {
        // PREPARE
        $_SERVER['Authorization'] = 'Bearer 1234';

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');

        // TEST
        $output = $this->sut();

        // ASSERT
        $expected_output = json_encode([
            'result' => false,
            'debug' => '',
            'error' => [
                'code' => 'argument.not_set',
                'message' => 'Argument updated_since not set',
            ],
        ]);
        $this->assertJsonStringEqualsJsonString($output, $expected_output);
    }

    public function testInvalidToken()
    {
        // PREPARE
        $_GET['updated_since'] = '2022-01-01';
        $_SERVER['Authorization'] = 'Bearer 1234';

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '0000');

        // TEST
        $output = $this->sut();

        // ASSERT
        $expected_output = json_encode([
            'result' => false,
            'debug' => '',
            'error' => [
                'code' => 'api_key.invalid',
                'message' => 'API key invalid',
            ],
        ]);
        $this->assertJsonStringEqualsJsonString($output, $expected_output);
    }

    public function testActualData()
    {
        // PREPARE
        $_GET['updated_since'] = '2022-01-01';
        $_SERVER['Authorization'] = 'Bearer 1234';

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');

        // TEST
        $output = $this->sut();

        // ASSERT
        $json = json_decode($output, true);
        $this->assertIsArray($json['orders']);
    }

    public function testEmptyReferenceInProduct()
    {
        // PREPARE
        $_GET['updated_since'] = '2022-01-01';
        $_SERVER['Authorization'] = 'Bearer 1234';

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');

        $existing_orders = Order::getOrdersWithInformations();

        $order = $this->entityCreator->createOrder();
        $order->reference = '1234';
        $order->save();

        $product = new Product();
        $product->name = 'Test product';
        $product->reference = '';
        $product->save();

        $orderDetail = $this->entityCreator->createOrderDetail($order, $product);
        $orderDetail->save();

        // TEST
        $output = $this->sut();

        // ASSERT
        $json = json_decode($output, true);
        $this->assertIsArray($json['orders']);
        $this->assertCount(count($existing_orders), $json['orders']); // no new order should be returned
    }

    private function sut(): string
    {
        include __DIR__ . '/../../orders.php';
        return $this->getActualOutputForAssertion();
    }
}
