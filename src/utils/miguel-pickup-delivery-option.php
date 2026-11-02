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

/** Preserve the carrier chosen for a pickup-point order in native checkout. */
class MiguelPickupDeliveryOption
{
    public static function apply(\Cart $cart, $addressId, $carrierId)
    {
        $key = (int) $carrierId . ',';
        $options = $cart->getDeliveryOptionList(null, true);
        if (!isset($options[(int) $addressId][$key])) {
            throw new \InvalidArgumentException('The selected carrier is not available for this cart and address.');
        }
        $cart->setDeliveryOption([(int) $addressId => $key]);
        if (!$cart->update()) {
            throw new \RuntimeException('Unable to persist the selected carrier.');
        }
    }
}
