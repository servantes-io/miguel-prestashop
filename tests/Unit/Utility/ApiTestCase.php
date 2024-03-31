<?php

namespace Tests\Unit\Utility;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

abstract class ApiTestCase extends WebTestCase
{
    use ContextMockerTrait;

    /**
     * @var Client|null
     */
    protected static $client;

    /**
     * @var RouterInterface
     */
    protected $router;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::mockContext();
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$kernel = static::bootKernel();
        self::$client = self::$kernel->getContainer()->get('test.client');
        self::$client->setServerParameters([]);
        self::$container = self::$client->getContainer();
        $this->router = self::$container->get('router');
    }

    public function getContext(): \Context
    {
        return static::getContext();
    }

    /**
     * @param string $route
     * @param array $params
     */
    protected function assertBadRequest(string $route, array $params): void
    {
        $route = $this->router->generate($route, $params);
        self::$client->request('GET', $route);

        $response = self::$client->getResponse();
        $this->assertEquals(400, $response->getStatusCode(), 'It should return a response with "Bad Request" Status.');
    }

    /**
     * @param string $route
     * @param array $params
     */
    protected function assertOkRequest(string $route, array $params): void
    {
        $route = $this->router->generate($route, $params);
        self::$client->request('GET', $route);

        $response = self::$client->getResponse();
        $this->assertEquals(200, $response->getStatusCode(), 'It should return a response with "OK" Status.');
    }

    /**
     * @param int $expectedStatusCode
     *
     * @return array
     */
    protected function assertResponseBodyValidJson(int $expectedStatusCode): array
    {
        $response = self::$client->getResponse();

        $message = 'Unexpected status code.';

        switch ($expectedStatusCode) {
            case 200:
                $message = 'It should return a response with "OK" Status.';

                break;
            case 400:
                $message = 'It should return a response with "Bad Request" Status.';

                break;
            case 404:
                $message = 'It should return a response with "Not Found" Status.';

                break;

            default:
                $this->fail($message);
        }

        $this->assertEquals($expectedStatusCode, $response->getStatusCode(), $message);

        $content = json_decode($response->getContent(), true);

        $this->assertEquals(
            JSON_ERROR_NONE,
            json_last_error(),
            'The response body should be a valid json document.'
        );

        return $content;
    }
}
