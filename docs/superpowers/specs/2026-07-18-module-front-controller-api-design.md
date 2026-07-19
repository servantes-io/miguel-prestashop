# PrestaShop-native API endpoints via a module front controller

**Date:** 2026-07-18
**Status:** Approved (design)
**Module:** miguel (PrestaShop)

## Problem

The module exposes three public API endpoints for the Miguel backend as
**direct-access PHP scripts** in the module root:

- `order-state-callback.php` — POST; Miguel changes PrestaShop order states.
- `orders.php` — GET; returns orders updated since a timestamp.
- `products.php` — GET; returns the product catalog.

They are reached at URLs like `https://shop.com/modules/miguel/orders.php` and kept
reachable by an `.htaccess` allowlist that re-permits exactly those three files after
denying all other `.php` in the module directory.

This is the classic "executable PHP inside `/modules/`" pattern that Cloudflare WAF
rules and hardened Apache / hosting configurations routinely block. The module's own
`.htaccess` allowlist cannot override a Cloudflare managed rule or a server-level
`<Directory>` deny, so the endpoints intermittently become **inaccessible**.

## Goal

Serve the same three endpoints through PrestaShop's native routing so requests go
through the shop's root `index.php` dispatcher — the one entry point that cannot be
blocked without taking down the whole shop — while keeping existing installs working
during the transition.

## Approaches considered

1. **Module front controller** — *chosen.* PrestaShop's equivalent of WordPress
   `register_rest_route`. A registered controller class reached through root
   `index.php`. Sidesteps the "PHP in modules is forbidden" problem entirely.
2. **PrestaShop Webservice API (`/api/`)** — rejected. Heavyweight, models resources
   its own way, uses WS keys rather than the existing bearer token, does not fit the
   custom callback logic, and `/api/` is itself frequently blocked.
3. **Infra-only allowlisting (Cloudflare/Apache)** — rejected. The current approach;
   fragile, per-customer, and not owned by the module.

## Key constraint

The Miguel backend currently **hardcodes the endpoint paths**: on connect the module
sends `baseUrl` + `baseUri` (see `getPrestashopDetails()`), and the backend appends
`/modules/miguel/orders.php` etc. itself. Therefore the new front-controller URLs will
not be called until the backend also changes. The chosen migration makes the module
**authoritative about its own routing** by reporting its endpoint URLs on connect, so
future routing changes never again require a backend change.

## Design

### 1. Dispatcher front controller

**File:** `controllers/front/api.php` → `class MiguelApiModuleFrontController extends ModuleFrontController`

- `public $auth = false;` — no customer login; the bearer token authenticates.
- `public $ssl = true;` — Miguel always calls over HTTPS.
- Routes on a `resource` query parameter → `orders` | `products` |
  `order-state-callback`, enforcing the correct HTTP method per resource
  (GET for `orders`/`products`, POST for `order-state-callback`).
- Overrides `initContent()` to emit **raw JSON and `exit`** — no Smarty/theme
  rendering, mirroring the legacy scripts.
