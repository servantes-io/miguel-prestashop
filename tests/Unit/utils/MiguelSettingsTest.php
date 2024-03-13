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

namespace Tests\Unit\utils;

require_once __DIR__ . '/../../../src/utils/miguel-settings.php';

final class MiguelSettingsTest extends \DatabaseTestCase
{
    public function testSaveValue()
    {
        // PREPARE
        $this->assertFalse(\Configuration::get(\MiguelSettings::API_ENABLE_KEY));

        // TEST
        \MiguelSettings::setEnabled(true);

        // VERIFY
        $this->assertTrue(\Configuration::get(\MiguelSettings::API_ENABLE_KEY));
    }
}
