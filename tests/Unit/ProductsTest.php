<?php

use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\ContextMocker;
use Tests\Unit\Utility\DatabaseTestCase;

class ProductsTest extends DatabaseTestCase
{
    /**
     * @var ContextMocker
     */
    protected $contextMocker;

    private $previousErrorReportingSetting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contextMocker = new ContextMocker();
        $this->contextMocker->mockContext();

        MiguelSettings::reset();
        unset($_SERVER['Authorization']);

        $this->previousErrorReportingSetting = error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);

        // Suppress output to console
        $this->setOutputCallback(function() {});
    }

    protected function tearDown(): void
    {
        $this->contextMocker->resetContext();

        error_reporting($this->previousErrorReportingSetting);

        parent::tearDown();
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
        $output = $this->sut();

        // ASSERT
        $json = json_decode($output, true);
        $this->assertIsArray($json['products']);
    }

    private function sut(): string
    {
        include __DIR__ . '/../../products.php';
        return $this->getActualOutput();
    }
}
