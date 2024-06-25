<?php
/**
 * 2024 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2024 Servantes
 *  @license LICENSE.txt
 */

namespace Tests\Unit\Utility;

use Context;
use Order;
use OrderDetail;

class EntityCreator
{
    private $context;

    public function __construct()
    {
        $this->context = Context::getContext();
    }

    /**
     * Creates a new order (not saved in the database)
     *
     * @return Order
     */
    public function createOrder()
    {
        $order = new Order();
        $order->id_customer = 1;
        $order->id_address_delivery = 1;
        $order->id_address_invoice = 1;
        $order->id_cart = 1;
        $order->id_currency = 1;
        $order->id_lang = 1;
        $order->id_carrier = 1;
        $order->current_state = 1;
        $order->module = 'miguel';
        $order->payment = 'miguel';
        $order->total_paid = 1;
        $order->total_paid_real = 1;
        $order->total_products = 1;
        $order->total_products_wt = 1;
        $order->total_shipping = 1;
        $order->total_shipping_tax_incl = 1;
        $order->total_shipping_tax_excl = 1;
        $order->reference = '1234';
        $order->conversion_rate = 1;
        return $order;
    }

    /**
     * Creates a new order detail (not saved in the database)
     *
     * @param Order $order
     * @param Product $product
     * @return OrderDetail
     */
    public function createOrderDetail($order, $product)
    {
        $orderDetail = new OrderDetail();
        $orderDetail->id_order = $order->id;
        $orderDetail->product_id = $product->id;
        $orderDetail->product_name = $product->name;
        $orderDetail->product_reference = $product->reference;
        $orderDetail->product_quantity = 1;
        $orderDetail->product_quantity_in_stock = 1;
        $orderDetail->product_price = 1;
        $orderDetail->unit_price_tax_incl = 1;
        $orderDetail->unit_price_tax_excl = 1;
        $orderDetail->total_price_tax_incl = 1;
        $orderDetail->total_price_tax_excl = 1;
        $orderDetail->id_warehouse = 1;
        $orderDetail->id_shop = 1;
        $orderDetail->id_shop_list = 1;
        return $orderDetail;
    }
}
