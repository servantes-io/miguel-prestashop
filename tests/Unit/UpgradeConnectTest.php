<?php
/**
 * 2024 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2024 Servantes
 *  @license LICENSE.txt
 */

namespace Tests\Unit;

use Miguel;
use Tests\Unit\Utility\DatabaseTestCase;

class UpgradeConnectTest extends DatabaseTestCase
{
    public function testUpgradeCallsConnectWithTimeoutAndReturnsTrue()
    {
        require_once __DIR__ . '/../../upgrade/upgrade-1.4.0.php';

        $module = new class extends Miguel {
            public $connectCalledWith = 'not-called';

            public function connectToMiguel($timeout = 0)
            {
                $this->connectCalledWith = $timeout;

                return 'ok';
            }
        };

        $result = upgrade_module_1_4_0($module);

        $this->assertTrue($result);
        $this->assertSame(Miguel::CONNECT_TIMEOUT, $module->connectCalledWith);
    }

    public function testUpgradeSucceedsEvenWhenConnectFails()
    {
        require_once __DIR__ . '/../../upgrade/upgrade-1.4.0.php';

        $module = new class extends Miguel {
            public function connectToMiguel($timeout = 0)
            {
                return false; // Miguel unreachable / API not configured
            }
        };

        $this->assertTrue(upgrade_module_1_4_0($module));
    }
}
