<?php
/**
 * Normalizes and validates the POST order-create body. The body intentionally
 * follows the WooCommerce plugin contract so Miguel can send the same frozen
 * item and delivery prices to either shop platform.
 */

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

class MiguelApiOutboundOrderRequest
{
    /**
     * @return array<string,mixed>
     */
    public static function fromPayload(array $payload)
    {
        $key = self::string($payload, 'idempotency_key', true);
        $currency = strtoupper(self::string($payload, 'currency', true));
        $email = self::string($payload, 'user_email', true);
        $billing = self::address($payload, 'billing');
        $shipping = isset($payload['shipping']) && is_array($payload['shipping'])
            ? self::address($payload, 'shipping') : $billing;

        if (!isset($payload['line_items']) || !is_array($payload['line_items']) || count($payload['line_items']) < 1) {
            throw new \InvalidArgumentException('line_items is required');
        }

        $items = [];
        foreach ($payload['line_items'] as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('line_items[' . $index . '] is invalid');
            }
            $quantity = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            if ($quantity < 1) {
                throw new \InvalidArgumentException('line_items[' . $index . '].quantity must be positive');
            }
            $items[] = [
                'product_code' => self::string($item, 'product_code', true, 'line_items[' . $index . '].product_code'),
                'quantity' => $quantity,
                'total' => self::money($item, 'total', 'line_items[' . $index . '].total'),
            ];
        }

        $shippingLines = [];
        if (isset($payload['shipping_lines'])) {
            if (!is_array($payload['shipping_lines'])) {
                throw new \InvalidArgumentException('shipping_lines is invalid');
            }
            foreach ($payload['shipping_lines'] as $index => $line) {
                if (!is_array($line) || !isset($line['method_id']) || !is_numeric($line['method_id'])) {
                    throw new \InvalidArgumentException('shipping_lines[' . $index . '].method_id is required');
                }
                $shippingLines[] = [
                    'method_id' => (int) $line['method_id'],
                    'method_title' => self::string($line, 'method_title', false),
                    // Older orders have a selected carrier but no frozen
                    // postage breakdown. Keep its total absent so PrestaShop
                    // calculates its normal carrier price instead of treating
                    // an invented zero as free shipping.
                    'total' => isset($line['total'])
                        ? self::money($line, 'total', 'shipping_lines[' . $index . '].total') : null,
                ];
            }
        }

        return [
            'idempotency_key' => $key,
            'currency' => $currency,
            'user_email' => $email,
            'order_note' => self::string($payload, 'order_note', false),
            'billing' => $billing,
            'shipping' => $shipping,
            'line_items' => $items,
            'shipping_lines' => $shippingLines,
        ];
    }

    private static function address(array $payload, $name)
    {
        if (!isset($payload[$name]) || !is_array($payload[$name])) {
            throw new \InvalidArgumentException($name . ' is required');
        }
        $address = $payload[$name];
        return [
            'first_name' => self::string($address, 'first_name', true, $name . '.first_name'),
            'last_name' => self::string($address, 'last_name', true, $name . '.last_name'),
            'company' => self::string($address, 'company', false),
            'address_1' => self::string($address, 'address_1', true, $name . '.address_1'),
            'address_2' => self::string($address, 'address_2', false),
            'city' => self::string($address, 'city', true, $name . '.city'),
            'state' => self::string($address, 'state', false),
            'postcode' => self::string($address, 'postcode', true, $name . '.postcode'),
            'country' => strtoupper(self::string($address, 'country', true, $name . '.country')),
            'phone' => self::string($address, 'phone', false),
        ];
    }

    private static function string(array $source, $key, $required, $label = null)
    {
        $label = $label ?: $key;
        if (!isset($source[$key]) || !is_string($source[$key]) || trim($source[$key]) === '') {
            if ($required) {
                throw new \InvalidArgumentException($label . ' is required');
            }
            return null;
        }
        return trim($source[$key]);
    }

    private static function money(array $source, $key, $label)
    {
        if (!isset($source[$key]) || (!is_string($source[$key]) && !is_int($source[$key]) && !is_float($source[$key]))
            || !is_numeric($source[$key])) {
            throw new \InvalidArgumentException($label . ' is required');
        }
        return number_format((float) $source[$key], 6, '.', '');
    }
}
