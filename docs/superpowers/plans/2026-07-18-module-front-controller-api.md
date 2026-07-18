# Module Front-Controller API Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Serve the three Miguel API endpoints through a native PrestaShop module front controller (routed via root `index.php`) so they stop being blocked by Cloudflare/Apache rules against executable PHP in `/modules/`, while keeping the legacy direct-access scripts working during the transition.

**Architecture:** A single dispatcher front controller (`controllers/front/api.php`) routes on a `resource` query parameter to shared routing logic in a new `MiguelApiDispatcher` class. Both the front controller and the (now thin) legacy scripts delegate to that dispatcher, giving one source of truth for validation and routing. The module reports its endpoint URLs to the Miguel backend on connect so the backend no longer hardcodes paths.

**Tech Stack:** PHP 7.1+, PrestaShop 1.7 / 8 / 9 module APIs (`ModuleFrontController`, `Link::getModuleLink`), PHPUnit for tests.

## Global Constraints

- PHP floor: `>=7.1.0` — no PHP 7.4+/8.0+ only syntax (no arrow functions, no typed properties, no `??=`, no named args, no union types in signatures).
- PrestaShop compatibility: `1.7`–`9.99.99` (`ps_versions_compliancy`). Code must work on PS 1.7, 8, and 9.
- All new `src/` and `controllers/` PHP files start with `if (!defined('_PS_VERSION_')) { exit; }` after the license header (follow existing file headers verbatim in style).
- New utility classes live in the `Miguel\Utils` namespace (matches existing `src/utils/*`).
- Response contract is **HTTP 200 + JSON envelope** for every outcome (success and error). Never emit real HTTP error status codes — the backend parser depends on the envelope.
- Resource identifiers are exactly: `orders`, `products`, `order-state-callback`. These strings are hardcoded on the backend — do not rename.
- Test environment: DB-backed tests extend `Tests\Unit\Utility\DatabaseTestCase` and require the dockerized test stack. Bring it up and run the full suite with `make test`. Run a single test with `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter <TestName>` once the environment (MySQL + PrestaShop in `vendor2/PrestaShop`) is available.
- Bump version to `1.3.0` (done in the final task) — do not touch `$this->version` in intermediate tasks.

---

## File Structure

- **Create** `src/utils/miguel-api-dispatcher.php` — `Miguel\Utils\MiguelApiDispatcher`; validates access, enforces HTTP method, routes a resource to the right module method, returns a `MiguelApiResponse`. Never echoes.
- **Create** `controllers/front/api.php` — `MiguelApiModuleFrontController`; thin adapter that gathers request inputs, calls the dispatcher, emits raw JSON, and keeps working during maintenance mode.
- **Modify** `src/utils/miguel-api-error.php` — add `resourceNotFound()` and `methodNotAllowed()` factories.
- **Modify** `miguel.php` — `require_once` the dispatcher; add `getCustomTokenHeader()` + `X-Miguel-Token` fallback in `getBearerToken()`; add `endpoints` map in `getPrestashopDetails()`; bump version.
- **Modify** `orders.php`, `products.php`, `order-state-callback.php` — reduce to thin shims delegating to the dispatcher; mark `@deprecated`.
- **Create** tests: `tests/Unit/ApiErrorTest.php`, `tests/Unit/BearerTokenTest.php`, `tests/Unit/ApiDispatcherTest.php`, `tests/Unit/PrestashopDetailsTest.php`.
- **Modify** `CHANGELOG.md`, `.github/scripts/build-zip.sh` (exclude `docs/` from the shipped zip).

---

## Task 1: New API error types

