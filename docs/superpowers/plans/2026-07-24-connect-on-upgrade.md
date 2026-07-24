# Connect on Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a module upgrade, call `POST /v2/eshop/prestashop/connect` once (via PrestaShop's native `upgrade/` mechanism) so Miguel immediately learns the new module version and endpoints.

**Architecture:** Extract the connect call that today lives inline in `Miguel::getContent()` into a reusable public `connectToMiguel()` method, give `curlPost()` an optional request timeout (default off, so existing callers are untouched), and add an `upgrade/upgrade-1.4.0.php` script that calls `connectToMiguel()` once and always reports success.

**Tech Stack:** PHP (PrestaShop 1.7.8 / 8 module, PHP 7.1+ target, tests run on PHP 7.4), PHPUnit, cURL.

## Global Constraints

- **No module version bump** — `Miguel::$version` stays `1.4.0` (it is unreleased). Do NOT edit the `$this->version` line.
- **No new hook, no new settings keys, no version tracking, no throttle** — the upgrade script fires exactly once by design.
- **`curlPost()` default behavior must not change** for existing callers — the new `$timeout` parameter defaults to `0` (off). Existing callers: [miguel.php:521](../../../miguel.php) (`/v2/orders`) and the refactored connect.
- **Non-test PHP files are linted** (php-cs-fixer), **header-stamped** (license header required), and **PHPStan level 5** analyzed (`upgrade/` and `miguel.php` are in scope). Test files under `tests/` are excluded from all three — but still follow existing test style for consistency.
- Tests run **only via Docker**: `make test-docker` (host has no PHP). Filter a single test with `make test-docker ARGS="--filter TestClassName"`.
- Commit style: Conventional Commits (`feat:`, `fix:`, `docs:`, `chore:`), matching git history.

---

### Task 1: Reusable `connectToMiguel()` + optional `curlPost()` timeout

Extract the inline connect into a public method, add an opt-in timeout to `curlPost()`, add the `CONNECT_TIMEOUT` constant, and route `getContent()` through the new method (behavior unchanged there).

**Files:**
- Modify: `miguel.php` (add `CONNECT_TIMEOUT` constant after `HOOKS`; add `$timeout` param + timeout opts to `curlPost()` ~line 579; add `connectToMiguel()` after `getPrestashopDetails()` ~line 667; refactor `getContent()` lines 158-159)
- Test: `tests/Unit/ConnectToMiguelTest.php` (create)

**Interfaces:**
- Consumes: existing `Miguel::getPrestashopDetails(): array` and `Miguel::curlPost(string $uri, array $params, int $timeout = 0)`.
- Produces:
  - `Miguel::CONNECT_TIMEOUT` — `int` constant, value `10`.
  - `Miguel::connectToMiguel(int $timeout = 0)` — POSTs `getPrestashopDetails()` to `/v2/eshop/prestashop/connect`; returns whatever `curlPost()` returns (`string` body on 2xx with a body, `true` on empty 2xx, `false` otherwise). Task 2 relies on this exact name/signature.
  - `Miguel::curlPost(string $uri, array $params, int $timeout = 0)` — when `$timeout > 0`, sets `CURLOPT_CONNECTTIMEOUT` and `CURLOPT_TIMEOUT`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/ConnectToMiguelTest.php`:

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

namespace Tests\Unit;

use Miguel;
use Tests\Unit\Utility\DatabaseTestCase;

class ConnectToMiguelTest extends DatabaseTestCase
{
    public function testPostsPrestashopDetailsToConnectEndpoint()
    {
        $module = new class extends Miguel {
            public $capturedUri;
            public $capturedParams;
            public $capturedTimeout;

            public function curlPost($uri, array $params, $timeout = 0)
            {
                $this->capturedUri = $uri;
                $this->capturedParams = $params;
                $this->capturedTimeout = $timeout;

                return 'connected';
            }
        };

        $result = $module->connectToMiguel(7);

        $this->assertSame('connected', $result);
        $this->assertSame('/v2/eshop/prestashop/connect', $module->capturedUri);
        $this->assertSame(7, $module->capturedTimeout);
        $this->assertEquals($module->getPrestashopDetails(), $module->capturedParams);
        $this->assertArrayHasKey('moduleVersion', $module->capturedParams);
        $this->assertArrayHasKey('endpoints', $module->capturedParams);
    }

    public function testDefaultsToNoTimeout()
    {
        $module = new class extends Miguel {
            public $capturedTimeout = 'unset';

            public function curlPost($uri, array $params, $timeout = 0)
            {
                $this->capturedTimeout = $timeout;

                return true;
            }
        };

        $module->connectToMiguel();

        $this->assertSame(0, $module->capturedTimeout);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test-docker ARGS="--filter ConnectToMiguelTest"`
