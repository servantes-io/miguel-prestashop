<?php

namespace Tests\Unit\Utility;

use Miguel\Utils\MiguelSettings;
use PHPUnit\Framework\TestCase;

class DatabaseTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        MiguelSettings::reset();
    }
}