**Files:**
- Modify: `src/utils/miguel-api-error.php`
- Test: `tests/Unit/ApiErrorTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `MiguelApiError::resourceNotFound(string $resource): MiguelApiError` → code `resource.not_found`, message `Resource <resource> not found`.
  - `MiguelApiError::methodNotAllowed(string $method): MiguelApiError` → code `method.not_allowed`, message `Method <method> not allowed`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ApiErrorTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiError;
use PHPUnit\Framework\TestCase;

class ApiErrorTest extends TestCase
{
    public function testResourceNotFound()
    {
        $error = MiguelApiError::resourceNotFound('widgets');

        $this->assertSame('resource.not_found', $error->getCode());
        $this->assertSame('Resource widgets not found', $error->getMessage());
    }

    public function testMethodNotAllowed()
    {
        $error = MiguelApiError::methodNotAllowed('DELETE');

        $this->assertSame('method.not_allowed', $error->getCode());
        $this->assertSame('Method DELETE not allowed', $error->getMessage());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter ApiErrorTest`
Expected: FAIL — `Call to undefined method Miguel\Utils\MiguelApiError::resourceNotFound()`.

- [ ] **Step 3: Write minimal implementation**

In `src/utils/miguel-api-error.php`, add these two factories immediately before the existing `unknownError()` method:

```php
    public static function resourceNotFound($resource): MiguelApiError
    {
        return new self('resource.not_found', "Resource $resource not found");
    }

    public static function methodNotAllowed($method): MiguelApiError
    {
        return new self('method.not_allowed', "Method $method not allowed");
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter ApiErrorTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add src/utils/miguel-api-error.php tests/Unit/ApiErrorTest.php
git commit -m "feat: add resourceNotFound and methodNotAllowed API errors"
```

---

## Task 2: X-Miguel-Token fallback for auth

**Files:**
- Modify: `miguel.php` (method `getBearerToken()` near line 831; add new `getCustomTokenHeader()`)
- Test: `tests/Unit/BearerTokenTest.php`

**Interfaces:**
- Consumes: existing `getallheaders()` polyfill (already required by `miguel.php`).
- Produces:
  - `Miguel::getCustomTokenHeader(): string|false` — token from the `X-Miguel-Token` header, else `false`.
  - `Miguel::getBearerToken()` now returns the custom-header token when the `Authorization` bearer is absent.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/BearerTokenTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel;
use PHPUnit\Framework\TestCase;

class BearerTokenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);
        parent::tearDown();
    }

    public function testReadsBearerFromAuthorizationHeader()
    {
        $_SERVER['Authorization'] = 'Bearer abc123';

        $module = new Miguel();

        $this->assertSame('abc123', $module->getBearerToken());
    }

    public function testFallsBackToCustomHeader()
    {
        $_SERVER['HTTP_X_MIGUEL_TOKEN'] = 'abc123';

        $module = new Miguel();

        $this->assertSame('abc123', $module->getBearerToken());
    }

    public function testReturnsFalseWhenNoToken()
    {
        $module = new Miguel();

        $this->assertFalse($module->getBearerToken());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter BearerTokenTest`
Expected: FAIL — `testFallsBackToCustomHeader` fails (returns `false`, expected `'abc123'`).

- [ ] **Step 3: Write minimal implementation**

In `miguel.php`, replace the existing `getBearerToken()` method body so it falls through to the custom header, and add `getCustomTokenHeader()` right after it:

```php
    public function getBearerToken()
    {
        $headers = $this->getAuthorizationHeader();
        // HEADER: Get the access token from the header
        if (!empty($headers)) {
            if (preg_match('/Bearer\s(\S+)/i', $headers, $matches)) {
                return $matches[1];
            }
        }

        // Fallback: some proxies strip the Authorization header. Accept the same
        // token via the X-Miguel-Token custom header, which is stripped far less often.
        $customToken = $this->getCustomTokenHeader();
        if (!empty($customToken)) {
            return $customToken;
        }

        return false;
    }

    /**
     * Reads the API token from the X-Miguel-Token custom header. Used as a fallback
     * when a proxy strips the Authorization header.
     *
     * @return string|false
     */
    public function getCustomTokenHeader()
    {
        if (isset($_SERVER['HTTP_X_MIGUEL_TOKEN'])) {
            return trim($_SERVER['HTTP_X_MIGUEL_TOKEN']);
        }

        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $key => $value) {
                if (strtolower($key) === 'x-miguel-token') {
                    return trim($value);
                }
            }
        }

        return false;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter BearerTokenTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add miguel.php tests/Unit/BearerTokenTest.php
