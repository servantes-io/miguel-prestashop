<?php

use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\ContextMocker;
use Tests\Unit\Utility\DatabaseTestCase;

class OrdersTest extends DatabaseTestCase
{
    /**
     * @var ContextMocker
     */
    protected $contextMocker;

    private $previousErrorReportingSetting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextMocker = new ContextMocker();
        $this->contextMocker->mockContext();

        MiguelSettings::reset();
        unset($_GET['updated_since']);
        unset($_SERVER['Authorization']);

        $this->previousErrorReportingSetting = error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);

        // Suppress output to console
        $this->setOutputCallback(function() {});
    }

    protected function tearDown(): void
    {
        $this->contextMocker->resetContext();

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

        $order = new Order();
        $order->id_customer = 1;
        $order->id_address_delivery = 1;
        $order->id_address_invoice = 1;
        $order->id_cart = 1;
        $order->id_currency = 1;
        $order->id_lang = 1;
        $order->id_carrier = 1;
        $order->current_state = 1;
        $order->module = 'miguel';
        $order->payment = 'miguel';
        $order->total_paid = 1;
        $order->total_paid_real = 1;
        $order->total_products = 1;
        $order->total_products_wt = 1;
        $order->total_shipping = 1;
        $order->total_shipping_tax_incl = 1;
        $order->total_shipping_tax_excl = 1;
        $order->reference = '1234';
        $order->conversion_rate = 1;
        $order->save();

        $product = new Product();
        $product->reference = '';
        $product->save();

        $orderDetail = new OrderDetail();
        $orderDetail->id_order = $order->id;
        $orderDetail->product_id = $product->id;
        $orderDetail->product_name = "Product 1";
        $orderDetail->product_quantity = 1;
        $orderDetail->product_quantity_in_stock = 1;
        $orderDetail->product_price = 1;
        $orderDetail->product_reference = $product->reference;
        $orderDetail->unit_price_tax_incl = 1;
        $orderDetail->unit_price_tax_excl = 1;
        $orderDetail->total_price_tax_incl = 1;
        $orderDetail->total_price_tax_excl = 1;
        $orderDetail->id_warehouse = 1;
        $orderDetail->id_shop = 1;
        $orderDetail->id_shop_list = 1;
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
        return $this->getActualOutput();
    }
}
