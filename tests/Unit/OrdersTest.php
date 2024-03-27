<?php
/**
 * 2024 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2024 Servantes
 *  @license LICENSE.txt
 */

namespace Tests\Unit;

use GuzzleHttp\Client;

final class OrdersTest extends \DatabaseTestCase
{
    private $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = new Client([
            'base_uri' => 'http://localhost:8000/modules/miguel/',
            'request.options' => [
                'exceptions' => false,
            ],
        ]);
    }

    public function testRequest()
    {
        // make a request to our API
        $response = $this->client->request('GET', 'orders.php');

        // check if the response is OK
        $this->assertEquals(200, $response->getStatusCode());
    }
}