git commit -m "feat: accept API token via X-Miguel-Token header fallback"
```

---

## Task 3: MiguelApiDispatcher (shared routing)

**Files:**
- Create: `src/utils/miguel-api-dispatcher.php`
- Modify: `miguel.php` (add `require_once` for the new file, alongside the other `src/utils` requires near lines 15-20)
- Test: `tests/Unit/ApiDispatcherTest.php`

**Interfaces:**
- Consumes: `Miguel::validateApiAccess()`, `Miguel::getUpdatedOrders($updated_since)`, `Miguel::getAllProducts()`, `Miguel::setOrderStates(array $data)`; `MiguelApiResponse::success()/error()`; `MiguelApiError::*` (incl. `resourceNotFound`, `methodNotAllowed` from Task 1).
- Produces:
  - `MiguelApiDispatcher::__construct(\Miguel $module)`
  - `MiguelApiDispatcher::dispatch(string $resource, string $method, array $get, string $rawBody): MiguelApiResponse` — auth first, then method check, then resource routing. Never echoes.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ApiDispatcherTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel;
use Miguel\Utils\MiguelApiDispatcher;
use Miguel\Utils\MiguelSettings;
use Tests\Unit\Utility\DatabaseTestCase;

class ApiDispatcherTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');
    }

    protected function tearDown(): void
    {
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);
        parent::tearDown();
    }

    private function dispatcher(): MiguelApiDispatcher
    {
        return new MiguelApiDispatcher(new Miguel());
    }

    public function testUnknownResourceReturnsResourceNotFound()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('widgets', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('resource.not_found', $response->getData()->getCode());
    }

    public function testWrongMethodReturnsMethodNotAllowed()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('order-state-callback', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('method.not_allowed', $response->getData()->getCode());
    }

    public function testOrdersWithoutUpdatedSinceReturnsArgumentNotSet()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('orders', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('argument.not_set', $response->getData()->getCode());
    }

    public function testInvalidTokenReturnsApiKeyInvalid()
    {
        $_SERVER['Authorization'] = 'Bearer wrong';

        $response = $this->dispatcher()->dispatch('products', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('api_key.invalid', $response->getData()->getCode());
    }

    public function testProductsSucceedWithCustomHeaderToken()
    {
        $_SERVER['HTTP_X_MIGUEL_TOKEN'] = '1234';

        $response = $this->dispatcher()->dispatch('products', 'GET', [], '');

        $this->assertTrue($response->getResult());
        $this->assertSame('products', $response->getDataKey());
        $this->assertIsArray($response->getData());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter ApiDispatcherTest`
Expected: FAIL — `Class "Miguel\Utils\MiguelApiDispatcher" not found`.

- [ ] **Step 3: Write minimal implementation**

Create `src/utils/miguel-api-dispatcher.php`:

```php
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
 * Central routing/validation for the Miguel public API. Shared by the module
 * front controller and the legacy direct-access scripts. Never echoes: it
 * returns a MiguelApiResponse that the caller serializes.
 */
class MiguelApiDispatcher
{
    /**
     * @var \Miguel
     */
    private $module;

    public function __construct(\Miguel $module)
    {
        $this->module = $module;
    }

    /**
     * @param string $resource one of: orders, products, order-state-callback
     * @param string $method   HTTP method (GET/POST)
     * @param array $get       query parameters
     * @param string $rawBody  raw request body (for POST resources)
     *
     * @return MiguelApiResponse
     */
    public function dispatch($resource, $method, array $get, $rawBody)
    {
        $valid = $this->module->validateApiAccess();
        if ($valid !== true) {
            return $valid;
        }

        switch ($resource) {
            case 'orders':
                if ($method !== 'GET') {
                    return MiguelApiResponse::error(MiguelApiError::methodNotAllowed($method));
                }
                if (!isset($get['updated_since'])) {
                    return MiguelApiResponse::error(MiguelApiError::argumentNotSet('updated_since'));
                }

                return MiguelApiResponse::success($this->module->getUpdatedOrders($get['updated_since']), 'orders');

            case 'products':
                if ($method !== 'GET') {
                    return MiguelApiResponse::error(MiguelApiError::methodNotAllowed($method));
                }

                return MiguelApiResponse::success($this->module->getAllProducts(), 'products');

            case 'order-state-callback':
                if ($method !== 'POST') {
                    return MiguelApiResponse::error(MiguelApiError::methodNotAllowed($method));
                }
                $data = json_decode($rawBody, true);
                if (null === $data) {
                    return MiguelApiResponse::error(MiguelApiError::invalidPayload('payload is required'));
                }
                if (!array_key_exists('code', $data)) {
                    return MiguelApiResponse::error(MiguelApiError::invalidPayload('code not set'));
                }

                return MiguelApiResponse::success($this->module->setOrderStates($data), 'result');

            default:
                return MiguelApiResponse::error(MiguelApiError::resourceNotFound((string) $resource));
        }
    }
}
```

