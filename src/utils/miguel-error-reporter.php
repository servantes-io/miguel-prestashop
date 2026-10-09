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

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Reports the errors the module catches to Miguel (`POST /v2/eshop/errors`), which forwards them to GlitchTip.
 *
 * The contract is Miguel's `docs/client-errors.md`. Reports wait in one global Configuration row. Nothing here may
 * throw into its caller.
 */
class MiguelErrorReporter
{
    /** Configuration row holding the buffered reports, oldest first. Each entry is { id, report }. */
    public const BUFFER_KEY = 'MIGUEL_ERROR_REPORTS';

    /** Configuration row holding the time before which nothing is sent (set by a 429). */
    public const RETRY_AT_KEY = 'MIGUEL_ERROR_REPORTS_RETRY_AT';

    public const ENDPOINT = '/v2/eshop/errors';

    public const CODE_PATTERN = '/^[A-Z][A-Z0-9_]{2,63}$/';

    public const BUFFER_MAX = 50;

    /**
     * The row is a TEXT column (65 535 bytes) and PrestaShop runs with sql_mode = '', so a longer value would be cut
     * silently into invalid JSON. It also keeps every request under Miguel's 64 KB: a batch is a part of the buffer,
     * sent without the ids and without the \uXXXX, \/ and tag escaping the row is stored with.
     */
    public const BUFFER_MAX_BYTES = 60000;

    public const BATCH_MAX = 20;

    /** Seconds; the contract's ceiling. */
    public const TIMEOUT = 5;

    /** Seconds to wait after a 429 that carries no usable Retry-After. */
    public const RETRY_DEFAULT = 60;

    /** The longest Retry-After honoured, in seconds: a proxy's 429 must not silence reporting for longer. */
    public const RETRY_MAX = 86400;

    public const MESSAGE_MAX = 1024;
    public const OPERATION_MAX = 200;
    public const EXCERPT_MAX = 500;
    public const CONTEXT_MAX = 20;
    public const CONTEXT_KEY_MAX = 64;
    public const CONTEXT_VALUE_MAX = 256;

