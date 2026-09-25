<?php
/** Native, unpaid PrestaShop order creation for orders placed in Miguel apps. */

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MiguelApiOutboundOrderCreator
{
    /** @var \Miguel */
    private $module;

    public function __construct(\Miguel $module)
    {
        $this->module = $module;
    }

    /**
     * @return array{order_id:int,idempotent_replay:bool}
     */
    public function create(array $request)
    {
        $this->assertSingleCarrier($request);
        $payloadHash = $this->payloadHash($request);
        $existing = $this->findIdempotentOrder($request['idempotency_key']);
        if ($existing !== null) {
            $storedHash = (string) ($existing['payload_hash'] ?? '');
            if ($storedHash !== '' && !hash_equals($storedHash, $payloadHash)) {
                throw new \RuntimeException('An idempotency key was already used with a different payload.');
            }
            if ((int) $existing['id_order'] > 0 && ((int) $existing['finalized'] === 1 || $storedHash === '')) {
                return ['order_id' => (int) $existing['id_order'], 'idempotent_replay' => true];
            }
            if ((int) $existing['id_order'] > 0) {
                $this->finalizeCreatedOrder((int) $existing['id_order'], $request);
                $this->completeIdempotencyKey($request['idempotency_key'], (int) $existing['id_order']);
                return ['order_id' => (int) $existing['id_order'], 'idempotent_replay' => true];
            }
            $recoveredOrderId = $this->findOrderByCart((int) ($existing['id_cart'] ?? 0));
            if ($recoveredOrderId > 0) {
                $this->storeCreatedOrder($request['idempotency_key'], $recoveredOrderId);
                $this->finalizeCreatedOrder($recoveredOrderId, $request);
                $this->completeIdempotencyKey($request['idempotency_key'], $recoveredOrderId);
                return ['order_id' => $recoveredOrderId, 'idempotent_replay' => true];
            }
            throw new \RuntimeException('An order with this idempotency key is already being created.');
        }

        $this->reserveIdempotencyKey($request['idempotency_key'], $payloadHash);
        $createdOrderId = 0;
        try {
            $currencyId = (int) \Currency::getIdByIsoCode($request['currency']);
            if ($currencyId < 1) {
                throw new \InvalidArgumentException('currency is not available in this shop');
            }

            $customer = $this->findOrCreateCustomer($request);
            $billing = $this->createAddress($customer, $request['billing']);
            $shipping = $this->sameAddress($request['billing'], $request['shipping'])
                ? $billing : $this->createAddress($customer, $request['shipping']);
            $cart = $this->createCart($customer, $billing, $shipping, $currencyId, $request);
            $this->storeCart($request['idempotency_key'], (int) $cart->id);

            $amount = $this->amount($request);
            $stateId = (int) \Configuration::get('PS_OS_BANKWIRE');
            if ($stateId < 1) {
                throw new \RuntimeException('PS_OS_BANKWIRE is not configured.');
            }

            $context = $this->module->getContext();
            $context->cart = $cart;
            $context->customer = $customer;
            $context->currency = new \Currency($currencyId);

            $this->module->validateOrder(
                (int) $cart->id,
                $stateId,
                $amount,
                'Miguel',
                $request['order_note'],
                [],
                null,
                false,
                $customer->secure_key
            );

            $orderId = (int) $this->module->currentOrder;
            if ($orderId < 1) {
                throw new \RuntimeException('PrestaShop did not return an order id.');
            }

            $createdOrderId = $orderId;
            $this->storeCreatedOrder($request['idempotency_key'], $orderId);
            $this->finalizeCreatedOrder($orderId, $request);
            $this->completeIdempotencyKey($request['idempotency_key'], $orderId);
            return ['order_id' => $orderId, 'idempotent_replay' => false];
        } catch (\Exception $exception) {
            if ($createdOrderId < 1) {
                $this->releaseIdempotencyKey($request['idempotency_key']);
            }
            throw $exception;
        }
    }

    private function assertSingleCarrier(array $request)
    {
        $carrierIds = [];
        foreach ($request['shipping_lines'] as $line) {
            $carrierIds[(string) $line['method_id']] = true;
        }
        if (count($carrierIds) > 1) {
            throw new \InvalidArgumentException('Multiple shipping carriers are not supported for one PrestaShop order.');
        }
    }

    private function payloadHash(array $request)
    {
        return hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function findOrCreateCustomer(array $request)
    {
        $id = (int) \Customer::customerExists($request['user_email'], true, false);
        if ($id > 0) {
            return new \Customer($id);
        }

        $customer = new \Customer();
        $customer->firstname = $request['billing']['first_name'];
        $customer->lastname = $request['billing']['last_name'];
        $customer->email = $request['user_email'];
        $customer->is_guest = 1;
        $customer->id_default_group = (int) \Configuration::get('PS_GUEST_GROUP');
        $customer->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $customer->passwd = \Tools::encrypt(\Tools::passwdGen(32));
        $customer->secure_key = md5(uniqid((string) mt_rand(), true));
        if (!$customer->add()) {
            throw new \RuntimeException('Unable to create customer.');
        }
        return $customer;
    }

    private function createAddress(\Customer $customer, array $data)
    {
        $countryId = (int) \Db::getInstance()->getValue(
            'SELECT `id_country` FROM `' . _DB_PREFIX_ . 'country` WHERE `iso_code` = "' . pSQL($data['country']) . '"'
        );
        if ($countryId < 1) {
            throw new \InvalidArgumentException('country ' . $data['country'] . ' is not available in this shop');
        }
        $address = new \Address();
        $address->id_customer = (int) $customer->id;
        $address->id_country = $countryId;
        $address->alias = 'Miguel ' . date('YmdHis');
        $address->firstname = $data['first_name'];
        $address->lastname = $data['last_name'];
        $address->company = $data['company'];
        $address->address1 = $data['address_1'];
        $address->address2 = $data['address_2'];
        $address->city = $data['city'];
        $address->postcode = $data['postcode'];
        $address->phone = $data['phone'];
        if (!$address->add()) {
            throw new \RuntimeException('Unable to create address.');
        }
        return $address;
    }

    private function createCart(\Customer $customer, \Address $billing, \Address $shipping, $currencyId, array $request)
    {
        $context = $this->module->getContext();
        $cart = new \Cart();
        $cart->id_customer = (int) $customer->id;
        $cart->id_address_invoice = (int) $billing->id;
        $cart->id_address_delivery = (int) $shipping->id;
        $cart->id_currency = (int) $currencyId;
        $cart->id_lang = (int) \Configuration::get('PS_LANG_DEFAULT');
        $cart->id_shop = (int) $context->shop->id;
        $cart->id_shop_group = (int) $context->shop->id_shop_group;
        $cart->secure_key = $customer->secure_key;

        if (count($request['shipping_lines']) > 0) {
            $carrierId = (int) $request['shipping_lines'][0]['method_id'];
            $carrier = new \Carrier($carrierId);
            if (!\Validate::isLoadedObject($carrier) || !(bool) $carrier->active || (bool) $carrier->deleted) {
                throw new \InvalidArgumentException('shipping_lines[0].method_id is not an active carrier');
            }
            $cart->id_carrier = $carrierId;
        }
        if (!$cart->add()) {
            throw new \RuntimeException('Unable to create cart.');
        }

        // Cart::updateQty() reads Context::cart in PrestaShop 1.7 instead of
        // using only its receiver, so publish the freshly persisted cart first.
        $context->cart = $cart;

        foreach ($request['line_items'] as $item) {
            $product = $this->findProduct($item['product_code']);
            $this->freezeProductPrice($cart, $product, $item);
            if (!$cart->updateQty((int) $item['quantity'], $product['id_product'], $product['id_product_attribute'])) {
                throw new \RuntimeException('Unable to add product ' . $item['product_code'] . ' to cart.');
            }
        }
        return $cart;
    }

    /**
     * The price from Miguel is the customer-facing (tax-included) price. A
     * PrestaShop SpecificPrice is necessarily tax-excluded, therefore use its
     * own tax calculator only to represent the same final price internally.
     */
    private function freezeProductPrice(\Cart $cart, array $product, array $item)
    {
        $address = new \Address((int) $cart->id_address_delivery);
        $calculator = \TaxManagerFactory::getManager($address, (int) $product['id_tax_rules_group'])
            ->getTaxCalculator();
        $unitPrice = $calculator->removeTaxes((float) $item['total'] / (int) $item['quantity']);
        if (!\Db::getInstance()->insert('specific_price', [
            'id_specific_price_rule' => 0,
            'id_cart' => (int) $cart->id,
            'id_product' => $product['id_product'],
            'id_shop' => (int) $cart->id_shop,
            'id_shop_group' => 0,
            'id_currency' => 0,
            'id_country' => 0,
            'id_group' => 0,
            'id_customer' => 0,
            'id_product_attribute' => $product['id_product_attribute'],
            'price' => number_format($unitPrice, 6, '.', ''),
            'from_quantity' => 1,
            'reduction' => 0,
            'reduction_tax' => 1,
            'reduction_type' => 'amount',
            'from' => '0000-00-00 00:00:00',
            'to' => '0000-00-00 00:00:00',
        ])) {
            throw new \RuntimeException('Unable to freeze product price.');
        }
    }

    private function findProduct($reference)
    {
        $db = \Db::getInstance();
        $escaped = pSQL($reference);
        // Db::getRow/getValue append their own LIMIT 1 on PrestaShop 1.7.
        $attribute = $db->getRow('SELECT pa.`id_product`, pa.`id_product_attribute`, p.`id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'product_attribute` pa INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.`id_product` = pa.`id_product` WHERE pa.`reference` = "' . $escaped . '"');
        if (is_array($attribute) && (int) $attribute['id_product'] > 0) {
            return [
                'id_product' => (int) $attribute['id_product'],
                'id_product_attribute' => (int) $attribute['id_product_attribute'],
                'id_tax_rules_group' => (int) $attribute['id_tax_rules_group'],
            ];
        }
        $id = (int) $db->getValue('SELECT `id_product` FROM `' . _DB_PREFIX_ . 'product` WHERE `reference` = "' . $escaped . '"');
        if ($id < 1) {
            throw new \InvalidArgumentException('product ' . $reference . ' was not found');
        }
        $taxRulesGroupId = (int) $db->getValue(
            'SELECT `id_tax_rules_group` FROM `' . _DB_PREFIX_ . 'product` WHERE `id_product` = ' . $id
        );
        return ['id_product' => $id, 'id_product_attribute' => 0, 'id_tax_rules_group' => $taxRulesGroupId];
    }

    private function amount(array $request)
    {
        $amount = 0.0;
        foreach ($request['line_items'] as $item) {
            $amount += (float) $item['total'];
        }
        foreach ($request['shipping_lines'] as $line) {
            if ($line['total'] !== null) {
                $amount += (float) $line['total'];
            }
        }
        return number_format($amount, 6, '.', '');
    }

    private function finalizeCreatedOrder($orderId, array $request)
    {
        $this->freezeShippingPrice($orderId, $request);
    }

    private function freezeShippingPrice($orderId, array $request)
    {
        $shippingIncl = 0.0;
        $hasShippingPrice = false;
        foreach ($request['shipping_lines'] as $candidate) {
            if ($candidate['total'] !== null) {
                $shippingIncl += (float) $candidate['total'];
                $hasShippingPrice = true;
            }
        }
        if (!$hasShippingPrice) {
            return;
        }

        $order = new \Order((int) $orderId);
        $address = new \Address((int) $order->id_address_delivery);
        $carrier = new \Carrier((int) $order->id_carrier);
        // Order::total_shipping_tax_excl is validated by PrestaShop's ObjectModel
        // as a price string. Tax removal can produce a repeating decimal, so
        // normalize both totals before assigning them to the Order object.
        $shippingIncl = (float) number_format($shippingIncl, 6, '.', '');
        $shippingExcl = (float) number_format(
            $carrier->getTaxCalculator($address)->removeTaxes($shippingIncl),
            6,
            '.',
            ''
        );
        $differenceIncl = $shippingIncl - (float) $order->total_shipping_tax_incl;
        $differenceExcl = $shippingExcl - (float) $order->total_shipping_tax_excl;

        $order->total_shipping_tax_excl = number_format($shippingExcl, 6, '.', '');
        $order->total_shipping_tax_incl = number_format($shippingIncl, 6, '.', '');
        $order->total_shipping = number_format($shippingIncl, 6, '.', '');
        // PaymentModule::validateOrder() calculates these totals from the
        // native cart before the Miguel shipping amount is frozen. Keep all
        // tax-included/excluded totals in sync with the new shipping amount.
        // The order is unpaid, so total_paid_real must remain unchanged.
        $order->total_paid_tax_excl = number_format(
            (float) $order->total_paid_tax_excl + $differenceExcl,
            6,
            '.',
            ''
        );
        $order->total_paid_tax_incl = number_format(
            (float) $order->total_paid_tax_incl + $differenceIncl,
            6,
            '.',
            ''
        );
        $order->total_paid = $order->total_paid_tax_incl;
        if (!$order->update()) {
            throw new \RuntimeException('Unable to freeze shipping price.');
        }

        \Db::getInstance()->update('order_carrier', [
            'shipping_cost_tax_excl' => number_format($shippingExcl, 6, '.', ''),
            'shipping_cost_tax_incl' => number_format($shippingIncl, 6, '.', ''),
        ], '`id_order` = ' . (int) $orderId);
    }

    private function sameAddress(array $first, array $second)
    {
        return $first === $second;
    }

    private function findIdempotentOrder($key)
    {
        $row = \Db::getInstance()->getRow('SELECT `id_order`, `id_cart`, `payload_hash`, `finalized` FROM `' . _DB_PREFIX_ . 'miguel_outbound_order` WHERE `idempotency_key` = "' . pSQL($key) . '"');
        return $row === false ? null : $row;
    }

    private function reserveIdempotencyKey($key, $payloadHash)
    {
        if (!\Db::getInstance()->insert('miguel_outbound_order', [
            'idempotency_key' => pSQL($key), 'payload_hash' => pSQL($payloadHash), 'id_cart' => 0, 'id_order' => 0, 'finalized' => 0,
        ])) {
            $dbError = trim((string) \Db::getInstance()->getMsgError());
            throw new \RuntimeException(
                'Unable to reserve idempotency key.' . ($dbError !== '' ? ' Database error: ' . $dbError : '')
            );
        }
    }

    private function storeCart($key, $cartId)
    {
        if (!\Db::getInstance()->update('miguel_outbound_order', ['id_cart' => (int) $cartId], '`idempotency_key` = "' . pSQL($key) . '" AND `id_order` = 0')) {
            throw new \RuntimeException('Unable to store the created cart.');
        }
    }

    private function findOrderByCart($cartId)
    {
        if ($cartId < 1) {
            return 0;
        }

        return (int) \Db::getInstance()->getValue(
            'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . (int) $cartId
        );
    }

    private function storeCreatedOrder($key, $orderId)
    {
        if (!\Db::getInstance()->update('miguel_outbound_order', ['id_order' => (int) $orderId], '`idempotency_key` = "' . pSQL($key) . '" AND `id_order` = 0')) {
            throw new \RuntimeException('Unable to store the created order.');
        }
    }

    private function completeIdempotencyKey($key, $orderId)
    {
        if (!\Db::getInstance()->update('miguel_outbound_order', ['id_order' => (int) $orderId, 'finalized' => 1], '`idempotency_key` = "' . pSQL($key) . '"')) {
            throw new \RuntimeException('Unable to store the created order.');
        }
    }

    private function releaseIdempotencyKey($key)
    {
        \Db::getInstance()->delete('miguel_outbound_order', '`idempotency_key` = "' . pSQL($key) . '" AND `id_order` = 0');
    }
}
