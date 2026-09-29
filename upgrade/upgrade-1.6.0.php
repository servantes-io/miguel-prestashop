<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_6_0($module)
{
    return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'miguel_outbound_order_payment` (
        `id_miguel_outbound_order_payment` int(11) NOT NULL AUTO_INCREMENT,
        `idempotency_key` varchar(128) NOT NULL,
        `payload_hash` varchar(64) NOT NULL,
        `id_order` int(11) NOT NULL,
        `finalized` tinyint(1) NOT NULL DEFAULT 0,
        `date_add` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id_miguel_outbound_order_payment`),
        UNIQUE KEY `miguel_outbound_order_payment_key` (`idempotency_key`)
    ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;');
}
