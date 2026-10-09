# SMP-20 — Report caught errors to Miguel — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The module reports the errors it catches to Miguel (`POST /v2/eshop/errors`), which forwards them to GlitchTip.

**Architecture:** One new class, `Miguel\Utils\MiguelErrorReporter` (`src/utils/miguel-error-reporter.php`), owns the buffer
(one global `Configuration` row), report building within the contract's limits, and the flush (its own cURL call, never
`curlPost`, so a failed send is never itself reported). `Miguel::curlGet` / `curlPost` classify every failed call into a
shared code; the module's logic catch sites (order sync, product export) report their own codes. Flushing runs from the
`actionCronJob` hook (PrestaShop's "Cron tasks manager" module) and after a successful Miguel call made in the back office —
never in a front-office request, so never on the checkout path.

**Tech Stack:** PHP ≥ 7.1 (tests run on 7.4), PrestaShop 1.7–9, PHPUnit via `scripts/prestashop_phpunit_docker.sh`
(filtered while developing: `make test-docker ARGS="--filter X"`).

**Spec:** the approved SMP-20 criteria (in the pull request body) and miguel's `docs/client-errors.md` (the contract).

## Global Constraints

- `reports` 1–20 per request, body ≤ 64 KB; `code` `^[A-Z][A-Z0-9_]{2,63}$`; `message` ≤ 1024; `operation` ≤ 200;
  `httpStatus` 100–599; `responseExcerpt` ≤ 2048; `context` ≤ 20 entries, key ≤ 64, value a string ≤ 256. Lengths are
  counted as Miguel (.NET) counts them: UTF-16 code units. One invalid report gets the whole batch refused with a 400.
- Buffer: at most 50 reports in one `Configuration` row, oldest dropped. Send: timeout ≤ 5 s, API key whenever there is
  one, the usual `MiguelForPrestashop/…` User-Agent.
- 202 → remove sent; 400 → drop sent; 429 → keep, wait `Retry-After` s; 5xx / network → keep.
- Nothing may throw into the caller; a failed send is never reported; no customer e-mail, name or address in `context`.

## Facts this design rests on (checked in PrestaShop 1.7.8)

- `Configuration::updateValue($k, $v, $html = false)` runs the value through `Db::escape`, which does
  `strip_tags(Tools::nl2br(…))`; `$html = true` runs `Tools::purifyHTML` instead. Neither is safe for raw JSON, so the
  buffer is stored with `html = false` as `json_encode(…, JSON_HEX_TAG)`: no `<`, `>` and (json_encode's
  own escaping) no raw newline, so both transforms are no-ops. The default `\uXXXX` escaping keeps it ASCII, which also
  survives a pre-utf8mb4 table.
- `configuration.value` is `TEXT` (65 535 bytes) and PrestaShop sets `sql_mode = ''`, so an over-long value is silently
  truncated into invalid JSON. The buffer therefore also drops its oldest entries until it encodes to ≤ 60 000 bytes.
  That cap also bounds every request below Miguel's 64 KB: a batch is part of the buffer, sent without the ids and
  without the `\uXXXX`, `\/` and tag escaping, so no separate per-request byte cut is needed.
- `Configuration::get*` answers from a per-request cache. The flush re-reads the row with a direct query before removing
  what it sent, so reports added by another request meanwhile are kept.
- `_PS_ADMIN_DIR_` is defined by the back office's `index.php` (legacy and Symfony pages alike), never in the front office;
  the test bootstrap does not define it, so the check sits in an overridable `Miguel::isBackOfficeRequest()`.
- The "Cron tasks manager" module (`cronjobs`) calls `hookActionCronJob` on modules hooked to `actionCronJob`, at the
  frequency `getCronFrequency()` returns. `registerHook` creates the hook when it does not exist. 1.4.0 is released, so
  existing shops get the hook from `upgrade/upgrade-1.5.0.php` and the module becomes 1.5.0.

## Review Focus

1. Miguel answers with an HTML error page or a body full of `<`/newlines → the report survives the `Configuration`
   round-trip intact (test: excerpt `<html>\n<b>x</b>` round-trips).
2. A message or excerpt with emoji / invalid UTF-8 → truncated in UTF-16 units, valid JSON, batch not refused
   (test: emoji message at the limit, invalid byte sequence).
3. The customer e-mail in `GET /v2/orders?userEmail=…` → `operation` is `GET /v2/orders`, the query never reaches a
   report (test).
4. A rejected key disables the module (`getContent` does `setEnabled(false)`) → the flush still sends the
   `MIGUEL_AUTH_REJECTED` report with that key (test: flush with API disabled sends).
5. Fifty large reports → the stored row stays under the TEXT limit and still decodes (test).

---

## File structure

| File | Change |
|---|---|
| `src/utils/miguel-error-reporter.php` | new: `MiguelErrorReporter` (buffer, build, classify, flush) |
| `miguel.php` | require the class; `actionCronJob` hook + `getCronFrequency`; classify failures in `curlGet`/`curlPost`; flush after a back-office success; catch sites in `hookActionOrderStatusUpdate`, `getAllProducts`, `fetchMiguelOrdersByCode`; drop the buffer rows on uninstall; version 1.5.0 |
| `upgrade/upgrade-1.5.0.php` | new: register `actionCronJob` |
| `tests/Unit/ErrorReporterTest.php` | new: buffer, limits, uninstall |
| `tests/Unit/ErrorReporterFlushTest.php` | new: flush against a stub server |
| `tests/Unit/ErrorReportingCallsTest.php` | new: the call sites and catch sites |
| `tests/Unit/Utility/MiguelStubServer.php`, `tests/Unit/Utility/miguel-stub-router.php` | new: `php -S` stub of Miguel that records requests and answers from a script |
| `tests/Unit/bootstrap.php` | require the stub server helper |
| `CHANGELOG.md` | `## v1.5.0` |

## Interfaces

```php
namespace Miguel\Utils;
class MiguelErrorReporter {
    const BUFFER_KEY = 'MIGUEL_ERROR_REPORTS';            // JSON list of {id, report}, oldest first
    const RETRY_AT_KEY = 'MIGUEL_ERROR_REPORTS_RETRY_AT'; // unix time before which nothing is sent
    const ENDPOINT = '/v2/eshop/errors';
    const BUFFER_MAX = 50; const BUFFER_MAX_BYTES = 60000;
    const BATCH_MAX = 20; const TIMEOUT = 5; const RETRY_DEFAULT = 60;
    public static function report($code, $message, array $details = []): void;   // never throws
    public static function reportFailedCall($method, $uri, $httpStatus, $body, $curlError): void; // shared codes
    public static function reportBadResponse($method, $uri, $body, $problem): void; // UNPARSABLE or INVALID
    public static function getBuffer(): array;                          // entries, oldest first
    public static function flush(\Miguel $module): void;                // one batch; never throws
    public static function deleteAll(): void;                           // uninstall
}
// Miguel
public function hookActionCronJob($params = []);   // flush
public function getCronFrequency();                // hourly
protected function isBackOfficeRequest();          // defined('_PS_ADMIN_DIR_')
```

`reportFailedCall` decides the code: `$curlError !== ''` or status 0 → `MIGUEL_UNREACHABLE` (message = cURL's error);
401/403 → `MIGUEL_AUTH_REJECTED`; any other ≥ 400 → `MIGUEL_HTTP_ERROR`; anything else → nothing. `operation` is
`METHOD /path` with the query string cut off.

## Task 1: Stub server + the reporter's buffer and report building

- [ ] Write `ErrorReporterTest` cases (failing: class missing): report stores `{code, message, occurredAt}` with
  `occurredAt` ISO-8601; invalid code ignored; empty message falls back to the code; 51 reports keep the newest 50;
  limits truncate in UTF-16 units (emoji at the limit); invalid UTF-8 is cleaned; non-scalar context values dropped,
  context capped at 20; `httpStatus` outside 100–599 dropped; an `<html>` excerpt with newlines round-trips; fifty
  maximal reports stay ≤ `BUFFER_MAX_BYTES` and decode; a corrupt row reads as an empty buffer and `report` still works.
- [ ] Run `make test-docker ARGS="--filter ErrorReporterTest"` → fails (class not found). Keep the output.
- [ ] Implement the class (buffer, build, truncate) and require it from `miguel.php`; drop the two rows in `uninstall`.
- [ ] Run the filter → passes.

## Task 2: Flush

- [ ] (`tests/Unit/ErrorReporterFlushTest.php`) Add `MiguelStubServer` (start `PHP_BINARY -S 127.0.0.1:<free port>` with the router, wait until it accepts,
  stop in `tearDownAfterClass`; `respond(status, body, headers)` queues answers; `requests()` returns recorded
  method/uri/headers/body) and point the module at it with `ENV_OWN`.
- [ ] Failing tests: 202 removes the sent reports and keeps those added meanwhile; sends ≤ 20 in arrival order with
  `Authorization: Bearer <key>`, the module's User-Agent and the original `occurredAt`; no key → no Authorization; 400
  drops; 429 keeps and sets the retry time from `Retry-After` (and a second flush before it sends nothing); 500 keeps;
  unreachable server keeps and reports nothing new; a send slower than 5 s is abandoned and kept; the module's API
  being disabled does not stop the flush; a full buffer of maximal reports sends ≤ 64 KB; empty buffer sends nothing.
- [ ] Implement `flush` / `takeBatch` / `remove` (fresh read by direct query) / `send` (cURL, `CURLOPT_TIMEOUT` and
  `CURLOPT_CONNECTTIMEOUT` = 5, `CURLOPT_HEADERFUNCTION` for `Retry-After`).
- [ ] Filter passes.

## Task 3: Call sites, catch sites, cron

- [ ] Failing `ErrorReportingCallsTest`: `curlPost` against an unreachable port → `MIGUEL_UNREACHABLE` with operation
  `POST /v2/orders`; 401 → `MIGUEL_AUTH_REJECTED` with `httpStatus` and excerpt; 500 → `MIGUEL_HTTP_ERROR`; `curlGet`
  of `/v2/orders?userEmail=…` reports operation `GET /v2/orders` with no e-mail anywhere in the report; a non-JSON
  200 on the purchased-books fetch → `MIGUEL_RESPONSE_UNPARSABLE`, JSON without `data` → `MIGUEL_RESPONSE_INVALID`; a
  successful call flushes in the back office and does not in the front office; a throwing order build inside
  `hookActionOrderStatusUpdate` does not escape and reports `ORDER_SYNC_FAILED` with `orderId` only; a throwing
  product export reports `PRODUCT_EXPORT_FAILED`; `hookActionCronJob` flushes; `actionCronJob` is in `HOOKS`;
  `upgrade_module_1_5_0` registers it and returns true even when that fails.
- [ ] Implement in `miguel.php`; add `upgrade/upgrade-1.5.0.php`; version 1.5.0; CHANGELOG.
- [ ] Whole suite: `scripts/prestashop_phpunit_docker.sh` passes.

## Codes this pull request adds (to be listed under "Prestashop" in miguel's `docs/client-errors.md`)

- `ORDER_SYNC_FAILED` — sending an order to Miguel threw inside the order-status hook (context `orderId`).
- `PRODUCT_EXPORT_FAILED` — building the product list Miguel pairs products from threw.

The module has no download-link code of its own (the purchased-books page shows Miguel's links), so no download codes.
