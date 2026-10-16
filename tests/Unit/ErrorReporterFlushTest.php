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

use Miguel\Utils\MiguelErrorReporter;
use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\DatabaseTestCase;
use Tests\Unit\Utility\MiguelStubServer;

class ErrorReporterFlushTest extends DatabaseTestCase
{
    /**
     * @var \Miguel
     */
    private $module;

    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::deleteByName(MiguelErrorReporter::BUFFER_KEY);
        \Configuration::deleteByName(MiguelErrorReporter::RETRY_AT_KEY);
        MiguelStubServer::reset();
        $this->module = new \Miguel();
    }

    private function bufferReports($count, $prefix = 'report ')
    {
        for ($i = 1; $i <= $count; ++$i) {
            MiguelErrorReporter::report('ORDER_SYNC_FAILED', $prefix . $i);
        }
    }

    private function bufferedMessages()
    {
        return array_map(function ($entry) {
            return $entry['report']['message'];
        }, MiguelErrorReporter::getBuffer());
    }

    private function sentReports($request)
    {
        return json_decode($request['body'], true)['reports'];
    }

    /**
     * Write a Configuration row the way another request would: in the database, behind this request's cache.
     */
    private function writeRowBehindTheCache($key, $value)
    {
        $db = \Db::getInstance();
        $where = '`name` = \'' . pSQL($key) . '\' AND `id_shop` IS NULL AND `id_shop_group` IS NULL';
        if ($db->getValue('SELECT 1 FROM `' . _DB_PREFIX_ . 'configuration` WHERE ' . $where, false)) {
            $db->execute('UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = \'' . pSQL($value) . '\' WHERE ' . $where);
        } else {
            $db->execute('INSERT INTO `' . _DB_PREFIX_ . 'configuration` (`name`, `value`, `date_add`, `date_upd`) VALUES (\'' . pSQL($key) . '\', \'' . pSQL($value) . '\', NOW(), NOW())');
        }
    }

    private function storedRow()
    {
        $raw = \Db::getInstance()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL(MiguelErrorReporter::BUFFER_KEY) . '\'', false);

        return json_decode($raw, true);
    }

    public function testDoesNotResendWhatAnotherRequestAlreadySent()
    {
        $this->bufferReports(2);
        $this->writeRowBehindTheCache(MiguelErrorReporter::BUFFER_KEY, '[]'); // a cron flush got its 202

        MiguelErrorReporter::flush($this->module);

        $this->assertSame([], MiguelStubServer::requests());
    }

    public function testHonoursARetryDelayAnotherRequestSet()
    {
        $this->bufferReports(1);
        $this->writeRowBehindTheCache(MiguelErrorReporter::RETRY_AT_KEY, (string) (time() + 120)); // its flush got a 429

        MiguelErrorReporter::flush($this->module);

        $this->assertSame([], MiguelStubServer::requests());
    }

    public function testSendsTheOldestTwentyWithKeyUserAgentAndOriginalTime()
    {
        $this->bufferReports(25);
        $buffer = MiguelErrorReporter::getBuffer();
        $buffer[0]['report']['occurredAt'] = '2026-10-01T08:00:00+00:00'; // buffered while Miguel was down
        \Configuration::updateGlobalValue(MiguelErrorReporter::BUFFER_KEY, json_encode($buffer, JSON_HEX_TAG));
        MiguelStubServer::respond(202);

        MiguelErrorReporter::flush($this->module);

        $requests = MiguelStubServer::requests();
        $this->assertCount(1, $requests);
        $this->assertSame('POST', $requests[0]['method']);
        $this->assertSame('/v2/eshop/errors', $requests[0]['uri']);
        $this->assertSame('Bearer ' . MiguelStubServer::TOKEN, $requests[0]['headers']['authorization']);
        $this->assertSame($this->module->getUserAgent(), $requests[0]['headers']['user-agent']);
        $this->assertStringStartsWith('MiguelForPrestashop/', $requests[0]['headers']['user-agent']);
        $this->assertStringStartsWith('application/json', $requests[0]['headers']['content-type']);

        $sent = $this->sentReports($requests[0]);
        $this->assertCount(20, $sent);
        $this->assertSame('report 1', $sent[0]['message']);
        $this->assertSame('report 20', $sent[19]['message']);
        $this->assertSame('2026-10-01T08:00:00+00:00', $sent[0]['occurredAt']);
        $this->assertSame(['code', 'message', 'occurredAt'], array_keys($sent[1]));

        $this->assertSame(['report 21', 'report 22', 'report 23', 'report 24', 'report 25'], $this->bufferedMessages());
    }

    public function testAcceptedKeepsReportsAnotherRequestAddedDuringTheSend()
    {
        $this->bufferReports(2);
        $stored = MiguelErrorReporter::getBuffer();
        $stored[] = ['id' => 'other-request', 'report' => ['code' => 'PRODUCT_EXPORT_FAILED', 'message' => 'later', 'occurredAt' => gmdate('c')]];
        // While Miguel answers, another request appends to the row.
        MiguelStubServer::respond(202, '', [], 0, 'UPDATE `' . _DB_PREFIX_ . 'configuration` SET `value` = '
            . '\'' . pSQL(json_encode($stored, JSON_HEX_TAG)) . '\''
            . ' WHERE `name` = \'' . MiguelErrorReporter::BUFFER_KEY . '\'');

        MiguelErrorReporter::flush($this->module);

        $this->assertCount(2, $this->sentReports(MiguelStubServer::requests()[0]));
        $this->assertSame(['other-request'], array_column($this->storedRow(), 'id'));
    }

    public function testSendsWhatAnotherRequestAddedBeforeTheFlush()
    {
        $this->bufferReports(2);
        $stored = MiguelErrorReporter::getBuffer();
        $stored[] = ['id' => 'other-request', 'report' => ['code' => 'PRODUCT_EXPORT_FAILED', 'message' => 'later', 'occurredAt' => gmdate('c')]];
        $this->writeRowBehindTheCache(MiguelErrorReporter::BUFFER_KEY, json_encode($stored, JSON_HEX_TAG));

        MiguelErrorReporter::flush($this->module);

        $this->assertSame(['report 1', 'report 2', 'later'], array_column($this->sentReports(MiguelStubServer::requests()[0]), 'message'));
        $this->assertSame([], $this->storedRow());
    }

    public function testNoKeySendsNoAuthorization()
    {
        MiguelStubServer::reset('');
        $this->bufferReports(1);

        MiguelErrorReporter::flush($this->module);

        $requests = MiguelStubServer::requests();
        $this->assertCount(1, $requests);
        $this->assertArrayNotHasKey('authorization', $requests[0]['headers']);
    }

    public function testDisabledModuleStillSendsSoARejectedKeyIsReported()
    {
        // getContent() disables the module when the connect is refused, right after that refusal was buffered.
        MiguelErrorReporter::report('MIGUEL_AUTH_REJECTED', 'Unauthorized', ['httpStatus' => 401]);
        MiguelSettings::setEnabled(false);

        MiguelErrorReporter::flush($this->module);

        $requests = MiguelStubServer::requests();
        $this->assertCount(1, $requests);
        $this->assertSame('Bearer ' . MiguelStubServer::TOKEN, $requests[0]['headers']['authorization']);
        $this->assertSame('MIGUEL_AUTH_REJECTED', $this->sentReports($requests[0])[0]['code']);
    }

    public function testBadRequestDropsTheSentReports()
    {
        $this->bufferReports(3);
        MiguelStubServer::respond(400, '{"title":"invalid"}');

        MiguelErrorReporter::flush($this->module);

        $this->assertSame([], MiguelErrorReporter::getBuffer());
    }

    public function testTooManyRequestsKeepsThemAndWaitsRetryAfter()
    {
        $this->bufferReports(3);
        MiguelStubServer::respond(429, '', ['Retry-After' => '120']);

        MiguelErrorReporter::flush($this->module);
        MiguelErrorReporter::flush($this->module); // still inside the delay: sends nothing

        $this->assertCount(1, MiguelStubServer::requests());
        $this->assertCount(3, MiguelErrorReporter::getBuffer());
        $retryAt = (int) \Configuration::getGlobalValue(MiguelErrorReporter::RETRY_AT_KEY);
        $this->assertGreaterThanOrEqual(time() + 115, $retryAt);
        $this->assertLessThanOrEqual(time() + 120, $retryAt);
    }

    public function testTooManyRequestsWithoutRetryAfterWaitsTheDefault()
    {
        $this->bufferReports(1);
        MiguelStubServer::respond(429);

        MiguelErrorReporter::flush($this->module);

        $retryAt = (int) \Configuration::getGlobalValue(MiguelErrorReporter::RETRY_AT_KEY);
        $this->assertGreaterThanOrEqual(time() + MiguelErrorReporter::RETRY_DEFAULT - 5, $retryAt);
    }

    public function testAnAbsurdRetryAfterWaitsADayAtMost()
    {
        $this->bufferReports(1);
        MiguelStubServer::respond(429, '', ['Retry-After' => '31536000']); // a proxy's year

        MiguelErrorReporter::flush($this->module);

        $this->assertLessThanOrEqual(time() + 86400, (int) \Configuration::getGlobalValue(MiguelErrorReporter::RETRY_AT_KEY));
    }

    public function testSendsAgainOnceTheDelayHasPassed()
    {
        $this->bufferReports(1);
        \Configuration::updateGlobalValue(MiguelErrorReporter::RETRY_AT_KEY, time() - 1);

        MiguelErrorReporter::flush($this->module);

        $this->assertCount(1, MiguelStubServer::requests());
        $this->assertSame([], MiguelErrorReporter::getBuffer());
    }

    public function testServerErrorKeepsThem()
    {
        $this->bufferReports(3);
        MiguelStubServer::respond(503, 'down');

        MiguelErrorReporter::flush($this->module);

        $this->assertCount(3, MiguelErrorReporter::getBuffer());
    }

    public function testUnreachableMiguelKeepsThemAndReportsNothingAboutIt()
    {
        $this->bufferReports(3);
        MiguelSettings::save(MiguelSettings::API_SERVER_OWN_KEY, MiguelStubServer::unreachableUrl());

        MiguelErrorReporter::flush($this->module);

        $this->assertSame(['report 1', 'report 2', 'report 3'], $this->bufferedMessages());
    }

    public function testGivesUpAfterFiveSeconds()
    {
        $this->bufferReports(1);
        MiguelStubServer::respond(202, '', [], 6);

        $started = microtime(true);
        MiguelErrorReporter::flush($this->module);
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(6.5, $elapsed);
        $this->assertCount(1, MiguelErrorReporter::getBuffer());
    }

    public function testAFullBufferOfLargeReportsFitsOneRequest()
    {
        $value = str_repeat("\u{0159}/<", 300); // stored as \u0159, \/ and \u003C, sent as themselves
        $context = [];
        for ($i = 0; $i < 20; ++$i) {
            $context['key' . $i] = $value;
        }
        for ($i = 0; $i < 50; ++$i) {
            MiguelErrorReporter::report('ORDER_SYNC_FAILED', $value, ['responseExcerpt' => $value, 'context' => $context]);
        }

        MiguelErrorReporter::flush($this->module);

        $requests = MiguelStubServer::requests();
        $this->assertCount(1, $requests);
        $this->assertLessThanOrEqual(64 * 1024, strlen($requests[0]['body']));
        $this->assertNotEmpty($this->sentReports($requests[0]));
        $this->assertSame(mb_substr($value, 0, 256), $this->sentReports($requests[0])[0]['context']['key0']);
    }

    public function testEmptyBufferSendsNothing()
    {
        MiguelErrorReporter::flush($this->module);

        $this->assertSame([], MiguelStubServer::requests());
    }
}