    /**
     * Buffer one report. Never throws.
     *
     * @param string $code error code, `^[A-Z][A-Z0-9_]{2,63}$`
     * @param string $message what happened
     * @param array<string,mixed> $details optional `operation`, `httpStatus`, `responseExcerpt` and `context` (ids only)
     */
    public static function report($code, $message, array $details = [])
    {
        try {
            if (!preg_match(self::CODE_PATTERN, (string) $code)) {
                return;
            }

            $buffer = self::getBuffer();
            $buffer[] = [
                'id' => bin2hex(random_bytes(8)),
                'report' => self::build($code, $message, $details),
            ];

            self::store(array_slice($buffer, -self::BUFFER_MAX));
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Report a call to Miguel that failed: no answer at all, a rejected key or another HTTP error. Anything else
     * (a success, a redirect) reports nothing. Never throws.
     *
     * @param string $method
     * @param string $uri path, possibly with a query string, which is never reported (it can hold an e-mail)
     * @param int $httpStatus 0 when there was no answer
     * @param string|bool $body the answer's body, as curl_exec() returned it
     * @param string $curlError curl_error(), '' when cURL had none
     */
    public static function reportFailedCall($method, $uri, $httpStatus, $body, $curlError)
    {
        try {
            $details = ['operation' => self::operation($method, $uri)];
            $httpStatus = (int) $httpStatus;

            if ('' !== (string) $curlError || $httpStatus < 100) {
                self::report('MIGUEL_UNREACHABLE', '' !== (string) $curlError ? $curlError : 'No answer from Miguel', $details);

                return;
            }

            $details['httpStatus'] = $httpStatus;
            if (is_string($body)) {
                $details['responseExcerpt'] = $body;
            }

            if (401 === $httpStatus || 403 === $httpStatus) {
                self::report('MIGUEL_AUTH_REJECTED', 'Miguel rejected the API key (HTTP ' . $httpStatus . ')', $details);
            } elseif ($httpStatus >= 400) {
                self::report('MIGUEL_HTTP_ERROR', 'Miguel answered HTTP ' . $httpStatus, $details);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Report a successful (200) answer the module could not use: MIGUEL_RESPONSE_UNPARSABLE when it is not JSON,
     * else MIGUEL_RESPONSE_INVALID with the given problem. Never throws.
     *
     * @param string $method
     * @param string $uri path, possibly with a query string, which is never reported
     * @param string $body
     * @param string $problem what is missing or wrongly typed
     */
    public static function reportBadResponse($method, $uri, $body, $problem)
    {
        try {
            $details = [
                'operation' => self::operation($method, $uri),
                'httpStatus' => 200,
                'responseExcerpt' => $body,
            ];

            json_decode((string) $body);
            if (JSON_ERROR_NONE !== json_last_error()) {
                self::report('MIGUEL_RESPONSE_UNPARSABLE', json_last_error_msg(), $details);
            } else {
                self::report('MIGUEL_RESPONSE_INVALID', $problem, $details);
            }
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * The buffered reports, oldest first.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function getBuffer()
    {
        return self::decode(self::freshGlobalValue(self::BUFFER_KEY));
    }

    /**
     * Send the oldest buffered reports. Never throws, and reports nothing about its own failure: it does not go
     * through Miguel::curlPost(), which reports.
     *
     * @param \Miguel $module supplies the User-Agent
     */
    public static function flush(\Miguel $module)
    {
        try {
            self::sendBatch($module);
        } catch (\Throwable $e) {
            return;
        }
    }

    /**
     * Remove both rows (module uninstall).
     */
    public static function deleteAll()
    {
        \Configuration::deleteByName(self::BUFFER_KEY);
        \Configuration::deleteByName(self::RETRY_AT_KEY);
    }

    /**
     * Send one batch and apply Miguel's answer to the buffer.
     *
     * @param \Miguel $module
     */
    private static function sendBatch(\Miguel $module)
    {
        if (time() < (int) self::freshGlobalValue(self::RETRY_AT_KEY)) {
            return;
        }

        // Not gated on the module being enabled: getContent() disables it when Miguel rejects the key, and that
        // rejection is the report most worth sending.
        $server = MiguelSettings::getServer();
        $url = MiguelSettings::getServerUrl($server);
        if (!is_string($url) || '' === $url) {
            return;
        }

        $batch = self::takeBatch(self::getBuffer());
        if ([] === $batch['ids']) {
            return;
        }

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'User-Agent: ' . $module->getUserAgent(),
        ];
        $token = trim((string) MiguelSettings::getServerToken($server));
        if ('' !== $token) {
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        $retryAfter = 0;
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => rtrim($url, '/') . self::ENDPOINT,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $batch['body'],
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            // as the module's other calls to Miguel do
            CURLOPT_SSL_VERIFYPEER => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$retryAfter) {
                if (preg_match('/^Retry-After:\s*(\d+)/i', $line, $matches)) {
                    $retryAfter = (int) $matches[1];
                }

                return strlen($line);
            },
        ]);
        $sent = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if (false === $sent) {
            return;
        }

        if (202 === $status || 400 === $status) {
            self::remove($batch['ids']);
        } elseif (429 === $status) {
            $delay = $retryAfter > 0 ? min($retryAfter, self::RETRY_MAX) : self::RETRY_DEFAULT;
            \Configuration::updateGlobalValue(self::RETRY_AT_KEY, time() + $delay);
        }
    }

    /**
     * The oldest BATCH_MAX reports and the request body that sends them.
     *
     * @param array<int,array<string,mixed>> $buffer
     *
     * @return array{ids: array<int,string>, body: string}
     */
    private static function takeBatch(array $buffer)
    {
        $ids = [];
        $reports = [];
        foreach (array_slice($buffer, 0, self::BATCH_MAX) as $entry) {
            if (isset($entry['id'], $entry['report'])) {
                $ids[] = $entry['id'];
                $reports[] = $entry['report'];
            }
        }

        return ['ids' => $ids, 'body' => (string) json_encode(['reports' => $reports], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    /**
     * Remove the answered reports, re-reading the row: another request may have added to it meanwhile.
     *
     * @param array<int,string> $ids
     */
    private static function remove(array $ids)
    {
        $remaining = array_filter(self::getBuffer(), function ($entry) use ($ids) {
            return !isset($entry['id']) || !in_array($entry['id'], $ids, true);
        });

        self::store(array_values($remaining));
    }

    /**
     * A global Configuration value as the database holds it now. Configuration::get*() answers from a cache loaded
     * when the request started, which has not seen other requests' writes since. The value read is put into that
     * cache, so a following updateGlobalValue() compares against it and updates the row: with no key in its cache it
     * would look the row up, find one another request created, and write nothing.
     *
     * @param string $key
     *
     * @return string|false false when there is no such row
     */
    private static function freshGlobalValue($key)
    {
        $value = \Db::getInstance()->getValue(
            'SELECT `value` FROM `' . _DB_PREFIX_ . 'configuration` WHERE `name` = \'' . pSQL($key) . '\''
            . ' AND `id_shop` IS NULL AND `id_shop_group` IS NULL',
            false
        );
        if (!is_string($value)) {
            return false;
        }

        \Configuration::set($key, $value, 0, 0);

        return $value;
    }

    /**
     * "METHOD /path", without the query string.
     *
     * @param string $method
     * @param string $uri
     *
     * @return string
     */
    private static function operation($method, $uri)
    {
        return $method . ' ' . explode('?', (string) $uri, 2)[0];
    }

    /**
     * @param mixed $raw stored value
     *
     * @return array<int,array<string,mixed>>
     */
    private static function decode($raw)
    {
        $buffer = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($buffer) ? array_values($buffer) : [];
    }

    /**
     * Store the buffer, dropping the oldest entries until it fits the column.
     *
     * Configuration::updateValue() strips tags (and turns newlines into <br />) unless told the value is HTML, and
     * then it purifies it instead; JSON_HEX_TAG leaves no "<" or ">" and json_encode leaves no raw newline, so
     * neither touches the stored JSON. The default \uXXXX escaping keeps it ASCII for any table charset.
     *
     * @param array<int,array<string,mixed>> $buffer
     */
    private static function store(array $buffer)
    {
        $json = json_encode($buffer, JSON_HEX_TAG);
        while (count($buffer) > 1 && strlen($json) > self::BUFFER_MAX_BYTES) {
            array_shift($buffer);
            $json = json_encode($buffer, JSON_HEX_TAG);
        }

        \Configuration::updateGlobalValue(self::BUFFER_KEY, $json);
    }

    /**
     * Build a report within the contract's limits.
     *
     * @param string $code
     * @param string $message
     * @param array<string,mixed> $details
     *
     * @return array<string,mixed>
     */
    private static function build($code, $message, array $details)
    {
        $message = self::truncate($message, self::MESSAGE_MAX);
        $report = [
            'code' => (string) $code,
            'message' => '' === trim($message) ? (string) $code : $message,
            'occurredAt' => gmdate('c'),
        ];

        if (isset($details['operation']) && '' !== (string) $details['operation']) {
            $report['operation'] = self::truncate($details['operation'], self::OPERATION_MAX);
        }

        if (isset($details['httpStatus']) && (int) $details['httpStatus'] >= 100 && (int) $details['httpStatus'] <= 599) {
            $report['httpStatus'] = (int) $details['httpStatus'];
        }

        if (isset($details['responseExcerpt']) && '' !== (string) $details['responseExcerpt']) {
            $report['responseExcerpt'] = self::truncate($details['responseExcerpt'], self::EXCERPT_MAX);
        }

        if (isset($details['context']) && is_array($details['context'])) {
            $context = [];
            foreach ($details['context'] as $key => $value) {
                if (count($context) >= self::CONTEXT_MAX) {
                    break;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $context[self::truncate($key, self::CONTEXT_KEY_MAX)] = self::truncate($value, self::CONTEXT_VALUE_MAX);
            }

            if ([] !== $context) {
                $report['context'] = $context;
            }
        }

        return $report;
    }

    /**
     * Cut a value to a length, replacing invalid UTF-8 first. The length is counted in UTF-16 code units, as Miguel
     * (.NET) counts it: a character outside the Basic Multilingual Plane counts twice.
     *
     * @param mixed $value
     * @param int $max
     *
     * @return string
     */
    private static function truncate($value, $max)
    {
        $value = mb_substr(mb_convert_encoding((string) $value, 'UTF-8', 'UTF-8'), 0, $max, 'UTF-8');
        while (strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) / 2 > $max) {
            $value = mb_substr($value, 0, -1, 'UTF-8');
        }

        return $value;
    }
}
