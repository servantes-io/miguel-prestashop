<?php

namespace Tests\Unit;

use Miguel;
use Miguel\Utils\MiguelSettings;
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

        $order = $this->entityCreator->createOrder();
        $order->reference = 'EMPTYREFONLY';
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
        // An order whose only product has an empty reference must be excluded from the
        // API output. Assert by code (not by a cross-test order count, which is fragile
        // in the shared, non-transactional test DB).
        $json = json_decode($output, true);
        $this->assertIsArray($json['orders']);
        $this->assertNotContains('EMPTYREFONLY', array_column($json['orders'], 'code'));
    }

    private function sut(): string
    {
        include __DIR__ . '/../../orders.php';

        if (method_exists($this, 'getActualOutputForAssertion'))
        {
            return $this->getActualOutputForAssertion();
        }
        else
        {
            return $this->getActualOutput();
        }
    }
}
