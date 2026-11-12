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
 * Cancels a Miguel-created PrestaShop order nobody paid: Miguel abandons a checkout the customer never finished.
 * Idempotent by the order's state; no e-mail goes to the customer, who never saw the order as placed.
 */
class MiguelApiOrderCancel
{
    public function cancel(int $orderId): array
    {
        if ($orderId < 1) {
            throw new \InvalidArgumentException('order_id must be a positive integer');
        }

        $isMiguelOrder = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'miguel_outbound_order` WHERE `id_order` = ' . $orderId
        ) > 0;
        if (!$isMiguelOrder) {
            throw new \InvalidArgumentException('Order is not a Miguel outbound order.');
        }

        $order = new \Order($orderId);
        if (!\Validate::isLoadedObject($order)) {
            throw new \InvalidArgumentException('Order was not found.');
        }

        $canceledStateId = (int) \Configuration::get('PS_OS_CANCELED');
        if ($canceledStateId < 1 || !\Validate::isLoadedObject(new \OrderState($canceledStateId))) {
            throw new \RuntimeException('PrestaShop canceled order state is not configured.');
        }
        if ((int) $order->current_state === $canceledStateId) {
            return ['order_id' => $orderId, 'cancelled' => true, 'idempotent_replay' => true];
        }
        // Read from the database, not Order::hasBeenPaid(): that reads a static history cache, which misses a payment
        // recorded earlier in the same request.
        $hasBeenPaid = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'order_history` oh INNER JOIN `' . _DB_PREFIX_ . 'order_state` os'
            . ' ON os.`id_order_state` = oh.`id_order_state` WHERE oh.`id_order` = ' . $orderId . ' AND os.`paid` = 1'
        ) > 0;
        if ($hasBeenPaid) {
            throw new \RuntimeException('A paid order is not cancelled from Miguel.');
        }

        $history = new \OrderHistory();
        $history->id_order = $orderId;
        $history->changeIdOrderState($canceledStateId, $orderId);
        // add(), not addWithemail(): the history entry for the admin timeline, without the cancellation e-mail.
        if (!$history->add()) {
            throw new \RuntimeException('Unable to record the canceled order history.');
        }
        $updatedOrder = new \Order($orderId);
        if (!\Validate::isLoadedObject($updatedOrder) || (int) $updatedOrder->current_state !== $canceledStateId) {
            throw new \RuntimeException('Unable to change the order to the canceled state.');
        }

        return ['order_id' => $orderId, 'cancelled' => true, 'idempotent_replay' => false];
    }
}