Then in `miguel.php`, add the require alongside the other utils (after the `miguel-api-error.php` require near line 19):

```php
require_once 'src/utils/miguel-api-dispatcher.php';
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter ApiDispatcherTest`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add src/utils/miguel-api-dispatcher.php miguel.php tests/Unit/ApiDispatcherTest.php
git commit -m "feat: add MiguelApiDispatcher for shared API routing"
```

---

## Task 4: Refactor legacy scripts to delegate to the dispatcher

**Files:**
- Modify: `orders.php`, `products.php`, `order-state-callback.php`
- Test (regression, unchanged): `tests/Unit/OrdersTest.php`, `tests/Unit/ProductsTest.php`, `tests/Unit/OrderStateCallbackTest.php`

**Interfaces:**
- Consumes: `MiguelApiDispatcher::dispatch()` (Task 3); `Miguel::readFileContent()`, `Miguel::getUserAgent()`.
- Produces: no new interface — the scripts keep emitting the same JSON envelope. Each script passes a literal HTTP method (they are single-method endpoints) so behavior is unchanged under CLI tests where `$_SERVER['REQUEST_METHOD']` is unset.

- [ ] **Step 1: Run existing tests to confirm green baseline**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter "OrdersTest|ProductsTest|OrderStateCallbackTest"`
Expected: PASS (all existing endpoint tests green before refactor).

- [ ] **Step 2: Refactor `orders.php`**

Replace the body below the license header + top-comment with:

```php
require_once __DIR__ . '/../../config/config.inc.php';
require_once __DIR__ . '/miguel.php';

use Miguel\Utils\MiguelApiDispatcher;

// required thing for PrestaShop validator (needs to be after config.inc.php)
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * @deprecated Use the module front controller instead:
 * index.php?fc=module&module=miguel&controller=api&resource=orders
 * Kept for backward compatibility with older backend behavior; scheduled for
 * removal in a future major version.
 */

$module = Miguel::createInstance();
$context = Context::getContext();
$context->controller = new FrontController();

header('Content-Type: application/json; charset=UTF-8');
header('User-Agent: ' . $module->getUserAgent());

$dispatcher = new MiguelApiDispatcher($module);
$output = $dispatcher->dispatch('orders', 'GET', $_GET, '');

echo json_encode($output, JSON_PRETTY_PRINT);
```

- [ ] **Step 3: Refactor `products.php`**

Replace the body below the license header + top-comment with:

```php
require_once __DIR__ . '/../../config/config.inc.php';
require_once __DIR__ . '/miguel.php';

use Miguel\Utils\MiguelApiDispatcher;

// required thing for PrestaShop validator (needs to be after config.inc.php)
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * @deprecated Use the module front controller instead:
 * index.php?fc=module&module=miguel&controller=api&resource=products
 * Kept for backward compatibility; scheduled for removal in a future major version.
 */

$module = Miguel::createInstance();
$context = Context::getContext();
$context->controller = new FrontController();

header('Content-Type: application/json; charset=UTF-8');
header('User-Agent: ' . $module->getUserAgent());

$dispatcher = new MiguelApiDispatcher($module);
$output = $dispatcher->dispatch('products', 'GET', $_GET, '');

echo json_encode($output, JSON_PRETTY_PRINT);
```

