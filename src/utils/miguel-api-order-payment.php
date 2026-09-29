<?php

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

/** Idempotently marks a Miguel-created PrestaShop order as paid. */
class MiguelApiOrderPayment
{
    public function markPaid(int $orderId, string $idempotencyKey): array
    {
        if ($orderId < 1) {
            throw new \InvalidArgumentException('order_id must be a positive integer');
        }
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('Idempotency-Key header is required');
        }

        $payloadHash = hash('sha256', json_encode(['order_id' => $orderId]));
        $existing = $this->find($idempotencyKey);
        if ($existing !== null) {
            if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                throw new \RuntimeException('An idempotency key was already used with a different payload.');
            }
            if ((int) $existing['finalized'] === 1) {
                return ['order_id' => (int) $existing['id_order'], 'paid' => true, 'idempotent_replay' => true];
            }
            throw new \RuntimeException('An order payment with this idempotency key is already being processed.');
        }

        $isMiguelOrder = (int) \Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'miguel_outbound_order` WHERE `id_order` = ' . $orderId
        ) > 0;
        if (!$isMiguelOrder) {
            throw new \InvalidArgumentException('Order is not a Miguel outbound order.');
        }

        if (!\Db::getInstance()->insert('miguel_outbound_order_payment', [
            'idempotency_key' => pSQL($idempotencyKey),
            'payload_hash' => pSQL($payloadHash),
            'id_order' => $orderId,
            'finalized' => 0,
        ])) {
            $existing = $this->find($idempotencyKey);
            if ($existing !== null && hash_equals((string) $existing['payload_hash'], $payloadHash)
                && (int) $existing['finalized'] === 1) {
                return ['order_id' => (int) $existing['id_order'], 'paid' => true, 'idempotent_replay' => true];
            }
            throw new \RuntimeException('Unable to reserve payment idempotency key.');
        }

        try {
            $order = new \Order($orderId);
            if (!\Validate::isLoadedObject($order)) {
                throw new \InvalidArgumentException('Order was not found.');
            }

            $paymentStateId = (int) \Configuration::get('PS_OS_PAYMENT');
            if ($paymentStateId < 1 || !\Validate::isLoadedObject(new \OrderState($paymentStateId))) {
                throw new \RuntimeException('PrestaShop payment-accepted order state is not configured.');
            }

            if ((int) $order->current_state !== $paymentStateId) {
                $history = new \OrderHistory();
                $history->id_order = $orderId;
                $history->changeIdOrderState($paymentStateId, $orderId);
                $updatedOrder = new \Order($orderId);
                if (!\Validate::isLoadedObject($updatedOrder)
                    || (int) $updatedOrder->current_state !== $paymentStateId) {
                    throw new \RuntimeException('Unable to change the order to the payment-accepted state.');
                }
            }

            if (!\Db::getInstance()->update('miguel_outbound_order_payment', ['finalized' => 1],
                '`idempotency_key` = "' . pSQL($idempotencyKey) . '" AND `id_order` = ' . $orderId)) {
                throw new \RuntimeException('Unable to finalize payment idempotency key.');
            }

            return ['order_id' => $orderId, 'paid' => true, 'idempotent_replay' => false];
        } catch (\Throwable $exception) {
            \Db::getInstance()->delete('miguel_outbound_order_payment',
                '`idempotency_key` = "' . pSQL($idempotencyKey) . '" AND `finalized` = 0');
            throw $exception;
        }
    }

    private function find(string $idempotencyKey): ?array
    {
        $row = \Db::getInstance()->getRow(
            'SELECT `id_order`, `payload_hash`, `finalized` FROM `' . _DB_PREFIX_
            . 'miguel_outbound_order_payment` WHERE `idempotency_key` = "' . pSQL($idempotencyKey) . '"'
        );
        return $row === false ? null : $row;
    }
}
