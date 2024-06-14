<?php

use Miguel\Utils\MiguelSettings;
use PHPUnit\Framework\TestCase;
use \phpmock\phpunit\PHPMock;
use Tests\Unit\Utility\ContextMocker;

class OrderStateCallbackTest extends TestCase
{
    /**
     * @var ContextMocker
     */
    protected $contextMocker;

    private $previousErrorReportingSetting;

    protected function setUp(): void
    {
        $this->contextMocker = new ContextMocker();
        $this->contextMocker->mockContext();

        MiguelSettings::reset();
        unset($_GET['updated_since']);
        unset($_SERVER['Authorization']);

        $this->previousErrorReportingSetting = error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);

        // Suppress output to console
        $this->setOutputCallback(function() {});

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->contextMocker->resetContext();

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
                'code' => 'argument.not_set',
                'message' => 'Argument updated_since not set',
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
            $input = json_encode($input);
            $fileGetContentsMock = $this->getFunctionMock(__NAMESPACE__, 'file_get_contents');
            $fileGetContentsMock->expects($this->once())
                ->with("php://input")
                ->willReturn($input);
        }

        include __DIR__ . '/../../order-state-callback.php';
        return $this->getActualOutput();
    }
}
