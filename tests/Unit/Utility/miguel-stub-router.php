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

/*
 * Router for `php -S` standing in for Miguel (see MiguelStubServer). Records every request to requests.log and
 * answers with the next queued response from responses.json (202 with an empty body when none is queued). A queued
 * response may first run one SQL statement on the test database, standing in for another request writing meanwhile.
 */
$dir = getenv('MIGUEL_STUB_DIR');

file_put_contents($dir . '/requests.log', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => file_get_contents('php://input'),
]) . "\n", FILE_APPEND | LOCK_EX);

$queue = json_decode((string) @file_get_contents($dir . '/responses.json'), true);
$response = is_array($queue) && [] !== $queue ? array_shift($queue) : ['status' => 202];
file_put_contents($dir . '/responses.json', json_encode(is_array($queue) ? $queue : []), LOCK_EX);

if (!empty($response['sql'])) {
    (new PDO(getenv('MIGUEL_STUB_DSN'), getenv('MIGUEL_STUB_DB_USER'), getenv('MIGUEL_STUB_DB_PASSWORD')))
        ->exec($response['sql']);
}

if (!empty($response['sleep'])) {
    sleep((int) $response['sleep']);
}

http_response_code((int) $response['status']);
foreach (isset($response['headers']) ? $response['headers'] : [] as $name => $value) {
    header($name . ': ' . $value);
}
echo isset($response['body']) ? $response['body'] : '';
