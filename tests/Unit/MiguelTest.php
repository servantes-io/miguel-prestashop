<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiResponse;
use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\DatabaseTestCase;

final class MiguelTest extends DatabaseTestCase
{
    private $sut;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sut = new \Miguel();
    }

    public function testCorrectClass()
    {
        $this->assertInstanceOf(\Miguel::class, $this->sut);
    }

    public function testMiguelWithoutAnyConfig()
    {
        // TEST
        $res = $this->sut->validateApiAccess();

        // VERIFY
        $this->assertInstanceOf(MiguelApiResponse::class, $res);

        $this->assertEquals(false, $res->getResult());

        $error = $res->getData();
        $this->assertEquals('api_key.not_set', $error->getCode());
        $this->assertEquals('API key not set', $error->getMessage());
    }

    public function testFullConfig()
    {
        // SETUP
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');
        MiguelSettings::setEnabled(true);

        $_SERVER['Authorization'] = 'Bearer 1234';

        // TEST
        $res = $this->sut->validateApiAccess();

        // VERIFY
        $this->assertTrue($res);
    }
}
