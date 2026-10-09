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

use Miguel;
use Miguel\Utils\MiguelErrorReporter;
use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\DatabaseTestCase;
use Tests\Unit\Utility\MiguelStubServer;

class ErrorReportingCallsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::deleteByName(MiguelErrorReporter::BUFFER_KEY);
        \Configuration::deleteByName(MiguelErrorReporter::RETRY_AT_KEY);
        MiguelStubServer::reset();
    }

    private function reports()
    {
        return array_map(function ($entry) {
            return $entry['report'];
        }, MiguelErrorReporter::getBuffer());
    }

    public function testUnreachableMiguelIsReported()
    {
        MiguelSettings::save(MiguelSettings::API_SERVER_OWN_KEY, MiguelStubServer::unreachableUrl());

        $this->assertFalse((new Miguel())->curlPost('/v2/orders', ['code' => 'ABC']));

        $reports = $this->reports();
        $this->assertCount(1, $reports);
        $this->assertSame('MIGUEL_UNREACHABLE', $reports[0]['code']);
        $this->assertSame('POST /v2/orders', $reports[0]['operation']);
        $this->assertNotSame('MIGUEL_UNREACHABLE', $reports[0]['message']); // cURL's own words
        $this->assertArrayNotHasKey('httpStatus', $reports[0]);
    }

    public function testRejectedKeyIsReportedAsAuthRejected()
    {
        MiguelStubServer::respond(401, '{"title":"Unauthorized","status":401}');

        $this->assertFalse((new Miguel())->connectToMiguel());

        $reports = $this->reports();
        $this->assertCount(1, $reports);
        $this->assertSame('MIGUEL_AUTH_REJECTED', $reports[0]['code']);
        $this->assertSame('POST /v2/eshop/prestashop/connect', $reports[0]['operation']);
        $this->assertSame(401, $reports[0]['httpStatus']);
        $this->assertSame('{"title":"Unauthorized","status":401}', $reports[0]['responseExcerpt']);
    }

    public function testForbiddenIsReportedAsAuthRejected()
    {
        MiguelStubServer::respond(403);

        (new Miguel())->curlPost('/v2/orders', []);

        $this->assertSame('MIGUEL_AUTH_REJECTED', $this->reports()[0]['code']);
        $this->assertArrayNotHasKey('responseExcerpt', $this->reports()[0]);
    }

    public function testOtherHttpErrorsAreReportedAsHttpError()
    {
        MiguelStubServer::respond(500, 'boom');
        MiguelStubServer::respond(422, '{"title":"invalid order"}');

        (new Miguel())->curlPost('/v2/orders', []);
        (new Miguel())->curlPost('/v2/orders', []);

        $reports = $this->reports();
        $this->assertSame(['MIGUEL_HTTP_ERROR', 'MIGUEL_HTTP_ERROR'], array_column($reports, 'code'));
        $this->assertSame([500, 422], array_column($reports, 'httpStatus'));
    }

    public function testFailedGetIsReportedWithoutItsQueryString()
    {
        MiguelStubServer::respond(502, 'bad gateway');

        $this->assertFalse((new Miguel())->curlGet('/v2/orders?userEmail=' . rawurlencode('jane.doe@example.com') . '&limit=100&page=1'));

        $reports = $this->reports();
        $this->assertSame('MIGUEL_HTTP_ERROR', $reports[0]['code']);
        $this->assertSame('GET /v2/orders', $reports[0]['operation']);
        $this->assertStringNotContainsString('example.com', json_encode($reports));
        $this->assertStringNotContainsString('jane', json_encode($reports));
    }

    public function testCallsWhileTheModuleIsDisabledAreNotReported()
    {
        MiguelSettings::setEnabled(false);

        (new Miguel())->curlPost('/v2/orders', []);

        $this->assertSame([], MiguelErrorReporter::getBuffer());
        $this->assertSame([], MiguelStubServer::requests());
    }

    private function customerModule()
    {
        $module = new Miguel();
        // The demo customer (pub@prestashop.com), who has orders: getOrderedBooks() asks Miguel only then.
        $module->getContext()->customer = new \Customer(2);

        return $module;
    }

    public function testUnparsablePurchasedBooksListIsReported()
    {
        MiguelStubServer::respond(200, '<html>maintenance</html>');

        $this->customerModule()->getOrderedBooks();

        $reports = $this->reports();
        $this->assertCount(1, $reports);
        $this->assertSame('MIGUEL_RESPONSE_UNPARSABLE', $reports[0]['code']);
        $this->assertSame('GET /v2/orders', $reports[0]['operation']);
        $this->assertSame(200, $reports[0]['httpStatus']);
        $this->assertSame('<html>maintenance</html>', $reports[0]['responseExcerpt']);
        $this->assertStringNotContainsString('@', json_encode($reports));
    }

    public function testPurchasedBooksListWithoutDataIsReportedAsInvalid()
    {
        MiguelStubServer::respond(200, '{"items":[]}');

        $this->customerModule()->getOrderedBooks();

        $reports = $this->reports();
        $this->assertCount(1, $reports);
        $this->assertSame('MIGUEL_RESPONSE_INVALID', $reports[0]['code']);
        $this->assertSame('GET /v2/orders', $reports[0]['operation']);
    }

    public function testValidPurchasedBooksListReportsNothing()
    {
        MiguelStubServer::respond(200, '{"data":[],"meta":{}}');

        $this->customerModule()->getOrderedBooks();

        $this->assertSame([], MiguelErrorReporter::getBuffer());
    }

    public function testSuccessfulCallInTheBackOfficeFlushesTheBuffer()
    {
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'earlier');
        $module = new class extends Miguel {
            protected function isBackOfficeRequest()
            {
                return true;
            }
        };

        $module->connectToMiguel();

        $uris = array_column(MiguelStubServer::requests(), 'uri');
        $this->assertSame(['/v2/eshop/prestashop/connect', '/v2/eshop/errors'], $uris);
        $this->assertSame([], MiguelErrorReporter::getBuffer());
    }

    public function testSuccessfulCallInTheFrontOfficeDoesNotFlush()
    {
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'earlier');

        (new Miguel())->curlPost('/v2/orders', []); // e.g. the order hook during checkout

        $this->assertSame(['/v2/orders'], array_column(MiguelStubServer::requests(), 'uri'));
        $this->assertCount(1, MiguelErrorReporter::getBuffer());
    }

    public function testFailedCallInTheBackOfficeDoesNotFlush()
    {
        MiguelStubServer::respond(500);
        $module = new class extends Miguel {
            protected function isBackOfficeRequest()
            {
                return true;
            }
        };

        $module->connectToMiguel();

        $this->assertCount(1, MiguelStubServer::requests());
        $this->assertCount(1, MiguelErrorReporter::getBuffer());
    }

    private function orderWithMiguelProduct()
    {
        $product = new \Product();
        $product->name = 'Miguel e-book';
        $product->reference = 'MIGUEL-EBOOK-1';
        $product->save();

        $order = $this->entityCreator->createOrder();
        $order->reference = 'SYNCFAIL';
        $order->save();
        $this->entityCreator->createOrderDetail($order, $product)->save();

        return $order;
    }

    public function testOrderSyncFailureDoesNotEscapeTheHookAndIsReported()
    {
        MiguelSettings::setEnabled(true);
        $order = $this->orderWithMiguelProduct();
        $module = new class extends Miguel {
            public function curlPost($uri, array $params, $timeout = 0)
            {
                throw new \RuntimeException('sync exploded');
            }
        };

        $module->hookActionOrderStatusUpdate(['id_order' => $order->id, 'newOrderStatus' => (object) ['paid' => true]]);

        $reports = $this->reports();
        $this->assertCount(1, $reports);
        $this->assertSame('ORDER_SYNC_FAILED', $reports[0]['code']);
        $this->assertSame('sync exploded', $reports[0]['message']);
        $this->assertSame(['orderId' => (string) $order->id], $reports[0]['context']);
    }

    public function testProductExportFailureIsReported()
    {
        $module = new class extends Miguel {
            public function __construct()
            {
                parent::__construct();
                $this->context = new class {
                    public function __set($name, $value)
                    {
                        throw new \RuntimeException('no shop context');
                    }
                };
            }
        };

        $result = $module->getAllProducts();

        $this->assertSame(['error' => 'no shop context'], $result);
        $reports = $this->reports();
        $this->assertCount(1, $reports);
        $this->assertSame('PRODUCT_EXPORT_FAILED', $reports[0]['code']);
        $this->assertSame('no shop context', $reports[0]['message']);
    }

    public function testCronHookFlushes()
    {
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'earlier');

        (new Miguel())->hookActionCronJob();

        $this->assertSame(['/v2/eshop/errors'], array_column(MiguelStubServer::requests(), 'uri'));
        $this->assertSame([], MiguelErrorReporter::getBuffer());
    }

    public function testCronHookIsRegisteredHourly()
    {
        $this->assertContains('actionCronJob', Miguel::HOOKS);
        $this->assertSame(
            ['hour' => -1, 'day' => -1, 'month' => -1, 'day_of_week' => -1],
            (new Miguel())->getCronFrequency()
        );
    }

    public function testUpgradeRegistersTheCronHook()
    {
        require_once __DIR__ . '/../../upgrade/upgrade-1.5.0.php';
        $module = new class extends Miguel {
            public $registered = [];

            public function registerHook($hook_name, $shop_list = null)
            {
                $this->registered[] = $hook_name;

                return true;
            }
        };

        $this->assertTrue(upgrade_module_1_5_0($module));
        $this->assertSame(['actionCronJob'], $module->registered);
    }

    public function testUpgradeSucceedsWhenRegisteringThrows()
    {
        require_once __DIR__ . '/../../upgrade/upgrade-1.5.0.php';
        $module = new class extends Miguel {
            public function registerHook($hook_name, $shop_list = null)
            {
                throw new \RuntimeException('db down');
            }
        };

        $this->assertTrue(upgrade_module_1_5_0($module));
    }
}
