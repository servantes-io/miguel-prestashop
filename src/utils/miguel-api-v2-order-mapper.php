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
 * Maps the Miguel API v2 order list response into the internal array shape
 * consumed by views/templates/front/purchased.tpl, so the template does not
 * need to change. The list item (IOrderListItem) carries formats[] with
 * download URLs, so no detail endpoint is needed.
 */
class MiguelApiV2OrderMapper
{
    /**
     * @param array<string,mixed> $listResponse decoded GET /v2/orders page
     *
     * @return array<string,array<string,mixed>> orders keyed by their code
     */
    public static function indexByCode(array $listResponse)
    {
        if (!isset($listResponse['data']) || !is_array($listResponse['data'])) {
            return [];
        }

        $index = [];
        foreach ($listResponse['data'] as $order) {
            if (isset($order['code'])) {
                $index[$order['code']] = $order;
            }
        }

        return $index;
    }

    /**
     * @param array<string,mixed> $listResponse decoded GET /v2/orders page
     *
     * @return int|null next page index, or null on the last page
     */
    public static function nextPage(array $listResponse)
    {
        if (!isset($listResponse['meta']['nextPage'])) {
            return null;
        }

        return $listResponse['meta']['nextPage'];
    }

    /**
     * @param array<string,mixed> $order one IOrder from the list `data[]`
     * @param array<string,mixed> $orderMeta PrestaShop-side fields:
     *                                        id_order, reference, date_add, order_state
     *
     * @return array<int,array<string,mixed>> one purchased-page row per linked item
     */
    public static function mapOrderToBooks(array $order, array $orderMeta)
    {
        $rows = [];
        $items = isset($order['items']) && is_array($order['items']) ? $order['items'] : [];

        foreach ($items as $item) {
            if (empty($item['product'])) {
                // no linked Miguel product — skip (matches v1 "book" check)
                continue;
            }

            $formats = [];
            if (isset($item['formats']) && is_array($item['formats'])) {
                foreach ($item['formats'] as $format) {
                    $formats[] = [
                        'format' => $format['format'],
                        'download_url' => $format['downloadUrl'],
                    ];
                }
            }

            $title = isset($item['product']['product']['title'])
                ? $item['product']['product']['title']
                : '';

            $rows[] = [
                'id_order' => $orderMeta['id_order'],
                'reference' => $orderMeta['reference'],
                'date_add' => $orderMeta['date_add'],
                'order_state' => $orderMeta['order_state'],
                'paid' => !empty($order['paid']),
                'product' => [
                    'book' => ['title' => $title],
                    'formats' => $formats,
                ],
            ];
        }

        return $rows;
    }
}
