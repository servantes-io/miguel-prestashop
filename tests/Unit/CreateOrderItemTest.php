<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiCreateOrderItem;
use Tests\Unit\Utility\DatabaseTestCase;

class CreateOrderItemTest extends DatabaseTestCase
{
    public function testJsonSerializeIncludesQuantity()
    {
        $item = new MiguelApiCreateOrderItem('9788024271101', 199.0, 149.0, 3);

        $json = $item->jsonSerialize();

        $this->assertSame(3, $json['quantity']);
        $this->assertSame('9788024271101', $json['code']);
        $this->assertSame(199.0, $json['price']['regular_without_vat']);
        $this->assertSame(149.0, $json['price']['sold_without_vat']);
    }

    public function testGetQuantity()
    {
        $item = new MiguelApiCreateOrderItem('X', 1.0, 1.0, 5);

        $this->assertSame(5, $item->getQuantity());
    }
}
