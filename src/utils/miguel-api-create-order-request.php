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

namespace Miguel\Utils;

use Address;
use Context;
use Pack;
use Product;
use Validate;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MiguelApiCreateOrderRequest
{
    /**
     * Create a product array structure for orders
     *
     * @param string $code Product reference code
     * @param float $regular_price Regular price without VAT
     * @param float $sold_price Sold price without VAT
     *
     * @return array Product array structure
     */
    public static function createProductArray(string $code, float $regular_price, float $sold_price)
    {
        return [
            'code' => $code,
            'price' => [
                'regular_without_vat' => $regular_price,
                'sold_without_vat' => $sold_price,
            ],
        ];
    }

    /**
     * Compose address string from address object
     *
     * @param Address $address_invoice Address object
     *
     * @return string Address string
     */
    public static function composeAddress(Address $address_invoice) {
        $address_str = '';
        $address_str .= ((strlen($address_invoice->company) > 0) ? ($address_invoice->company . ', ') : (''));
        $address_str .= ((strlen($address_invoice->firstname) > 0 && strlen($address_invoice->lastname) > 0) ? ($address_invoice->firstname . ' ' . $address_invoice->lastname . ', ') : (''));
        $address_str .= ((strlen($address_invoice->address1) > 0) ? ($address_invoice->address1 . ', ') : (''));
        $address_str .= ((strlen($address_invoice->address2) > 0) ? ($address_invoice->address2 . ', ') : (''));
        $address_str .= ((strlen($address_invoice->postcode) > 0) ? ($address_invoice->postcode . ', ') : (''));
        $address_str .= ((strlen($address_invoice->city) > 0) ? ($address_invoice->city . ', ') : (''));
        $address_str .= ((strlen($address_invoice->country) > 0) ? ($address_invoice->country . ', ') : (''));
        $address_str = substr($address_str, 0, -2);

        return $address_str;
    }

    /**
     * Create an array of products from order detail
     *
     * @param array $order_detail Order detail array
     *
     * @return array Products array
     */
    public static function createProductsArray(array $order_detail) {
        $products = [];

        foreach ($order_detail as $key => $product) {
            // Check if the product is a pack
            $product_id = (int) $product['product_id'];

            if (Pack::isPack($product_id)) {
                // Get pack items and add them individually
                $pack_items = Pack::getItems($product_id, (int) Context::getContext()->language->id);

                if (empty($pack_items)) {
                    $product_array = self::createArrayFromSimpleProduct($product);
                    if (false == $product_array) {
                        continue;
                    }
                    $products[] = $product_array;
                    continue;
                }

                // First, calculate total individual values for proportional distribution
                $total_individual_value = 0;
                $total_individual_regular_value = 0;
                $valid_pack_items = [];

                foreach ($pack_items as $pack_item) {
                    $pack_product = new Product($pack_item->id, false, (int) Context::getContext()->language->id);

                    if (Validate::isLoadedObject($pack_product) && !empty($pack_product->reference)) {
                        $individual_price = (float) $pack_product->getPrice(false);
                        $individual_regular_price = (float) $pack_product->getPrice(false, null, 6, null, false, false);
                        $item_quantity = (int) $pack_item->pack_quantity;

                        $item_total_value = $individual_price * $item_quantity;
                        $item_regular_total_value = $individual_regular_price * $item_quantity;

                        $total_individual_value += $item_total_value;
                        $total_individual_regular_value += $item_regular_total_value;

                        $valid_pack_items[] = [
                            'product' => $pack_product,
                            'quantity' => $item_quantity,
                            'individual_total' => $item_total_value,
                            'individual_regular_total' => $item_regular_total_value,
                        ];
                    }
                }

                // Now distribute pack price proportionally among valid items
                if ($total_individual_value > 0 && count($valid_pack_items) > 0) {
                    foreach ($valid_pack_items as $item_data) {
                        $proportion = $item_data['individual_total'] / $total_individual_value;
                        $regular_proportion = $total_individual_regular_value > 0 ?
                            $item_data['individual_regular_total'] / $total_individual_regular_value : $proportion;

                        $pack_item_unit_price = $product['unit_price_tax_excl'] * $proportion;
                        $pack_item_original_price = $product['original_product_price'] * $regular_proportion;

                        $products[] = self::createProductArray(
                            $item_data['product']->reference,
                            $pack_item_original_price,
                            $pack_item_unit_price
                        );
                    }
                }
            } else {
                $product_array = self::createArrayFromSimpleProduct($product);
                if (false == $product_array) {
                    continue;
                }
                $products[] = $product_array;
            }
        }

        return $products;
    }

    private static function createArrayFromSimpleProduct(array $product) {
        if (null == $product['product_reference'] || '' == $product['product_reference']) {
            // ignore products without reference
            return false;
        }

        return self::createProductArray(
            $product['product_reference'],
            $product['original_product_price'],
            $product['unit_price_tax_excl']
        );
    }
}
