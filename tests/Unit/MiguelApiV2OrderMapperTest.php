<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiV2OrderMapper;
use PHPUnit\Framework\TestCase;

class MiguelApiV2OrderMapperTest extends TestCase
{
    public function testIndexByCodeKeysOrdersByCode()
    {
        $list = ['data' => [['code' => 'A', 'paid' => true], ['code' => 'B', 'paid' => false]], 'meta' => []];

        $index = MiguelApiV2OrderMapper::indexByCode($list);

        $this->assertSame(['A', 'B'], array_keys($index));
        $this->assertTrue($index['A']['paid']);
    }

    public function testIndexByCodeEmptyWhenNoData()
    {
        $this->assertSame([], MiguelApiV2OrderMapper::indexByCode([]));
    }

    public function testNextPageReadsMeta()
    {
        $this->assertSame(2, MiguelApiV2OrderMapper::nextPage(['meta' => ['nextPage' => 2]]));
        $this->assertNull(MiguelApiV2OrderMapper::nextPage(['meta' => ['nextPage' => null]]));
        $this->assertNull(MiguelApiV2OrderMapper::nextPage([]));
    }

    public function testMapOrderToBooksBuildsTemplateRows()
    {
        $order = [
            'code' => 'REF7',
            'paid' => true,
            'items' => [
                [
                    'code' => 'BK1',
                    'product' => ['product' => ['title' => 'The Book']],
                    'formats' => [
                        ['format' => 'epub', 'downloadUrl' => 'https://x/epub'],
                        ['format' => 'pdf', 'downloadUrl' => 'https://x/pdf'],
                    ],
                ],
            ],
        ];
        $meta = ['id_order' => 7, 'reference' => 'REF7', 'date_add' => '2026-07-22', 'order_state' => 'Payment accepted'];

        $rows = MiguelApiV2OrderMapper::mapOrderToBooks($order, $meta);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(7, $row['id_order']);
        $this->assertSame('REF7', $row['reference']);
        $this->assertSame('2026-07-22', $row['date_add']);
        $this->assertSame('Payment accepted', $row['order_state']);
        $this->assertTrue($row['paid']);
        $this->assertSame('The Book', $row['product']['book']['title']);
        $this->assertSame('epub', $row['product']['formats'][0]['format']);
        $this->assertSame('https://x/epub', $row['product']['formats'][0]['download_url']);
        $this->assertSame('https://x/pdf', $row['product']['formats'][1]['download_url']);
    }

    public function testMapOrderSkipsItemsWithoutLinkedProduct()
    {
        $order = [
            'code' => 'R',
            'paid' => false,
            'items' => [
                ['code' => 'NOPROD', 'product' => null, 'formats' => []],
                ['code' => 'BK2', 'product' => ['product' => ['title' => 'Kept']], 'formats' => []],
            ],
        ];
        $meta = ['id_order' => 1, 'reference' => 'R', 'date_add' => 'd', 'order_state' => 's'];

        $rows = MiguelApiV2OrderMapper::mapOrderToBooks($order, $meta);

        $this->assertCount(1, $rows);
        $this->assertSame('Kept', $rows[0]['product']['book']['title']);
    }

    public function testMapOrderNullFormatsYieldsPreparingRow()
    {
        $order = [
            'code' => 'R',
            'paid' => true,
            'items' => [
                ['code' => 'BK3', 'product' => ['product' => ['title' => 'Preparing']], 'formats' => null],
            ],
        ];
        $meta = ['id_order' => 1, 'reference' => 'R', 'date_add' => 'd', 'order_state' => 's'];

        $rows = MiguelApiV2OrderMapper::mapOrderToBooks($order, $meta);

        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]['product']['formats']);
    }
}
