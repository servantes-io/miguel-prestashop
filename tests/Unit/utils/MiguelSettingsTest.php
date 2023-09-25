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

namespace Tests\Unit\utils;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

require_once __DIR__ . '/../../../src/utils/miguel-settings.php';

final class MiguelSettingsTest extends KernelTestCase
{
    public function testSaveValue()
    {
        $this->assertFalse(Configuration::get(MiguelSettings::API_ENABLE_KEY));

        MiguelSettings::setEnabled(true);

        $this->assertFalse(Configuration::get(MiguelSettings::API_ENABLE_KEY));
    }
}
