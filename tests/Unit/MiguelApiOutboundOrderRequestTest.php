<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Miguel\Utils\MiguelApiOutboundOrderRequest;
use PHPUnit\Framework\TestCase;

class MiguelApiOutboundOrderRequestTest extends TestCase
{
    public function testNormalizesTheWooCompatiblePayload()
    {
        $request = MiguelApiOutboundOrderRequest::fromPayload([
            'idempotency_key' => 'MIGUEL-ORDER-1',
            'currency' => 'CZK',
            'user_email' => 'buyer@example.test',
            'order_note' => 'Created in mobile app.',
            'billing' => [
                'first_name' => 'Jan Novak',
                'last_name' => 'Novak',
                'address_1' => 'Main 1',
                'city' => 'Prague',
                'postcode' => '11000',
                'country' => 'CZ',
            ],
            'shipping' => [
                'first_name' => 'Jan Novak',
                'last_name' => 'Novak',
                'address_1' => 'Main 2',
                'city' => 'Prague',
                'postcode' => '11000',
                'country' => 'CZ',
            ],
            'line_items' => [[
                'product_code' => '9788024271101',
                'quantity' => 2,
                'subtotal' => '199.00',
                'total' => '199.00',
            ]],
            'shipping_lines' => [[
                'method_id' => '3',
                'method_title' => 'PPL',
                'total' => '79.00',
            ]],
        ]);

        $this->assertSame('MIGUEL-ORDER-1', $request['idempotency_key']);
        $this->assertSame('CZK', $request['currency']);
        $this->assertSame('Novak', $request['billing']['last_name']);
        $this->assertSame(2, $request['line_items'][0]['quantity']);
        $this->assertSame('199.000000', $request['line_items'][0]['total']);
        $this->assertSame(3, $request['shipping_lines'][0]['method_id']);
    }

    public function testRejectsMissingIdempotencyKey()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency_key');

        MiguelApiOutboundOrderRequest::fromPayload($this->validPayload(['idempotency_key' => '']));
    }

    public function testRejectsLineItemWithoutAPrice()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('line_items[0].total');

        $payload = $this->validPayload();
        $payload['line_items'] = [['product_code' => 'SKU-1', 'quantity' => 1]];
        MiguelApiOutboundOrderRequest::fromPayload($payload);
    }

    public function testRejectsAddressWithoutLastName()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('billing.last_name');

        $payload = $this->validPayload();
        unset($payload['billing']['last_name']);
        MiguelApiOutboundOrderRequest::fromPayload($payload);
    }

    private function validPayload(array $replace = [])
    {
        return array_replace_recursive([
            'idempotency_key' => 'MIGUEL-ORDER-1',
            'currency' => 'CZK',
            'user_email' => 'buyer@example.test',
            'billing' => [
                'first_name' => 'Jan Novak',
                'last_name' => 'Novak',
                'address_1' => 'Main 1',
                'city' => 'Prague',
                'postcode' => '11000',
                'country' => 'CZ',
            ],
            'line_items' => [[
                'product_code' => 'SKU-1',
                'quantity' => 1,
                'total' => '199.00',
            ]],
            'shipping_lines' => [],
        ], $replace);
    }
}
