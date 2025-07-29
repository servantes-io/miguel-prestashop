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
    public static function createProductArray($code, $regular_price, $sold_price)
    {
        return [
            'code' => $code,
            'price' => [
                'regular_without_vat' => $regular_price,
                'sold_without_vat' => $sold_price,
            ],
        ];
    }
}
