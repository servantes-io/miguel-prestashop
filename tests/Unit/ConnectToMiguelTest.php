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

class ConnectToMiguelTest extends DatabaseTestCase
{
    public function testPostsPrestashopDetailsToConnectEndpoint()
    {
        $module = new class extends Miguel {
            public $capturedUri;
            public $capturedParams;
            public $capturedTimeout;

            public function curlPost($uri, array $params, $timeout = 0)
            {
                $this->capturedUri = $uri;
                $this->capturedParams = $params;
                $this->capturedTimeout = $timeout;

                return 'connected';
            }
        };

        $result = $module->connectToMiguel(7);

        $this->assertSame('connected', $result);
        $this->assertSame('/v2/eshop/prestashop/connect', $module->capturedUri);
        $this->assertSame(7, $module->capturedTimeout);
        $this->assertEquals($module->getPrestashopDetails(), $module->capturedParams);
        $this->assertArrayHasKey('moduleVersion', $module->capturedParams);
        $this->assertArrayHasKey('endpoints', $module->capturedParams);
    }

    public function testDefaultsToNoTimeout()
    {
        $module = new class extends Miguel {
            public $capturedTimeout = 'unset';

            public function curlPost($uri, array $params, $timeout = 0)
            {
                $this->capturedTimeout = $timeout;

                return true;
            }
        };

        $module->connectToMiguel();

        $this->assertSame(0, $module->capturedTimeout);
    }
}