- Overrides `displayMaintenancePage()` to a no-op so the API keeps working while the
  shop is in maintenance mode (a raw script ignores maintenance; a front controller
  normally would not — this preserves today's behavior).

**New URLs** (both produced by `getModuleLink`, both work):

- `https://shop.com/index.php?fc=module&module=miguel&controller=api&resource=orders`
  (always works, no URL rewriting required)
- `https://shop.com/module/miguel/api?resource=orders` (pretty form, when rewriting is on)

No `hookModuleRoutes` / custom route registration is needed — the
`index.php?fc=module&module=miguel&controller=api` form works out of the box.

### 2. Shared dispatcher (extracted, testable)

**File:** `src/utils/miguel-api-dispatcher.php` → `class MiguelApiDispatcher`

```php
dispatch(string $resource, string $method, array $get, string $rawBody): MiguelApiResponse
```

Responsibilities:

1. Validate access via `Miguel::validateApiAccess()`.
2. Enforce the HTTP method for the resource.
3. Route to the existing module methods: `getUpdatedOrders($updated_since)`,
   `getAllProducts()`, `setOrderStates($data)`.
4. Return a `MiguelApiResponse` — **never echoes**.

Both the front controller and the legacy scripts call this, giving one source of truth
for routing/validation that is unit-testable without output buffering.

**New error types** in `MiguelApiError`:

- `resourceNotFound` — unknown/absent `resource`.
- `methodNotAllowed` — wrong HTTP method for the resource.

### 3. Auth: Bearer + `X-Miguel-Token` fallback

`getBearerToken()` gains a fallback: when the `Authorization` bearer is absent or
stripped by a proxy, read the same token from the `X-Miguel-Token` header
(`$_SERVER['HTTP_X_MIGUEL_TOKEN']`, using the existing `getallheaders()` polyfill).
`validateApiAccess()` is otherwise unchanged.

The response contract stays **HTTP 200 + JSON envelope** (never real HTTP error status
codes) so the existing backend parser is unaffected.

### 4. Report endpoint URLs on connect

`getPrestashopDetails()` adds an `endpoints` map built with
`getModuleLink('miguel', 'api', ['resource' => …], true)`:

```json
"endpoints": {
  "orders": "https://shop/index.php?fc=module&module=miguel&controller=api&resource=orders",
  "products": "https://shop/index.php?fc=module&module=miguel&controller=api&resource=products",
  "orderStateCallback": "https://shop/index.php?fc=module&module=miguel&controller=api&resource=order-state-callback"
}
```

`baseUrl` / `baseUri` remain for backward compatibility.

**Coordinated backend change (separate repository, out of scope for this module's
implementation but required for the new routes to be used):** prefer `endpoints` when
present; fall back to the legacy `/modules/miguel/*.php` paths for installs that do not
report them.

### 5. Legacy scripts → thin shims (kept)

`orders.php`, `products.php`, and `order-state-callback.php` remain reachable via the
current `.htaccess` allowlist and are reduced to thin entry points that delegate to
`MiguelApiDispatcher`. They are marked `@deprecated` and stay for old-backend /
mid-transition installs, scheduled for removal in a future major version. The
`.htaccess` allowlist stays for now.

### 6. Testing

- New unit tests for `MiguelApiDispatcher`: resource routing, method enforcement,
  unknown resource, and `X-Miguel-Token` fallback — direct return-value assertions.
- Existing `OrdersTest` / `ProductsTest` / `OrderStateCallbackTest` keep passing
  unchanged (the legacy scripts still exist and still emit the same output).
- A controller-level smoke test hitting
  `index.php?fc=module&module=miguel&controller=api` against the dockerized PrestaShop,
  covering PS 1.7 / 8 / 9.

### 7. Versioning

Minor feature → bump `1.2.3` → **`1.3.0`**; update `CHANGELOG.md` and the `$this->version`
constant in `miguel.php`.

## Decisions locked

- **Controller shape:** single dispatcher controller (not three separate controllers),
  routing on `resource` — closest to `register_rest_route` with a router.
- **Resource keys:** exactly `orders`, `products`, `order-state-callback` (the backend
  will hardcode these strings).
- **Migration/compatibility:** report URLs on connect **and** keep the legacy scripts as
  fallback shims during a deprecation window (no flag day).
- **Auth transport:** `Authorization: Bearer` primary + `X-Miguel-Token` custom-header
  fallback.
- **Response contract:** HTTP 200 + JSON envelope preserved (no real status codes).

## Out of scope

- The Miguel backend change to prefer reported `endpoints` (separate repository;
  noted here as a required dependency).
- Removing the legacy scripts and the `.htaccess` allowlist (future major version).
- Pretty-URL route customization via `hookModuleRoutes`.
