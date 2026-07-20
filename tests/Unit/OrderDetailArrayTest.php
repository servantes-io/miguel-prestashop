<?php

namespace Tests\Unit;

use Address;
use Configuration;
use Country;
use Miguel;
use Miguel\Utils\MiguelApiCreateOrderRequest;
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

    public function testOrderCarriesIntegerId()
    {
        list($order) = $this->buildOrder('IDTEST', '9788024271101');

        $result = (new Miguel())->createOrderDetailArray(['id_order' => $order->id]);

        $this->assertIsArray($result);
        $this->assertSame((int) $order->id, $result['id']);
    }

    public function testOrderCarriesCreatedDate()
    {
        list($order) = $this->buildOrder('CREATEDTEST', '9788024271101');

        $result = (new Miguel())->createOrderDetailArray(['id_order' => $order->id]);

        $this->assertIsArray($result);
        $this->assertSame(date(DATE_ISO8601, strtotime($order->date_add)), $result['created_date']);
    }

    public function testStructureAddressReturnsNullForUnloadableAddress()
    {
        $this->assertNull(MiguelApiCreateOrderRequest::structureAddress(null));
        $this->assertNull(MiguelApiCreateOrderRequest::structureAddress(new Address(0)));
    }

    public function testStructureAddressReturnsExpectedKeys()
    {
        $structured = MiguelApiCreateOrderRequest::structureAddress(new Address(1));

        $this->assertIsArray($structured);
        foreach (['full_name', 'company', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'phone'] as $key) {
            $this->assertArrayHasKey($key, $structured);
        }
    }

    public function testStructureAddressMapsValues()
    {
        $countryId = (int) Configuration::get('PS_COUNTRY_DEFAULT');
        $expectedIso = (new Country($countryId))->iso_code;

        $address = new Address();
        $address->id_customer = 1;
        $address->id_country = $countryId;
        $address->alias = 'miguel-test';
        $address->lastname = 'Novak';
        $address->firstname = 'Jan';
        $address->address1 = 'Main St 1';
        $address->address2 = '';
        $address->city = 'Praha';
        $address->postcode = '11000';
        $address->phone = '';
        $address->phone_mobile = '+420999888777';
        $address->save();

        $structured = MiguelApiCreateOrderRequest::structureAddress(new Address($address->id));

        $this->assertSame('Jan Novak', $structured['full_name']);
        $this->assertSame('Main St 1', $structured['address1']);
        $this->assertSame('Praha', $structured['city']);
        $this->assertSame('11000', $structured['zip']);
        $this->assertSame($expectedIso, $structured['country']);   // ISO code, not name
        $this->assertSame('+420999888777', $structured['phone']);  // falls back to phone_mobile when phone is blank
        $this->assertNull($structured['company']);   // empty coerced to null
        $this->assertNull($structured['address2']);  // empty coerced to null
    }

    public function testOrderCarriesStructuredAddresses()
    {
        list($order) = $this->buildOrder('ADDRTEST', '9788024271101');

        $result = (new Miguel())->createOrderDetailArray(['id_order' => $order->id]);

        $this->assertIsArray($result['billing_address']);
        // Fixture sets id_address_delivery = 1, so shipping is present too.
        $this->assertIsArray($result['shipping_address']);
    }
}
