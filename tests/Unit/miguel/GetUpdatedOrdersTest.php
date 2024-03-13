<?php
/**
 * 2023 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 * @author    Pavel Vejnar <vejnar.p@gmail.com>
 * @copyright 2022 - 2023 Servantes
 * @license   LICENSE.txt
 */

namespace Tests\Unit\miguel;

use Configuration;
use PHPUnit\Framework\TestCase;
use Product;
use Tax;
use TaxRule;
use TaxRulesGroup;
use Tools;

require_once __DIR__ . '/../../../miguel.php';

final class GetUpdatedOrdersTest extends TestCase
{
    public function testGetUpdatedOrders()
    {
        $product = self::_makeProduct('Test product 1', 100, self::_getIdTaxRulesGroup(20));

        $miguel = new \Miguel();
        $this->assertIsArray($miguel->getUpdatedOrders('2022-01-01'));
    }

    /////// Helpers

    /**
     * This is cached by $rate.
     *
     * @param $rate is e.g. 5.5, 20...
     *
     * @return int
     */
    private static function _getIdTax(int $rate): int
    {
        static $taxes = [];

        $name = $rate . '% TAX';

        if (!array_key_exists($name, $taxes)) {
            $tax = new Tax(null, (int) Configuration::get('PS_LANG_DEFAULT'));
            $tax->name = $name;
            $tax->rate = $rate;
            $tax->active = true;
            self::assertTrue((bool) $tax->save()); // casting because actually returns 1, but not the point here.
            $taxes[$name] = $tax->id;
        }

        return $taxes[$name];
    }

    /**
     * This is cached by $rate.
     *
     * @param $rate is e.g. 5.5, 20...
     */
    private static function _getIdTaxRulesGroup(int $rate): int
    {
        static $groups = [];

        $name = $rate . '% TRG';

        if (!array_key_exists($name, $groups)) {
            $taxRulesGroup = new TaxRulesGroup(null, (int) Configuration::get('PS_LANG_DEFAULT'));
            $taxRulesGroup->name = $name;
            $taxRulesGroup->active = true;
            self::assertTrue((bool) $taxRulesGroup->save());

            $taxRule = new TaxRule(null, (int) Configuration::get('PS_LANG_DEFAULT'));
            $taxRule->id_tax = self::_getIdTax($rate);
            $taxRule->id_country = Configuration::get('PS_COUNTRY_DEFAULT');
            $taxRule->id_tax_rules_group = $taxRulesGroup->id;

            self::assertTrue($taxRule->save());

            $groups[$name] = $taxRulesGroup->id;
        }

        return (int) $groups[$name];
    }

    private static function _makeProduct(string $name, float $price, int $id_tax_rules_group): Product
    {
        $product = new Product(null, false, (int) Configuration::get('PS_LANG_DEFAULT'));
        $product->id_tax_rules_group = $id_tax_rules_group;
        $product->name = $name;
        $product->price = $price;
        $product->link_rewrite = Tools::str2url($name);
        self::assertTrue($product->save());

        return $product;
    }
}
