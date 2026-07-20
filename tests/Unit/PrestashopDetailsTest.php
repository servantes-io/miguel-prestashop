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

class PrestashopDetailsTest extends DatabaseTestCase
{
    public function testIncludesEndpointUrls()
    {
        $module = new Miguel();

        $details = $module->getPrestashopDetails();

        $this->assertArrayHasKey('endpoints', $details);
        $this->assertArrayHasKey('orders', $details['endpoints']);
        $this->assertArrayHasKey('products', $details['endpoints']);
        $this->assertArrayHasKey('orderStateCallback', $details['endpoints']);

        $this->assertStringContainsString('resource=orders', $details['endpoints']['orders']);
        $this->assertStringContainsString('resource=products', $details['endpoints']['products']);
        $this->assertStringContainsString('resource=order-state-callback', $details['endpoints']['orderStateCallback']);
    }

    public function testEndpointsUseIndexPhpDispatchForm()
    {
        $module = new Miguel();

        $details = $module->getPrestashopDetails();

        // Must always be the index.php dispatch form (works regardless of URL rewriting),
        // never the friendly /module/miguel/api form.
        foreach ($details['endpoints'] as $url) {
            $this->assertStringContainsString('index.php?fc=module&module=miguel&controller=api&resource=', $url);
            $this->assertStringNotContainsString('/module/miguel/api', $url);
        }
    }

    public function testEndpointsAreRelativeToBaseUri()
    {
        $module = new Miguel();

        $details = $module->getPrestashopDetails();

        // The links carry no scheme/host (that is reported separately in baseUrl)
        // and are relative to baseUri.
        foreach ($details['endpoints'] as $url) {
            $this->assertStringNotContainsString('://', $url);
            $this->assertStringStartsWith($details['baseUri'], $url);
        }
    }

    public function testKeepsLegacyBaseFields()
    {
        $module = new Miguel();

        $details = $module->getPrestashopDetails();

        $this->assertArrayHasKey('baseUrl', $details);
        $this->assertArrayHasKey('baseUri', $details);
        $this->assertArrayHasKey('moduleVersion', $details);
    }
}
