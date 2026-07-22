<?php
/**
 * 2026 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2026 Servantes
 *  @license LICENSE.txt
 */

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds the Miguel API v2 `OrderCreate` payload for `POST /v2/orders`.
 *
 * Self-contained: it loads the order's related entities and reuses the shared
 * product/address helpers. It does NOT touch createOrderDetailArray(), which
 * keeps emitting the v1 shape for the inbound API.
 */
class MiguelApiV2OrderRequest
{
    /**
     * @param \Order $order the PrestaShop order
     * @param bool $paid whether the order counts as paid (drives purchasedAt)
     *
     * @return array<string,mixed>|null the OrderCreate body, or null when there
     *                                  are no sendable items
     */
    public static function build(\Order $order, bool $paid)
    {
        $customer = new \Customer($order->id_customer);
        $currency = new \Currency($order->id_currency);
        $language = new \Language($customer->id_lang);
        $invoiceAddress = new \Address((int) $order->id_address_invoice);
        $deliveryAddress = new \Address((int) $order->id_address_delivery);
        $orderDetail = \OrderDetail::getList($order->id);

        $items = [];
        foreach (MiguelApiCreateOrderRequest::createProductsArray($order, $orderDetail) as $item) {
            // createProductsArray() returns v1 item arrays (jsonSerialize output):
            // ['code', 'quantity', 'price' => ['regular_without_vat', 'sold_without_vat']].
            // v2 carries only the sold unit price; the regular price has no v2 field.
            $items[] = [
                'code' => $item['code'],
                'quantity' => $item['quantity'],
                'unitPrice' => [
                    'withoutVat' => $item['price']['sold_without_vat'],
                ],
            ];
        }

        if (count($items) < 1) {
            return null;
        }

        $createdAt = date(DATE_ISO8601, strtotime($order->date_add));

        return [
            'code' => $order->reference,
            'user' => [
                'id' => (string) $order->id_customer,
                'name' => $customer->firstname . ' ' . $customer->lastname,
                'address' => MiguelApiCreateOrderRequest::composeAddress($invoiceAddress),
                'email' => $customer->email,
                'language' => $language->iso_code,
            ],
            'currencyCode' => $currency->iso_code,
            'purchasedAt' => $paid ? $createdAt : null,
            'eshopId' => (string) $order->id,
            'eshopCreatedAt' => $createdAt,
            'eshopUpdatedAt' => date(DATE_ISO8601, strtotime($order->date_upd)),
            'billingAddress' => self::toV2Address(MiguelApiCreateOrderRequest::structureAddress($invoiceAddress)),
            'shippingAddress' => self::toV2Address(MiguelApiCreateOrderRequest::structureAddress($deliveryAddress)),
            'source' => null,
            'socialDrmContent' => null,
            'items' => $items,
        ];
    }

    /**
     * Remap the v1 structured-address keys to v2 OrderAddressModel. Only
     * full_name differs (-> fullName); the rest are identical.
     *
     * @param array<string,mixed>|null $address
     *
     * @return array<string,mixed>|null
     */
    private static function toV2Address($address)
    {
        if (null === $address) {
            return null;
        }

        $address['fullName'] = $address['full_name'];
        unset($address['full_name']);

        return $address;
    }
}
