<?php

namespace Tests\Unit;

use Miguel;
use PHPUnit\Framework\TestCase;

class BearerTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);
        parent::tearDown();
    }

    public function testReadsBearerFromAuthorizationHeader()
    {
        $_SERVER['Authorization'] = 'Bearer abc123';

        $module = new Miguel();

        $this->assertSame('abc123', $module->getBearerToken());
    }

    public function testFallsBackToCustomHeader()
    {
        $_SERVER['HTTP_X_MIGUEL_TOKEN'] = 'abc123';

        $module = new Miguel();

        $this->assertSame('abc123', $module->getBearerToken());
    }

    public function testReturnsFalseWhenNoToken()
    {
        $module = new Miguel();

        $this->assertFalse($module->getBearerToken());
    }
}
