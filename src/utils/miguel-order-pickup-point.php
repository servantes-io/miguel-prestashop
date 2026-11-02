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

/** Displays pickup information from the existing order message. */
class MiguelOrderPickupPoint
{
    public static function fromNote($note)
    {
        if (!preg_match('/^Pickup point \(([^\r\n]+)\): ([^\r\n]+)\r?\n([^\r\n]+)(?:\r?\n([^\r\n]*))?/mu', (string) $note, $match)) {
            return null;
        }

        return ['carrier' => $match[1], 'point_id' => $match[2], 'name' => $match[3], 'address' => $match[4] ?? ''];
    }

    public static function render($orderId)
    {
        $db = \Db::getInstance();
        $point = false;
        // validateOrder stores the original order note in ps_message.
        $messages = $db->executeS('SELECT message FROM `' . _DB_PREFIX_ . 'message` WHERE id_order = '
            . (int) $orderId . ' ORDER BY id_message DESC');
        foreach ($messages ?: [] as $message) {
            $point = self::fromNote($message['message']);
            if ($point) {
                break;
            }
        }
        if (!$point) {
            $point = self::fromNote($db->getValue('SELECT note FROM `' . _DB_PREFIX_ . 'orders` WHERE id_order = ' . (int) $orderId));
        }
        if (!$point) {
            return '';
        }
        $escape = function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };

        return '<style>.js-order-notes-block textarea{min-height:120px!important;height:120px!important;}'
            . '#miguel-order-pickup-point{margin-top:12px;margin-bottom:12px;}'
            . '#miguel-order-pickup-point .card-body{padding:16px!important;}'
            . '#miguel-order-pickup-point p{margin:8px 0 0;line-height:1.45;}</style>'
            . '<div id="miguel-order-pickup-point" class="card" style="margin:12px 0;"><div class="card-body" style="padding:16px;">'
            . '<h3 class="card-header-title">Výdejní místo</h3><p><strong>' . $escape($point['name'])
            . '</strong><br>' . $escape($point['address']) . '<br>' . $escape($point['carrier'])
            . ' · ID: ' . $escape($point['point_id']) . '</p></div></div>'
            . '<script>(function(){function place(){var block=document.getElementById("miguel-order-pickup-point");'
            . 'var target=document.querySelector("#orderShippingTabContent .card-body");'
            . 'if(block&&target&&block.parentNode!==target){target.appendChild(block);return true;}return false;}'
            . 'place();if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",place);}'
            . 'var tries=0, timer=setInterval(function(){if(place()||++tries>40){clearInterval(timer);}},250);'
            . 'if(window.MutationObserver){new MutationObserver(place).observe(document.body,{childList:true,subtree:true});}'
            . '})();</script>';
    }
}
