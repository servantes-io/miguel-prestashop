<?php

namespace Tests\Unit;

use Miguel;
use Miguel\Utils\MiguelApiDispatcher;
use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\DatabaseTestCase;

class ApiDispatcherTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);
        parent::tearDown();
    }

    private function dispatcher(): MiguelApiDispatcher
    {
        return new MiguelApiDispatcher(new Miguel());
    }

    public function testUnknownResourceReturnsResourceNotFound()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('widgets', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('resource.not_found', $response->getData()->getCode());
    }

    public function testWrongMethodReturnsMethodNotAllowed()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('order-state-callback', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('method.not_allowed', $response->getData()->getCode());
    }

    public function testOrdersWithoutUpdatedSinceReturnsArgumentNotSet()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('orders', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('argument.not_set', $response->getData()->getCode());
    }

    public function testInvalidTokenReturnsApiKeyInvalid()
    {
        $_SERVER['Authorization'] = 'Bearer wrong';

        $response = $this->dispatcher()->dispatch('products', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('api_key.invalid', $response->getData()->getCode());
    }

    public function testProductsSucceedWithCustomHeaderToken()
    {
        $_SERVER['HTTP_X_MIGUEL_TOKEN'] = '1234';

        $response = $this->dispatcher()->dispatch('products', 'GET', [], '');

        $this->assertTrue($response->getResult());
        $this->assertSame('products', $response->getDataKey());
        $this->assertIsArray($response->getData());
    }

    public function testOrderStateCallbackWithEmptyBodyReturnsPayloadInvalid()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('order-state-callback', 'POST', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('payload.invalid', $response->getData()->getCode());
        $this->assertSame('Invalid payload: payload is required', $response->getData()->getMessage());
    }

    public function testOrderStateCallbackWithoutCodeReturnsPayloadInvalid()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('order-state-callback', 'POST', [], json_encode(['miguel_state' => 'x']));

        $this->assertFalse($response->getResult());
        $this->assertSame('payload.invalid', $response->getData()->getCode());
        $this->assertSame('Invalid payload: code not set', $response->getData()->getMessage());
    }
}