- [ ] **Step 4: Refactor `order-state-callback.php`**

Replace the body below the license header + top-comment with:

```php
require_once __DIR__ . '/../../config/config.inc.php';
require_once __DIR__ . '/miguel.php';

use Miguel\Utils\MiguelApiDispatcher;

// required thing for PrestaShop validator (needs to be after config.inc.php)
if (!defined('_PS_VERSION_')) {
    exit;
}

/*
 * @deprecated Use the module front controller instead:
 * index.php?fc=module&module=miguel&controller=api&resource=order-state-callback
 * Kept for backward compatibility; scheduled for removal in a future major version.
 */

$module = Miguel::createInstance();
$context = Context::getContext();
$context->controller = new FrontController();

header('Content-Type: application/json; charset=UTF-8');
header('User-Agent: ' . $module->getUserAgent());

$dispatcher = new MiguelApiDispatcher($module);
$output = $dispatcher->dispatch('order-state-callback', 'POST', $_GET, $module->readFileContent('php://input'));

echo json_encode($output);
```

- [ ] **Step 5: Run the endpoint tests to verify unchanged behavior**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter "OrdersTest|ProductsTest|OrderStateCallbackTest"`
Expected: PASS — same output as the Step 1 baseline (the `MiguelMock` in `OrderStateCallbackTest` still supplies the body via `readFileContent`, and `validateApiAccess` is still honored).

- [ ] **Step 6: Commit**

```bash
git add orders.php products.php order-state-callback.php
git commit -m "refactor: legacy API scripts delegate to MiguelApiDispatcher"
```

---

## Task 5: Dispatcher front controller

**Files:**
- Create: `controllers/front/api.php`
- Verify: manual `curl` smoke test against the dev docker stack

**Interfaces:**
- Consumes: `MiguelApiDispatcher::dispatch()` (Task 3); `$this->module` (the `Miguel` instance PrestaShop injects); `Miguel::getUserAgent()`, `Miguel::readFileContent()`.
- Produces: URL `index.php?fc=module&module=miguel&controller=api&resource=<resource>` returning the JSON envelope. No PHP interface consumed by later tasks.

**Note on testing:** `initContent()` terminates the request with `exit`, so it cannot be exercised by an in-process PHPUnit test without killing the runner. The routing logic it depends on is fully covered by `ApiDispatcherTest` (Task 3); this task verifies the *wiring* (routing reaches the controller and returns pure JSON) with a `curl` smoke test.

- [ ] **Step 1: Create the controller**

Create `controllers/front/api.php`:

```php
<?php
/**
 * 2023 Servantes
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
if (!defined('_PS_VERSION_')) {
    exit;
}

use Miguel\Utils\MiguelApiDispatcher;

class MiguelApiModuleFrontController extends ModuleFrontController
{
    /**
     * @var bool no customer login required; the bearer token authenticates
     */
    public $auth = false;

    /**
     * @var bool Miguel always calls over HTTPS
     */
    public $ssl = true;

    public function initContent()
    {
        $dispatcher = new MiguelApiDispatcher($this->module);
        $response = $dispatcher->dispatch(
            Tools::getValue('resource'),
            isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET',
            $_GET,
            $this->module->readFileContent('php://input')
        );

        // Discard any buffered theme/partial output so the response is pure JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=UTF-8');
        header('User-Agent: ' . $this->module->getUserAgent());

        echo json_encode($response);
        exit;
    }

    /**
     * Keep the API reachable while the shop is in maintenance mode.
     * The parent implementation renders the 503 maintenance page and exits.
     */
    protected function displayMaintenancePage()
    {
        // intentionally left blank
    }
}
```

- [ ] **Step 2: Bring up the dev stack and install the module**

```bash
docker compose up -d
```

Then in the browser at `http://localhost:8082/admin` (admin folder `admin`), install/enable the **Miguel** module (Module Manager → Upload/enable). This mirrors a real shop; no API token needs to be configured for the smoke test in Step 3.

