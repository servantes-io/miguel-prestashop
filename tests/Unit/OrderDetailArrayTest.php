<?php

namespace Tests\Unit;

use Miguel;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class OrderDetailArrayTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
    }

    /**
     * Persists an order + product + order detail and returns [Order, Product, OrderDetail].
     *
     * @return array
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

        return [$order, $product, $orderDetail];
    }

    public function testProductLineCarriesQuantity()
    {
        list($order) = $this->buildOrder('QTYTEST', '9788024271101', 4);

        $result = (new Miguel())->createOrderDetailArray(['id_order' => $order->id]);

        $this->assertIsArray($result);
        $this->assertSame(4, $result['products'][0]['quantity']);
    }
}
