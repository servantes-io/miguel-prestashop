<?php

namespace Tests\Unit\Utils;

use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\DatabaseTestCase;

final class MiguelSettingsTest extends DatabaseTestCase
{
    public function testSaveValue()
    {
        // PREPARE
        $this->assertFalse(\Configuration::get(MiguelSettings::API_ENABLE_KEY));

        // TEST
        MiguelSettings::setEnabled(true);

        // VERIFY
        $this->assertTrue(\Configuration::get(MiguelSettings::API_ENABLE_KEY));
    }
}