- [ ] **Step 3: Smoke-test routing (no token → JSON error envelope)**

Run:

```bash
curl -s "http://localhost:8082/index.php?fc=module&module=miguel&controller=api&resource=products"
```

Expected: a JSON body (not an HTML theme page, not a 404), specifically the auth error envelope:

```json
{"result":false,"debug":"","error":{"code":"api_key.not_set","message":"API key not set","data":{"headers":{ ... }}}}
```

Getting JSON back proves the request routed through root `index.php` to the controller and returned a pure JSON envelope — the core fix. (A wrong resource returns `resource.not_found`; a `POST` to `resource=order-state-callback` without a body returns `payload.invalid`.)

- [ ] **Step 4: Commit**

```bash
git add controllers/front/api.php
git commit -m "feat: add MiguelApi front controller for native routing"
```

---

## Task 6: Report endpoint URLs to the backend on connect

**Files:**
- Modify: `miguel.php` (method `getPrestashopDetails()` near line 609)
- Test: `tests/Unit/PrestashopDetailsTest.php`

**Interfaces:**
- Consumes: `$this->context->link->getModuleLink('miguel', 'api', ['resource' => ...], true)`.
- Produces: `getPrestashopDetails()` return array gains an `endpoints` key: `['orders' => <url>, 'products' => <url>, 'orderStateCallback' => <url>]`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/PrestashopDetailsTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel;
use Tests\Unit\Utility\DatabaseTestCase;

class PrestashopDetailsTest extends DatabaseTestCase
{
    public function testIncludesEndpointUrls()
    {
        $module = new Miguel();

        $details = $module->getPrestashopDetails();

        $this->assertArrayHasKey('endpoints', $details);
        $this->assertArrayHasKey('orders', $details['endpoints']);
        $this->assertArrayHasKey('products', $details['endpoints']);
        $this->assertArrayHasKey('orderStateCallback', $details['endpoints']);

        $this->assertStringContainsString('resource=orders', $details['endpoints']['orders']);
        $this->assertStringContainsString('resource=products', $details['endpoints']['products']);
        $this->assertStringContainsString('resource=order-state-callback', $details['endpoints']['orderStateCallback']);
    }

