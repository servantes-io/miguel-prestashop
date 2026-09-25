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
 *  @author Pavel Vejnar <vejnar.p@gmail.com>
 *  @copyright  2022 - 2023 Servantes
 *  @license LICENSE.txt
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = [];

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'miguel` (
    `id_miguel` int(11) NOT NULL AUTO_INCREMENT,
    PRIMARY KEY  (`id_miguel`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'miguel_outbound_order` (
    `id_miguel_outbound_order` int(11) NOT NULL AUTO_INCREMENT,
    `idempotency_key` varchar(128) NOT NULL,
    `payload_hash` varchar(64) NULL,
    `id_cart` int(11) NOT NULL DEFAULT 0,
    `id_order` int(11) NOT NULL DEFAULT 0,
    `finalized` tinyint(1) NOT NULL DEFAULT 0,
    `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id_miguel_outbound_order`),
    UNIQUE KEY `miguel_outbound_order_key` (`idempotency_key`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

foreach ($sql as $query) {
    if (false == Db::getInstance()->execute($query)) {
        return false;
    }
}
