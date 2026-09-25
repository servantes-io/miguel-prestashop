<?php

namespace Tests\Unit;

use Db;
use Tests\Unit\Utility\DatabaseTestCase;

class InstallSqlTest extends DatabaseTestCase
{
    public function testFreshInstallCreatesOutboundOrderTableWithoutDuplicateColumnMigration()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'miguel_outbound_order`');

        $result = include dirname(__DIR__, 2) . '/src/sql/install.php';

        $this->assertNotFalse($result);
        $columns = Db::getInstance()->executeS(
            'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'miguel_outbound_order`'
        );
        $names = array_column($columns, 'Field');
        $this->assertContains('payload_hash', $names);
        $this->assertContains('id_cart', $names);
        $this->assertContains('finalized', $names);
    }
}