    public function testKeepsLegacyBaseFields()
    {
        $module = new Miguel();

        $details = $module->getPrestashopDetails();

        $this->assertArrayHasKey('baseUrl', $details);
        $this->assertArrayHasKey('baseUri', $details);
        $this->assertArrayHasKey('moduleVersion', $details);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter PrestashopDetailsTest`
Expected: FAIL — `Failed asserting that an array has the key 'endpoints'`.

- [ ] **Step 3: Write minimal implementation**

In `miguel.php`, replace `getPrestashopDetails()` with:

```php
    public function getPrestashopDetails()
    {
        $ps = [];
        $ps['psVersion'] = _PS_VERSION_;
        $ps['moduleVersion'] = $this->version;
        $ps['baseUrl'] = Tools::getShopDomainSsl(true);
        $ps['baseUri'] = __PS_BASE_URI__;
        $ps['endpoints'] = [
            'orders' => $this->context->link->getModuleLink('miguel', 'api', ['resource' => 'orders'], true),
            'products' => $this->context->link->getModuleLink('miguel', 'api', ['resource' => 'products'], true),
            'orderStateCallback' => $this->context->link->getModuleLink('miguel', 'api', ['resource' => 'order-state-callback'], true),
        ];

        return $ps;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit -c tests/Unit/phpunit.xml --filter PrestashopDetailsTest`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add miguel.php tests/Unit/PrestashopDetailsTest.php
git commit -m "feat: report front-controller endpoint URLs on connect"
```

---

## Task 7: Version bump, changelog, packaging

**Files:**
- Modify: `miguel.php` (`$this->version` near line 53)
- Modify: `CHANGELOG.md`
- Modify: `.github/scripts/build-zip.sh` (exclude `docs/` from the shipped zip)

**Interfaces:** none.

- [ ] **Step 1: Bump the module version**

In `miguel.php`, change:

```php
        $this->version = '1.2.3';
```

to:

```php
        $this->version = '1.3.0';
```

- [ ] **Step 2: Add the changelog entry**

At the top of `CHANGELOG.md`, immediately under the `# CHANGELOG` heading, insert:

```markdown
## v1.3.0

Added:

- Native PrestaShop API endpoint via a module front controller (`index.php?fc=module&module=miguel&controller=api&resource=…`), routed through the shop's `index.php` so it is no longer blocked by Cloudflare/Apache rules against direct PHP access in `/modules/`.
- The module now reports its endpoint URLs to Miguel on connect (`endpoints` in the connect payload).
- Fallback API-token transport via the `X-Miguel-Token` header for environments that strip `Authorization`.

Changed:

- The legacy `orders.php`, `products.php`, and `order-state-callback.php` scripts now delegate to the shared dispatcher and are deprecated (kept for backward compatibility).
```

- [ ] **Step 3: Exclude docs from the shipped zip**

In `.github/scripts/build-zip.sh`, add `docs` to the `ignore` list so specs/plans are not packaged. Change:

```bash
ignore=".git .github .idea .vscode .gitignore .php-cs-fixer.dist.php .php-cs-fixer.cache composer.* tests run doc vendor vendor2 docker-compose.yml docker-compose.test.yml Makefile *.zip"
```

to (add `docs` after `doc`):

```bash
ignore=".git .github .idea .vscode .gitignore .php-cs-fixer.dist.php .php-cs-fixer.cache composer.* tests run doc docs vendor vendor2 docker-compose.yml docker-compose.test.yml Makefile *.zip"
```

- [ ] **Step 4: Run the full suite**

Run: `make test`
Expected: the full PHPUnit suite passes (new tests from Tasks 1–3 and 6 plus the unchanged endpoint regression tests).

- [ ] **Step 5: Commit**

```bash
git add miguel.php CHANGELOG.md .github/scripts/build-zip.sh
git commit -m "chore: bump to 1.3.0, changelog, exclude docs from zip"
```

---

## Coordinated backend change (out of scope, tracked here)

For the new routing to be used in production, the Miguel backend (separate repository) must:

1. Prefer the `endpoints` map from the connect payload when present.
2. Fall back to the legacy `/modules/miguel/<script>.php` paths for installs that do not report `endpoints`.

Until the backend ships this, the legacy scripts remain the active path. No PrestaShop-module code depends on the backend change, so this plan is independently shippable.

---

## Self-Review

**Spec coverage:**
- Dispatcher front controller (spec §1) → Task 5.
- Shared dispatcher (spec §2) → Task 3.
- Bearer + `X-Miguel-Token` fallback (spec §3) → Task 2.
- Report endpoint URLs on connect (spec §4) → Task 6.
- Legacy scripts → thin shims (spec §5) → Task 4.
- Testing (spec §6) → Tasks 1–3, 6 (unit) + Task 5 (smoke) + Task 4 (regression).
- Versioning (spec §7) → Task 7.
- New error types `resourceNotFound`/`methodNotAllowed` (spec §2) → Task 1.
- Response contract HTTP 200 + JSON envelope → preserved by dispatcher returning `MiguelApiResponse`; scripts/controller `echo json_encode(...)` with no status code changes.
- Backend coordination note (spec "out of scope") → captured in the "Coordinated backend change" section.

**Type consistency:** `dispatch(string $resource, string $method, array $get, string $rawBody): MiguelApiResponse` used identically in Tasks 3, 4, 5. `getCustomTokenHeader()` defined in Task 2 and referenced nowhere else. Error factories `resourceNotFound`/`methodNotAllowed` defined in Task 1, consumed in Task 3. `endpoints` keys `orders`/`products`/`orderStateCallback` consistent between Task 6 code and test.

**Placeholder scan:** no TBD/TODO; every code step shows complete code; every test step shows the assertion and the exact run command with expected result.
