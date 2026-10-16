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
use Tests\Unit\Utility\DatabaseTestCase;

class ErrorReporterTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \Configuration::deleteByName(MiguelErrorReporter::BUFFER_KEY);
        \Configuration::deleteByName(MiguelErrorReporter::RETRY_AT_KEY);
    }

    private function reports()
    {
        return array_map(function ($entry) {
            return $entry['report'];
        }, MiguelErrorReporter::getBuffer());
    }

    /**
     * Length as Miguel (.NET) counts it: UTF-16 code units.
     */
    private function utf16Length($value)
    {
        return strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) / 2;
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

    public function testReportKeepsWhatAnotherRequestAddedMeanwhile()
    {
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'first');
        $stored = $this->storedRow();
        $stored[] = ['id' => 'other-request', 'report' => ['code' => 'PRODUCT_EXPORT_FAILED', 'message' => 'other', 'occurredAt' => '2026-10-09T08:00:00+00:00']];
        $this->writeRowBehindTheCache(MiguelErrorReporter::BUFFER_KEY, json_encode($stored, JSON_HEX_TAG));

        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'second');

        $this->assertSame(['first', 'other', 'second'], array_column(array_column($this->storedRow(), 'report'), 'message'));
    }

    public function testReportWritesIntoARowAnotherRequestCreatedMeanwhile()
    {
        // This request starts with no row in its cache; another request creates the row afterwards.
        \Configuration::loadConfiguration();
        $this->writeRowBehindTheCache(MiguelErrorReporter::BUFFER_KEY, json_encode([['id' => 'other-request', 'report' => ['code' => 'PRODUCT_EXPORT_FAILED', 'message' => 'other', 'occurredAt' => '2026-10-09T08:00:00+00:00']]], JSON_HEX_TAG));

        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'mine');

        $this->assertSame(['other', 'mine'], array_column(array_column($this->storedRow(), 'report'), 'message'));
    }

    public function testReportIsBufferedWithCodeMessageAndOccurredAt()
    {
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'Something broke', [
            'operation' => 'POST /v2/orders',
            'httpStatus' => 500,
            'responseExcerpt' => 'oops',
            'context' => ['orderId' => '12'],
        ]);

        $reports = $this->reports();
        $this->assertCount(1, $reports);
        $this->assertSame('ORDER_SYNC_FAILED', $reports[0]['code']);
        $this->assertSame('Something broke', $reports[0]['message']);
        $this->assertSame('POST /v2/orders', $reports[0]['operation']);
        $this->assertSame(500, $reports[0]['httpStatus']);
        $this->assertSame('oops', $reports[0]['responseExcerpt']);
        $this->assertSame(['orderId' => '12'], $reports[0]['context']);
        $this->assertNotFalse(\DateTime::createFromFormat(DATE_ATOM, $reports[0]['occurredAt']));
        $this->assertLessThanOrEqual(5, abs(strtotime($reports[0]['occurredAt']) - time()));
    }

    public function testInvalidCodeIsIgnored()
    {
        MiguelErrorReporter::report('lowercase', 'x');
        MiguelErrorReporter::report('AB', 'x');

        $this->assertSame([], MiguelErrorReporter::getBuffer());
    }

    public function testEmptyMessageFallsBackToTheCode()
    {
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', '  ');

        $this->assertSame('ORDER_SYNC_FAILED', $this->reports()[0]['message']);
    }

    public function testBufferKeepsTheNewestFifty()
    {
        for ($i = 1; $i <= 51; ++$i) {
            MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'report ' . $i);
        }

        $reports = $this->reports();
        $this->assertCount(50, $reports);
        $this->assertSame('report 2', $reports[0]['message']);
        $this->assertSame('report 51', $reports[49]['message']);
    }

    public function testLimitsAreCountedInUtf16Units()
    {
        $emoji = "\u{1F600}"; // outside the BMP: two UTF-16 units
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', str_repeat($emoji, 1000), [
            'operation' => str_repeat('o', 300),
            'responseExcerpt' => str_repeat($emoji, 3000),
            'context' => [str_repeat('k', 100) => str_repeat($emoji, 300)],
        ]);

        $report = $this->reports()[0];
        $this->assertSame(1024, $this->utf16Length($report['message']));
        $this->assertSame(200, $this->utf16Length($report['operation']));
        $this->assertLessThanOrEqual(2048, $this->utf16Length($report['responseExcerpt']));
        $key = array_keys($report['context'])[0];
        $this->assertSame(64, $this->utf16Length($key));
        $this->assertSame(256, $this->utf16Length($report['context'][$key]));
    }

    public function testInvalidUtf8IsCleaned()
    {
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', "bad \xC3\x28 byte", ['responseExcerpt' => "\xFF\xFE"]);

        $report = $this->reports()[0];
        $this->assertTrue(mb_check_encoding($report['message'], 'UTF-8'));
        $this->assertStringStartsWith('bad ', $report['message']);
        $this->assertTrue(mb_check_encoding($report['responseExcerpt'], 'UTF-8'));
    }

    public function testContextKeepsTwentyScalarEntriesAsStrings()
    {
        $context = ['nested' => ['a' => 1], 'none' => null];
        for ($i = 1; $i <= 25; ++$i) {
            $context['id' . $i] = $i;
        }
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'x', ['context' => $context]);

        $reported = $this->reports()[0]['context'];
        $this->assertCount(20, $reported);
        $this->assertArrayNotHasKey('nested', $reported);
        $this->assertArrayNotHasKey('none', $reported);
        $this->assertSame('1', $reported['id1']);
    }

    public function testHttpStatusOutsideTheContractRangeIsDropped()
    {
        MiguelErrorReporter::report('MIGUEL_UNREACHABLE', 'x', ['httpStatus' => 0]);

        $this->assertArrayNotHasKey('httpStatus', $this->reports()[0]);
    }

    public function testHtmlAndNewlinesSurviveTheConfigurationRow()
    {
        $excerpt = "<html>\n<body><b>502 Bad Gateway</b> & \"quotes\" 'too'</body>\r\n</html>";
        MiguelErrorReporter::report('MIGUEL_HTTP_ERROR', "line one\nline <two>", ['responseExcerpt' => $excerpt]);

        // Read the stored row, not this request's cache.
        $raw = \Db::getInstance()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = "' . pSQL(MiguelErrorReporter::BUFFER_KEY) . '"');
        $stored = json_decode($raw, true);

        $this->assertSame($excerpt, $stored[0]['report']['responseExcerpt']);
        $this->assertSame("line one\nline <two>", $stored[0]['report']['message']);
    }

    public function testFiftyLargeReportsStayWithinTheConfigurationColumn()
    {
        $big = str_repeat("\u{0159}", 2000); // ř: escaped as ř in the stored JSON
        $context = [];
        for ($i = 0; $i < 20; ++$i) {
            $context['key' . $i] = $big;
        }
        for ($i = 0; $i < 50; ++$i) {
            MiguelErrorReporter::report('ORDER_SYNC_FAILED', $big, ['responseExcerpt' => $big, 'context' => $context]);
        }

        $raw = \Db::getInstance()->getValue('SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = "' . pSQL(MiguelErrorReporter::BUFFER_KEY) . '"');
        $this->assertLessThanOrEqual(MiguelErrorReporter::BUFFER_MAX_BYTES, strlen($raw));
        $this->assertIsArray(json_decode($raw, true));
        $this->assertNotEmpty(MiguelErrorReporter::getBuffer());
    }

    public function testUninstallRemovesBothRows()
    {
        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'x');
        \Configuration::updateGlobalValue(MiguelErrorReporter::RETRY_AT_KEY, time() + 60);

        (new \Miguel())->uninstall();

        $this->assertFalse(\Configuration::hasKey(MiguelErrorReporter::BUFFER_KEY));
        $this->assertFalse(\Configuration::hasKey(MiguelErrorReporter::RETRY_AT_KEY));
    }

    public function testCorruptRowReadsAsEmptyAndIsReplaced()
    {
        \Configuration::updateGlobalValue(MiguelErrorReporter::BUFFER_KEY, '{"truncated');

        $this->assertSame([], MiguelErrorReporter::getBuffer());

        MiguelErrorReporter::report('ORDER_SYNC_FAILED', 'after corruption');
        $this->assertSame('after corruption', $this->reports()[0]['message']);
    }
}
