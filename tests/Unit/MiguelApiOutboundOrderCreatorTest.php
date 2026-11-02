<?php

namespace Tests\Unit;

use Miguel;
use Tests\Unit\Utility\DatabaseTestCase;

class MiguelApiOutboundOrderCreatorTest extends DatabaseTestCase
{
    private $countryIso;

    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
        $this->countryIso = (string) \Db::getInstance()->getValue(
            'SELECT `iso_code` FROM `' . _DB_PREFIX_ . 'country` WHERE `active` = 1 ORDER BY `id_country` ASC'
        );
        $this->assertNotSame('', $this->countryIso);

        \Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'miguel_outbound_order` (
            `id_miguel_outbound_order` int(11) NOT NULL AUTO_INCREMENT,
            `idempotency_key` varchar(128) NOT NULL,
            `payload_hash` varchar(64) NULL,
            `id_cart` int(11) NOT NULL DEFAULT 0,
            `id_order` int(11) NOT NULL DEFAULT 0,
            `finalized` tinyint(1) NOT NULL DEFAULT 0,
            `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_miguel_outbound_order`),
            UNIQUE KEY `miguel_outbound_order_key` (`idempotency_key`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8');
        \Db::getInstance()->execute('INSERT IGNORE INTO `' . _DB_PREFIX_ . 'module` (`name`, `active`, `version`) VALUES ("miguel", 1, "1.6.0")');
        \Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'miguel_outbound_order_payment` (
            `id_miguel_outbound_order_payment` int(11) NOT NULL AUTO_INCREMENT,
            `idempotency_key` varchar(128) NOT NULL,
            `payload_hash` varchar(64) NOT NULL,
            `id_order` int(11) NOT NULL,
            `finalized` tinyint(1) NOT NULL DEFAULT 0,
            `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_miguel_outbound_order_payment`),
            UNIQUE KEY `miguel_outbound_order_payment_key` (`idempotency_key`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8');
        \Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'packetery_order` (
            `id_order` int NOT NULL,
            `id_cart` int NOT NULL,
            `id_branch` int NULL,
            `name_branch` varchar(255) NULL,
            `currency_branch` char(3) NULL,
            `is_cod` tinyint(1) NOT NULL DEFAULT 0,
            `exported` tinyint(1) NOT NULL DEFAULT 0,
            `id_carrier` int DEFAULT 0,
            `is_ad` int DEFAULT 0,
            `is_carrier` tinyint(1) NOT NULL DEFAULT 0,
            `carrier_pickup_point` varchar(40) NULL,
            `country` varchar(2) NULL,
            `zip` varchar(255) NULL,
            `city` varchar(255) NULL,
            `street` varchar(255) NULL,
            `house_number` varchar(255) NULL,
            `latitude` varchar(255) NULL,
            `longitude` varchar(255) NULL,
            PRIMARY KEY (`id_order`),
            UNIQUE KEY `packetery_cart` (`id_cart`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8');
    }

    public function testCreatesUnpaidNativeOrderAndReplaysIdempotently()
    {
        $reference = 'OUTBOUND-' . uniqid();
        $product = new \Product();
        $product->name = 'Outbound book';
        $product->reference = $reference;
        $product->price = 90;
        $product->active = 1;
        $product->available_for_order = 1;
        $product->save();
        \StockAvailable::setQuantity((int) $product->id, 0, 10);

        $currency = \Currency::getCurrencyInstance((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $carrierId = (int) \Db::getInstance()->getValue(
            'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier` WHERE `active` = 1 AND `deleted` = 0'
        );
        $this->assertGreaterThan(0, $carrierId);
        $module = new \Miguel();
        $module->active = true;

        $payload = [
            'idempotency_key' => 'OUTBOUND-ORDER-' . $reference,
            'currency' => $currency->iso_code,
            'user_email' => strtolower($reference) . '@example.test',
            'billing' => [
                'first_name' => 'Jan', 'last_name' => 'Novak', 'address_1' => 'Main 1', 'city' => 'Prague',
                'postcode' => '11000', 'country' => $this->countryIso,
            ],
            'line_items' => [[
                'product_code' => $reference, 'quantity' => 1, 'total' => '360.00',
            ]],
            'shipping_lines' => [[
                'method_id' => (string) $carrierId, 'method_title' => 'Test carrier', 'total' => '199.00',
            ]],
        ];

        $created = $module->createOutboundOrder($payload);
        $replayed = $module->createOutboundOrder($payload);

        $this->assertTrue($created->getResult());
        $this->assertGreaterThan(0, $created->getData()['order_id']);
        $this->assertFalse($created->getData()['idempotent_replay']);
        $createdOrder = new \Order((int) $created->getData()['order_id']);
        $expectedCreatedAt = \DateTimeImmutable::createFromFormat(
            'Y-m-d H:i:s',
            $createdOrder->date_add,
            new \DateTimeZone(date_default_timezone_get())
        )->format('c');
        $this->assertSame($expectedCreatedAt, $created->getData()['created_at']);
        $detail = \OrderDetail::getList((int) $created->getData()['order_id'])[0];
        $this->assertSame(360.0, (float) $detail['unit_price_tax_excl']);
        $this->assertSame(199.0, (float) $createdOrder->total_shipping_tax_incl);
        $this->assertSame(559.0, (float) $createdOrder->total_paid_tax_incl);
        $this->assertSame(559.0, (float) $createdOrder->total_paid);
        $this->assertSame(0.0, (float) $createdOrder->total_paid_real);
        $this->assertTrue($replayed->getResult());
        $this->assertSame($created->getData()['order_id'], $replayed->getData()['order_id']);
        $this->assertTrue($replayed->getData()['idempotent_replay']);
        $this->assertSame($created->getData()['created_at'], $replayed->getData()['created_at']);
        $updatedOrders = $module->getUpdatedOrders('2000-01-01T00:00:00+00:00');
        $updatedOrderIds = array_map('intval', array_column($updatedOrders, 'id'));
        $this->assertNotContains((int) $created->getData()['order_id'], $updatedOrderIds);

        // A native order may exist even when the marker update was interrupted. The cart link
        // lets the next idempotent request recover and finalize it instead of getting stuck.
        $createdCartId = (int) (new \Order((int) $created->getData()['order_id']))->id_cart;
        \Db::getInstance()->update('miguel_outbound_order', [
            'id_cart' => $createdCartId,
            'id_order' => 0,
            'finalized' => 0,
        ], '`idempotency_key` = "' . pSQL($payload['idempotency_key']) . '"');
        $recovered = $module->createOutboundOrder($payload);
        $this->assertTrue($recovered->getResult());
        $this->assertTrue($recovered->getData()['idempotent_replay']);
        $this->assertSame($created->getData()['order_id'], $recovered->getData()['order_id']);
    }

    public function testCreatesPacketaOrderDataFromMiguelPickupPoint()
    {
        $reference = 'OUTBOUND-PACKETA-' . uniqid();
        $product = new \Product();
        $product->name = 'Outbound Packeta book';
        $product->reference = $reference;
        $product->price = 90;
        $product->active = 1;
        $product->available_for_order = 1;
        $product->save();
        \StockAvailable::setQuantity((int) $product->id, 0, 10);

        $currency = \Currency::getCurrencyInstance((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $carrierId = (int) \Db::getInstance()->getValue(
            'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier` WHERE `active` = 1 AND `deleted` = 0 ORDER BY `id_carrier` ASC'
        );
        if ($carrierId < 1) {
            $carrier = new \Carrier();
            $carrier->name = 'Zasilkovna mock';
            $carrier->active = 1;
            $carrier->deleted = 0;
            $carrier->shipping_handling = 0;
            $carrier->is_module = 0;
            $carrier->need_range = 0;
            $carrier->range_behavior = 0;
            $carrier->shipping_external = 0;
            $carrier->delay = [1 => '2-3 days'];
            $carrier->add();
            $carrierId = (int) $carrier->id;
        }
        $module = new \Miguel();
        $module->active = true;

        $response = $module->createOutboundOrder([
            'idempotency_key' => 'OUTBOUND-PACKETA-' . $reference,
            'currency' => $currency->iso_code,
            'user_email' => strtolower($reference) . '@example.test',
            'billing' => [
                'first_name' => 'Jan', 'last_name' => 'Novak', 'address_1' => 'Main 1', 'city' => 'Prague',
                'postcode' => '11000', 'country' => $this->countryIso,
            ],
            'line_items' => [[
                'product_code' => $reference, 'quantity' => 1, 'total' => '90.00',
            ]],
            'shipping_lines' => [[
                'method_id' => (string) $carrierId, 'method_title' => 'Zásilkovna', 'total' => '79.00',
            ]],
            'pickup_point' => [
                'carrier' => 'packeta', 'id' => '2317', 'name' => 'Golčův Jeníkov',
                'address' => 'Jiráskova 183, 582 82',
            ],
        ]);

        $this->assertTrue($response->getResult(), json_encode($response->getData()));
        $orderId = (int) $response->getData()['order_id'];
        $row = \Db::getInstance()->getRow('SELECT * FROM `' . _DB_PREFIX_ . 'packetery_order` WHERE `id_order` = ' . $orderId);
        $orderCarrierId = (int) \Db::getInstance()->getValue('SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_order` = ' . $orderId);
        $this->assertSame('2317', (string) $row['id_branch']);
        $this->assertSame('Golčův Jeníkov', $row['name_branch']);
        $this->assertSame($orderCarrierId, (int) $row['id_carrier']);
        $this->assertSame(0, (int) $row['is_carrier']);
        $this->assertSame(0, (int) $row['exported']);

        $orderHistoryBeforePayment = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = ' . $orderId
        );
        $payment = new Miguel\Utils\MiguelApiOrderPayment();
        $paid = $payment->markPaid($orderId, 'PAYMENT-PACKETA-' . $reference);
        $this->assertFalse($paid['idempotent_replay']);
        $this->assertSame(1, (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = ' . $orderId
            . ' AND `id_order_state` = ' . (int) \Configuration::get('PS_OS_PAYMENT')
        ));
        $this->assertSame($orderHistoryBeforePayment + 1, (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = ' . $orderId
        ));
        $this->assertSame('2317', (string) \Db::getInstance()->getValue(
            'SELECT `id_branch` FROM `' . _DB_PREFIX_ . 'packetery_order` WHERE `id_order` = ' . $orderId
        ));

        $replayedPayment = $payment->markPaid($orderId, 'PAYMENT-PACKETA-' . $reference);
        $this->assertTrue($replayedPayment['idempotent_replay']);
        $this->assertSame(1, (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history` WHERE `id_order` = ' . $orderId
            . ' AND `id_order_state` = ' . (int) \Configuration::get('PS_OS_PAYMENT')
        ));
    }

    public function testCreatesOrderUsingMiguelPrice()
    {
        $reference = 'OUTBOUND-MISMATCH-' . uniqid();
        $product = new \Product();
        $product->name = 'Outbound book';
        $product->reference = $reference;
        $product->price = 100;
        $product->active = 1;
        $product->available_for_order = 1;
        $product->save();
        \StockAvailable::setQuantity((int) $product->id, 0, 10);

        $currency = \Currency::getCurrencyInstance((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $module = new \Miguel();
        $module->active = true;
        $response = $module->createOutboundOrder([
            'idempotency_key' => 'OUTBOUND-ORDER-' . $reference,
            'currency' => $currency->iso_code,
            'user_email' => strtolower($reference) . '@example.test',
            'billing' => [
                'first_name' => 'Jan', 'last_name' => 'Novak', 'address_1' => 'Main 1', 'city' => 'Prague',
                'postcode' => '11000', 'country' => $this->countryIso,
            ],
            'line_items' => [[
                'product_code' => $reference, 'quantity' => 1, 'total' => '90.00',
            ]],
            'shipping_lines' => [],
        ]);

        $this->assertTrue($response->getResult());
        $this->assertGreaterThan(0, $response->getData()['order_id']);
        $detail = \OrderDetail::getList((int) $response->getData()['order_id'])[0];
        $this->assertSame(90.0, (float) $detail['unit_price_tax_excl']);
    }

    public function testRejectsSameIdempotencyKeyWithDifferentPayload()
    {
        $reference = 'OUTBOUND-HASH-' . uniqid();
        $product = new \Product();
        $product->name = 'Outbound hash book';
        $product->reference = $reference;
        $product->price = 100;
        $product->active = 1;
        $product->available_for_order = 1;
        $product->save();
        \StockAvailable::setQuantity((int) $product->id, 0, 10);

        $currency = \Currency::getCurrencyInstance((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $module = new \Miguel();
        $module->active = true;
        $payload = [
            'idempotency_key' => 'OUTBOUND-HASH-' . $reference,
            'currency' => $currency->iso_code,
            'user_email' => strtolower($reference) . '@example.test',
            'billing' => [
                'first_name' => 'Jan', 'last_name' => 'Novak', 'address_1' => 'Main 1', 'city' => 'Prague',
                'postcode' => '11000', 'country' => $this->countryIso,
            ],
            'line_items' => [[
                'product_code' => $reference, 'quantity' => 1, 'total' => '90.00',
            ]],
            'shipping_lines' => [],
        ];

        $module->createOutboundOrder($payload);
        $payload['line_items'][0]['total'] = '91.00';

        $response = $module->createOutboundOrder($payload);

        $this->assertFalse($response->getResult());
        $this->assertStringContainsString('different payload', $response->getData()->getMessage());
    }

    public function testRejectsMultipleShippingCarriers()
    {
        $reference = 'OUTBOUND-CARRIERS-' . uniqid();
        $product = new \Product();
        $product->name = 'Outbound carrier book';
        $product->reference = $reference;
        $product->price = 100;
        $product->active = 1;
        $product->available_for_order = 1;
        $product->save();
        \StockAvailable::setQuantity((int) $product->id, 0, 10);

        $currency = \Currency::getCurrencyInstance((int) \Configuration::get('PS_CURRENCY_DEFAULT'));
        $carrierIds = \Db::getInstance()->executeS(
            'SELECT `id_carrier` FROM `' . _DB_PREFIX_ . 'carrier` WHERE `active` = 1 AND `deleted` = 0 LIMIT 2'
        );
        $this->assertCount(2, $carrierIds);
        $module = new \Miguel();
        $module->active = true;
        $payload = [
            'idempotency_key' => 'OUTBOUND-CARRIERS-' . $reference,
            'currency' => $currency->iso_code,
            'user_email' => strtolower($reference) . '@example.test',
            'billing' => [
                'first_name' => 'Jan', 'last_name' => 'Novak', 'address_1' => 'Main 1', 'city' => 'Prague',
                'postcode' => '11000', 'country' => $this->countryIso,
            ],
            'line_items' => [[
                'product_code' => $reference, 'quantity' => 1, 'total' => '90.00',
            ]],
            'shipping_lines' => [
                ['method_id' => (string) $carrierIds[0]['id_carrier'], 'method_title' => 'Carrier 1', 'total' => '10.00'],
                ['method_id' => (string) $carrierIds[1]['id_carrier'], 'method_title' => 'Carrier 2', 'total' => '20.00'],
            ],
        ];

        $response = $module->createOutboundOrder($payload);

        $this->assertFalse($response->getResult());
        $this->assertStringContainsString('Multiple shipping carriers', $response->getData()->getMessage());
    }
}
