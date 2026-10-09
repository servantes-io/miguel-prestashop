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

namespace Tests\Unit\Utility;

use Miguel\Utils\MiguelSettings;

/**
 * A local `php -S` server standing in for Miguel, so the module's real cURL calls can be tested: it records each
 * request and answers from a queue (miguel-stub-router.php). One server per test process, started on first use.
 */
class MiguelStubServer
{
    public const TOKEN = 'stub-api-key';

    private static $process;
    private static $dir;
    private static $port;

    public static function start()
    {
        if (null !== self::$process) {
            return;
        }

        self::$dir = sys_get_temp_dir() . '/miguel-stub-' . getmypid();
        if (!is_dir(self::$dir)) {
            mkdir(self::$dir, 0700, true);
        }
        self::$port = self::freePort();
        // _DB_SERVER_ may carry a port ("host:port"), which a PDO DSN takes apart.
        $server = explode(':', _DB_SERVER_, 2);

        $command = 'exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . self::$port . ' '
            . escapeshellarg(__DIR__ . '/miguel-stub-router.php');
        self::$process = proc_open(
            $command,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            [
                'MIGUEL_STUB_DIR' => self::$dir,
                'MIGUEL_STUB_DSN' => 'mysql:host=' . $server[0] . (isset($server[1]) ? ';port=' . $server[1] : '') . ';dbname=' . _DB_NAME_,
                'MIGUEL_STUB_DB_USER' => _DB_USER_,
                'MIGUEL_STUB_DB_PASSWORD' => _DB_PASSWD_,
            ]
        );

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.2);
            if (false !== $socket) {
                fclose($socket);
                register_shutdown_function([self::class, 'stop']);

                return;
            }
            usleep(50000);
        }

        throw new \RuntimeException('Miguel stub server did not start');
    }

    public static function stop()
    {
        if (null !== self::$process) {
            proc_terminate(self::$process);
            proc_close(self::$process);
            self::$process = null;
        }
    }

    public static function url()
    {
        return 'http://127.0.0.1:' . self::$port;
    }

    /**
     * A local URL nothing listens on.
     */
    public static function unreachableUrl()
    {
        return 'http://127.0.0.1:' . self::freePort();
    }

    /**
     * Start the server if needed, forget earlier requests and queued answers, and point the module at it.
     *
     * @param string $token API key to configure ('' for none)
     */
    public static function reset($token = self::TOKEN)
    {
        self::start();
        file_put_contents(self::$dir . '/requests.log', '');
        file_put_contents(self::$dir . '/responses.json', '[]');

        MiguelSettings::save(MiguelSettings::API_SERVER_KEY, MiguelSettings::ENV_OWN);
        MiguelSettings::save(MiguelSettings::API_SERVER_OWN_KEY, self::url());
        MiguelSettings::save(MiguelSettings::API_TOKEN_OWN_KEY, $token);
        MiguelSettings::setEnabled(true);
    }

    /**
     * Queue the answer to the next request.
     *
     * @param int $status
     * @param string $body
     * @param array<string,string> $headers
     * @param int $sleep seconds to wait before answering
     * @param string $sql statement to run on the test database before answering
     */
    public static function respond($status, $body = '', array $headers = [], $sleep = 0, $sql = '')
    {
        $queue = json_decode(file_get_contents(self::$dir . '/responses.json'), true);
        $queue[] = ['status' => $status, 'body' => $body, 'headers' => $headers, 'sleep' => $sleep, 'sql' => $sql];
        file_put_contents(self::$dir . '/responses.json', json_encode($queue));
    }

    /**
     * The requests received since the last reset: method, uri, headers (lowercase names), body.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function requests()
    {
        $lines = array_filter(explode("\n", file_get_contents(self::$dir . '/requests.log')));

        return array_values(array_map(function ($line) {
            return json_decode($line, true);
        }, $lines));
    }

    private static function freePort()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
