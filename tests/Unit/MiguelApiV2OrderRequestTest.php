<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiV2OrderRequest;
use Order;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class MiguelApiV2OrderRequestTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
    }

    /**
     * @return Order
     */
    private function buildOrder(string $reference, string $productReference, int $quantity = 1)
    {
        $order = $this->entityCreator->createOrder();
        $order->reference = $reference;
        $order->save();

        $product = new Product();
        $product->name = 'Test product';
        $product->reference = $productReference;
        $product->save();

        $orderDetail = $this->entityCreator->createOrderDetail($order, $product);
        $orderDetail->product_quantity = $quantity;
        $orderDetail->save();

        return $order;
    }

    public function testBuildsV2OrderCreateShape()
    {
        $order = $this->buildOrder('V2CREATE', '9788024271101', 2);

        $body = MiguelApiV2OrderRequest::build($order, true);

        $this->assertIsArray($body);
        $this->assertSame('V2CREATE', $body['code']);
        $this->assertSame((string) $order->id, $body['eshopId']);
        $this->assertArrayHasKey('currencyCode', $body);
        // user is WatermarkUser: email + language required, camelCase keys
        $this->assertArrayHasKey('email', $body['user']);
        $this->assertArrayHasKey('language', $body['user']);
        $this->assertArrayHasKey('name', $body['user']);
        // items are OrderCreateItem: unitPrice.withoutVat, no regular price
        $item = $body['items'][0];
        $this->assertSame('9788024271101', $item['code']);
        $this->assertSame(2, $item['quantity']);
        $this->assertArrayHasKey('withoutVat', $item['unitPrice']);
        $this->assertArrayNotHasKey('price', $item);
        $this->assertArrayNotHasKey('regular_without_vat', $item);
    }

    public function testPurchasedAtIsSetWhenPaidAndNullWhenNot()
    {
        $order = $this->buildOrder('V2PAID', '9788024271101');

        $paidBody = MiguelApiV2OrderRequest::build($order, true);
        $unpaidBody = MiguelApiV2OrderRequest::build($order, false);

        $this->assertSame(date(DATE_ISO8601, strtotime($order->date_add)), $paidBody['purchasedAt']);
        $this->assertNull($unpaidBody['purchasedAt']);
    }

    public function testEshopDatesUseIso8601()
    {
        $order = $this->buildOrder('V2DATES', '9788024271101');

        $body = MiguelApiV2OrderRequest::build($order, true);

        $this->assertSame(date(DATE_ISO8601, strtotime($order->date_add)), $body['eshopCreatedAt']);
        $this->assertSame(date(DATE_ISO8601, strtotime($order->date_upd)), $body['eshopUpdatedAt']);
    }

    public function testAddressesUseCamelCaseFullName()
    {
        $order = $this->buildOrder('V2ADDR', '9788024271101');

        $body = MiguelApiV2OrderRequest::build($order, true);

        $this->assertIsArray($body['billingAddress']);
        $this->assertArrayHasKey('fullName', $body['billingAddress']);
        $this->assertArrayNotHasKey('full_name', $body['billingAddress']);
    }

    public function testReturnsNullWhenNoSendableItems()
    {
        // product without a reference is skipped by createProductsArray -> no items
        $order = $this->buildOrder('V2EMPTY', '');

        $this->assertNull(MiguelApiV2OrderRequest::build($order, true));
    }
}
