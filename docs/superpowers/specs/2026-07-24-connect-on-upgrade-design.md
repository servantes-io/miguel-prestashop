# Connect on upgrade — design

**Date:** 2026-07-24
**Status:** Approved
**Module version:** 1.4.0 (unreleased — no version bump for this change)

## Goal

When a shop upgrades the Miguel module to a new version, notify Miguel **once**
by calling `POST /v2/eshop/prestashop/connect`, so Miguel immediately learns the
new `moduleVersion` and reported `endpoints` — without waiting for an admin to
open the module's configuration page.

## Background

The module already knows how to connect. `Miguel::getContent()`
([miguel.php:157-166](../../../miguel.php)) posts `getPrestashopDetails()` (which
carries `psVersion`, `moduleVersion`, `baseUrl`, `baseUri`, and the module's
`endpoints`) to `/v2/eshop/prestashop/connect` and treats a non-`false` response
as a successful pairing. Today this is the **only** trigger: Miguel only hears
about a new module version whenever an admin happens to open the config page.

`Miguel::curlPost()` already guards against an unconfigured or disabled API
(returns `false` without a network call), so calling connect when the shop is not
set up is safe and cheap.

PrestaShop runs `upgrade/upgrade-X.Y.Z.php` scripts (function
`upgrade_module_X_Y_Z($module)`) automatically, exactly once, when a shop's
installed module version crosses that version. The repo already uses this
mechanism ([upgrade/upgrade-1.0.1.php](../../../upgrade/upgrade-1.0.1.php)).

## Approach

Trigger the connect from PrestaShop's native upgrade mechanism — **one time, via
`upgrade/`**. No new hook, no runtime version tracking, no throttle: the upgrade
script fires exactly once per version bump by design.

### 1. Upgrade script

Add `upgrade/upgrade-1.4.0.php`:

```php
function upgrade_module_1_4_0($module)
{
    $module->connectToMiguel(Miguel::CONNECT_TIMEOUT);

    return true; // Always succeed — a connect failure must NOT block the upgrade.
}
```

PrestaShop runs this once when a shop upgrades from an already-released version
(e.g. 1.3.x) up to 1.4.0. On such an upgrade the merchant's API key already
exists in `Configuration` and persists across the upgrade, so the connect
actually succeeds and Miguel receives the fresh details.

The function returns `true` unconditionally: whether or not the connect
succeeds, the module upgrade itself must complete.

### 2. Reusable connect method

Extract the connect currently inlined in `getContent()` into a public method on
the module:

```php
public function connectToMiguel($timeout = 0)
{
    $details = $this->getPrestashopDetails();

    return $this->curlPost('/v2/eshop/prestashop/connect', $details, $timeout);
}
```

`getContent()` is refactored to call `connectToMiguel()` in place of its inline
`curlPost(...)`, preserving its exact current behavior (it calls with no timeout
argument, i.e. the existing default). The upgrade script reuses the same method.

### 3. curlPost timeout parameter

Add an optional `$timeout` parameter to `curlPost()`:

```php
public function curlPost($uri, array $params, $timeout = 0)
```

- Default `0` keeps the **current behavior unchanged** for every existing caller
  (order sync, config-page connect, etc.).
- When `$timeout > 0`, set `CURLOPT_CONNECTTIMEOUT` and `CURLOPT_TIMEOUT` so a
  slow or dead Miguel cannot hang the caller.

Define `const CONNECT_TIMEOUT = 10;` on the module (seconds), used by the upgrade
path so the upgrade screen never hangs on an unreachable Miguel.

## Out of scope (explicitly not doing)

- **No** new hook (an earlier iteration considered a back-office
  `actionAdminControllerSetMedia` hook — dropped).
- **No** new `Configuration`/settings keys, no stored "connected version", no
  throttle — the upgrade mechanism already fires exactly once.
- **No** module version bump — stays `1.4.0`. A one-line note goes into the
  existing unreleased v1.4.0 CHANGELOG section.
- **Fresh installs** are unaffected. `install()` does not run upgrade scripts, so
  a brand-new install still connects via the config page as it does today. This
  feature targets *upgrades*, matching the goal.

## Edge cases

| Situation | Behavior |
|-----------|----------|
| API key not set / module disabled at upgrade time | `curlPost` no-ops (returns `false`); `upgrade_module_1_4_0` still returns `true`; upgrade succeeds. |
| Miguel down or slow at upgrade time | `CONNECT_TIMEOUT` bounds the wait; upgrade still returns `true` and succeeds. |
| Connect succeeds | Miguel receives current `getPrestashopDetails()` (version + endpoints). |

## Testing

- **Unit** — `connectToMiguel()` posts to `/v2/eshop/prestashop/connect` with the
  `getPrestashopDetails()` payload, using the existing `MiguelMock` /
  `ContextMocker` patterns in [tests/Unit/](../../../tests/Unit/) (cf.
  `PrestashopDetailsTest`, `MiguelTest`).
- **Unit** — `curlPost()`'s `$timeout` default leaves existing behavior intact
  (existing callers unchanged); a positive timeout is accepted. (Cover to the
  extent the current cURL-mocking approach allows; otherwise assert the method
  signature/default contract.)
- The `upgrade/upgrade-1.4.0.php` wrapper needs the PrestaShop upgrade harness to
  execute, so its logic is covered through the extracted `connectToMiguel()`
  method rather than the thin wrapper.
- Run via `make test-docker`.

## Files touched

- `miguel.php` — extract `connectToMiguel()`; add `$timeout` to `curlPost()`; add
  `CONNECT_TIMEOUT` constant; route `getContent()` through the new method.
- `upgrade/upgrade-1.4.0.php` — new; calls `connectToMiguel()`, returns `true`.
- `CHANGELOG.md` — one line under the existing unreleased v1.4.0 section.
- `tests/Unit/…` — test for `connectToMiguel()`.