Expected: FAIL — `Call to undefined method Miguel::connectToMiguel()` (and/or the anonymous class fails to declare `curlPost` compatibly until Step 4 adds the `$timeout` param).

- [ ] **Step 3a: Add the `CONNECT_TIMEOUT` constant**

In `miguel.php`, immediately after the `HOOKS` constant block (the `];` that closes `public const HOOKS = [ ... ];`), add:

```php

    /** Seconds to wait for the connect POST during upgrade so the upgrade screen never hangs. */
    public const CONNECT_TIMEOUT = 10;
```

- [ ] **Step 3b: Add the `$timeout` parameter to `curlPost()`**

In `miguel.php`, update the `curlPost` docblock and signature. Change:

```php
    /**
     * @param string $uri
     * @param array<string, string> $params
     */
    public function curlPost($uri, array $params)
    {
```

to:

```php
    /**
     * @param string $uri
     * @param array<string, string> $params
     * @param int $timeout seconds; 0 disables the explicit timeout (default)
     */
    public function curlPost($uri, array $params, $timeout = 0)
    {
```

Then, immediately after the `curl_setopt_array($curl, [ ... ]);` call inside `curlPost` (the block that sets `CURLOPT_POSTFIELDS => json_encode($params)`), add:

```php
        if ($timeout > 0) {
            curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        }
```

- [ ] **Step 3c: Add the `connectToMiguel()` method**

In `miguel.php`, immediately after the closing brace of `getPrestashopDetails()` (the method that ends with `return $ps;` then `}`), add:

```php
    /**
     * Report this shop's details to Miguel (pairing / connect).
     *
     * @param int $timeout seconds; 0 disables the explicit timeout (default)
     *
     * @return string|bool
     */
    public function connectToMiguel($timeout = 0)
    {
        return $this->curlPost('/v2/eshop/prestashop/connect', $this->getPrestashopDetails(), $timeout);
    }
```

- [ ] **Step 3d: Route `getContent()` through the new method**

In `miguel.php`, inside `getContent()`, replace these two lines:

```php
            $prestashopDetails = $this->getPrestashopDetails();
            $test_key = $this->curlPost('/v2/eshop/prestashop/connect', $prestashopDetails);
```

with:

```php
            $test_key = $this->connectToMiguel();
```

(Leave the surrounding `if (false == $test_key) { ... }` logic unchanged.)

- [ ] **Step 4: Run test to verify it passes**

Run: `make test-docker ARGS="--filter ConnectToMiguelTest"`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full suite (nothing regressed)**

Run: `make test-docker`
Expected: PASS — all pre-existing tests still green (confirms the `getContent()` refactor and `curlPost()` signature change broke nothing).

- [ ] **Step 6: Commit**

```bash
git add miguel.php tests/Unit/ConnectToMiguelTest.php
git commit -m "feat: add reusable connectToMiguel() with optional curlPost timeout

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

### Task 2: Call connect on upgrade + CHANGELOG

Add the upgrade script that fires the connect once, and note it in the changelog.

**Files:**
- Create: `upgrade/upgrade-1.4.0.php`
- Modify: `CHANGELOG.md` (under the existing unreleased `## v1.4.0` section)
- Test: `tests/Unit/UpgradeConnectTest.php` (create)

