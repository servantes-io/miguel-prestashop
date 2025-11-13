<?php

namespace Tests\Unit;

use Miguel;
use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\DatabaseTestCase;

class OrderStateCallbackTest extends DatabaseTestCase
{
    private $previousErrorReportingSetting;

    protected function setUp(): void
    {
        parent::setUp();

        unset($_GET['updated_since']);
        unset($_SERVER['Authorization']);

        $this->previousErrorReportingSetting = error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);

        // Suppress output to console
        if (method_exists($this, 'setOutputCallback'))
        {
            $this->setOutputCallback(function() {});
        }
    }

    protected function tearDown(): void
    {
        error_reporting($this->previousErrorReportingSetting);

        parent::tearDown();
    }

    public function testWithoutPayload()
    {
        // PREPARE
        $_SERVER['Authorization'] = 'Bearer 1234';

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');

        // TEST
        $output = $this->sut();

        // ASSERT
        $expected_output = json_encode([
            'result' => false,
            'debug' => '',
            'error' => [
                "code" => "payload.invalid",
                "message" => "Invalid payload: payload is required"
            ],
        ]);
        $this->assertJsonStringEqualsJsonString($output, $expected_output);
    }

    public function testInvalidToken()
    {
        // PREPARE
        $_SERVER['Authorization'] = 'Bearer 1234';

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '0000');

        // TEST
        $output = $this->sut();

        // ASSERT
        $expected_output = json_encode([
            'result' => false,
            'debug' => '',
            'error' => [
                'code' => 'api_key.invalid',
                'message' => 'API key invalid',
            ],
        ]);
        $this->assertJsonStringEqualsJsonString($output, $expected_output);
    }

    public function testActualData()
    {
        // PREPARE
        $_SERVER['Authorization'] = 'Bearer 1234';

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');

        // TEST
        $output = $this->sut([
            "code" => "1234",
            "miguel_state" => 'finished',
            "products" => [
                [
                    "formats" => [
                        [
                            "format" => "epub",
                        ],
                    ],
                ],
            ],
        ]);

        // ASSERT
        $json = json_decode($output, true);
        $this->assertIsArray($json['orders']);
    }

    private function sut($input = null): string
    {
        if ($input !== null)
        {
            $mockModule = $this->createMock(Miguel::class)
                // valid token
                ->method('validateApiAccess')
                ->willReturn(true)
                // mock payload
                ->method('readFileContent')
                ->willReturn(json_encode($input));

            Miguel::setSharedInstance($mockModule);
        }

        include __DIR__ . '/../../order-state-callback.php';
        return $this->getActualOutputForAssertion();
    }
}
