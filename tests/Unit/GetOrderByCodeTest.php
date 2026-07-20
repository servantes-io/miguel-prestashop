<?php

namespace Tests\Unit;

use Miguel;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class GetOrderByCodeTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
    }

    /**
     * @return \Order
     */
    private function persistOrder(string $reference, string $productReference)
    {
        $order = $this->entityCreator->createOrder();
        $order->reference = $reference;
        $order->save();

        $product = new Product();
        $product->name = 'Test product';
        $product->reference = $productReference;
        $product->save();

        $orderDetail = $this->entityCreator->createOrderDetail($order, $product);
        $orderDetail->save();

        return $order;
    }

    public function testReturnsOrderForKnownCode()
    {
        $order = $this->persistOrder('CODEFOUND', '9788024271101');

        $result = (new Miguel())->getOrderByCode('CODEFOUND');

        $this->assertIsArray($result);
        $this->assertSame('CODEFOUND', $result['code']);
        $this->assertSame((int) $order->id, $result['id']);
    }

    public function testReturnsFalseForUnknownCode()
    {
        $this->assertFalse((new Miguel())->getOrderByCode('NOSUCHCODE'));
    }

    public function testReturnsFalseWhenNoMiguelProducts()
    {
        $this->persistOrder('NOREF', '');

        $this->assertFalse((new Miguel())->getOrderByCode('NOREF'));
    }

    public function testReturnsNewestMatchingOrderForSharedReference()
    {
        $older = $this->persistOrder('DUPREF', '9788024271101');
        $newer = $this->persistOrder('DUPREF', '9788024271101');

        $this->assertGreaterThan((int) $older->id, (int) $newer->id);

        $result = (new Miguel())->getOrderByCode('DUPREF');

        $this->assertIsArray($result);
        $this->assertSame((int) $newer->id, $result['id']);
    }
}