**Interfaces:**
- Consumes: `Miguel::connectToMiguel(int $timeout = 0)` and `Miguel::CONNECT_TIMEOUT` from Task 1.
- Produces: global function `upgrade_module_1_4_0($module): bool` — calls `$module->connectToMiguel(Miguel::CONNECT_TIMEOUT)` and always returns `true`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/UpgradeConnectTest.php`:

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

namespace Tests\Unit;

use Miguel;
use Tests\Unit\Utility\DatabaseTestCase;

class UpgradeConnectTest extends DatabaseTestCase
{
    public function testUpgradeCallsConnectWithTimeoutAndReturnsTrue()
    {
        require_once __DIR__ . '/../../upgrade/upgrade-1.4.0.php';

        $module = new class extends Miguel {
            public $connectCalledWith = 'not-called';

            public function connectToMiguel($timeout = 0)
            {
                $this->connectCalledWith = $timeout;

                return 'ok';
            }
        };

        $result = upgrade_module_1_4_0($module);

        $this->assertTrue($result);
        $this->assertSame(Miguel::CONNECT_TIMEOUT, $module->connectCalledWith);
    }

    public function testUpgradeSucceedsEvenWhenConnectFails()
    {
        require_once __DIR__ . '/../../upgrade/upgrade-1.4.0.php';

        $module = new class extends Miguel {
            public function connectToMiguel($timeout = 0)
            {
                return false; // Miguel unreachable / API not configured
            }
        };

        $this->assertTrue(upgrade_module_1_4_0($module));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make test-docker ARGS="--filter UpgradeConnectTest"`
Expected: FAIL — `failed to open stream` / `require ... upgrade-1.4.0.php` does not exist (file not created yet).

- [ ] **Step 3: Create the upgrade script**

Create `upgrade/upgrade-1.4.0.php` (license header copied verbatim from the existing `upgrade/upgrade-1.0.1.php` so header-stamp stays green):

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
 *  @author Pavel Vejnar <vejnar.p@gmail.com>
 *  @copyright  2022 - 2023 Servantes
 *  @license LICENSE.txt
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Notify Miguel once, right after the module is upgraded, so it learns the new
 * module version and endpoints without waiting for an admin to open the config page.
 *
 * @param Miguel $module
 *
 * @return bool always true — a failed connect must not block the module upgrade
 */
function upgrade_module_1_4_0($module)
{
    $module->connectToMiguel(Miguel::CONNECT_TIMEOUT);

    return true;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make test-docker ARGS="--filter UpgradeConnectTest"`
Expected: PASS (2 tests).

- [ ] **Step 5: Update the CHANGELOG**

In `CHANGELOG.md`, under the existing `## v1.4.0` heading and directly above the current `Changed:` line, insert an `Added:` block:

```markdown
Added:

- On module upgrade, the module now calls the Miguel connect endpoint (`POST /v2/eshop/prestashop/connect`) once via PrestaShop's upgrade mechanism, so Miguel learns the new module version and endpoint URLs without waiting for an admin to open the module configuration page.

```

- [ ] **Step 6: Run the full suite**

Run: `make test-docker`
Expected: PASS — all tests green.

- [ ] **Step 7: Commit**

```bash
git add upgrade/upgrade-1.4.0.php tests/Unit/UpgradeConnectTest.php CHANGELOG.md
git commit -m "feat: connect to Miguel on module upgrade

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>"
```

---

## Notes for the implementer

- **PHPStan level 5** analyzes `miguel.php` and `upgrade/`. The `@param Miguel $module` docblock on `upgrade_module_1_4_0` is what lets PHPStan resolve `$module->connectToMiguel(...)`. Keep it.
- **php-cs-fixer** (PrestaShop ruleset) checks `miguel.php` and `upgrade/` but NOT `tests/`. Match the existing formatting (4-space indent, opening brace on its own line for functions/methods, one blank line between methods). These style checks cannot run locally (no host PHP); rely on matching existing files and on CI.
- Anonymous test doubles override `curlPost`/`connectToMiguel` with signatures that must stay compatible with the parent (`curlPost($uri, array $params, $timeout = 0)`), which is why Task 1 must land the new `curlPost` signature before its own test can pass.
