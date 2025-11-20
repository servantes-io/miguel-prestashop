<?php
/**
 * 2025 Servantes
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

class MiguelApiCreateOrderItem implements \JsonSerializable
{
    private $code;
    private $regular_price;
    private $sold_price;

    /**
     * Constructor
     *
     * @param string $code Product code
     * @param float $regular_price Regular price without VAT
     * @param float $sold_price Sold price without VAT
     */
    public function __construct(string $code, float $regular_price, float $sold_price)
    {
        $this->code = $code;
        $this->regular_price = $regular_price;
        $this->sold_price = $sold_price;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'code' => $this->code,
            'price' => [
                'regular_without_vat' => $this->regular_price,
                'sold_without_vat' => $this->sold_price,
            ],
        ];
    }

    /**
     * Get the product code
     *
     * @return string Product code
     */
    public function getCode()
    {
        return $this->code;
    }

    /**
     * Get the regular price without VAT
     *
     * @return float Regular price without VAT
     */
    public function getRegularPrice()
    {
        return $this->regular_price;
    }

    /**
     * Get the sold price without VAT
     *
     * @return float Sold price without VAT
     */
    public function getSoldPrice()
    {
        return $this->sold_price;
    }
}
