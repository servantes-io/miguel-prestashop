# Fetch a single order by its code — design

## Goal

Add an API endpoint that returns **one** order identified by its PrestaShop
reference ("code"). It complements the existing `orders` (`updated_since`)
listing: instead of returning every order changed since a date, it targets a
single known order.

## Decisions (settled during brainstorming)

- **Dedicated resource** `order` (not an extension of `orders`). The `orders`
  resource is left untouched.
- **Response is a single `order` object** (not an array).
- **Not found → error envelope** with a new `order.not_found` code.
- **Ambiguous code** (a reference that maps to multiple `orders` rows — e.g. a
  checkout split across carriers/suppliers) → return the **newest matching order
  that has Miguel products**.
- **No version bump.** `1.3.0` is unreleased, so this ships as part of it; the
  changelog note goes under the existing `## v1.3.0` section.
- **Order objects gain an `id` field** (integer PrestaShop order id) — added in
  the shared builder, so it appears in every order payload.
- **Order objects gain structured `billing_address` and `shipping_address`**
  objects (shaped after the Miguel-v2 `OrderAddressModel`, in the module's
  snake_case convention). Also added in the shared builder. The existing
  flattened `user.address` string is **kept** (the backend model requires it);
  this is additive.
- **Product lines gain a `quantity`** field (the order line's `product_quantity`).
  The backend `PrestashopOrder.ProductModel` already declares `quantity`
  (defaulting to 1); the module simply starts emitting it.

## Request contract

- **Resource:** `order`
- **Method:** `GET` only (non-GET → `method.not_allowed`).
- **Query param:** `code` (required) — the order reference, e.g. `XKBKNABJK`.
  Missing → `argument.not_set` (`Argument code not set`).
- **URL (recommended, front-controller):**
  `{shop}/index.php?fc=module&module=miguel&controller=api&resource=order&code=XKBKNABJK`
  (friendly-URL form: `{shop}/module/miguel/api?resource=order&code=XKBKNABJK`).
- **Front-controller only** — unlike `orders` / `products` /
  `order-state-callback`, there is **no** deprecated direct-access script
  (`order.php`). This resource is new, so there is no backward-compatibility
  surface to preserve.
- **Auth:** identical to every other endpoint — bearer token or `X-Miguel-Token`
  header, enforced by `validateApiAccess()` before dispatch.

## Response contract

All responses are HTTP 200 with the standard `{ result, debug, <key> }` envelope.

**Found:**

```json
{
  "result": true,
  "debug": "",
  "order": {
    "id": 5002,
    "code": "XKBKNABJK",
    "user": {
      "id": "42",
      "full_name": "Jan Novák",
      "email": "jan@example.com",
      "address": "Jan Novák, Václavské náměstí 1, 11000, Praha, CZ",
      "lang": "cs"
    },
    "billing_address": {
      "full_name": "Jan Novák",
      "company": null,
      "address1": "Václavské náměstí 1",
      "address2": null,
      "city": "Praha",
      "state": null,
      "zip": "11000",
      "country": "CZ",
      "phone": "+420123456789"
    },
    "shipping_address": {
      "full_name": "Jan Novák",
      "company": null,
      "address1": "Václavské náměstí 1",
      "address2": null,
      "city": "Praha",
      "state": null,
      "zip": "11000",
      "country": "CZ",
      "phone": "+420123456789"
    },
    "purchase_date": "2024-01-15T10:30:00+0000",
    "update_date": "2024-01-16T08:05:00+0000",
    "paid": true,
    "currency_code": "CZK",
    "products": [
      { "code": "9788024271101", "quantity": 2, "price": { "regular_without_vat": 199.0, "sold_without_vat": 149.0 } }
    ]
  }
}
```

The `order` object is byte-for-byte the same shape as an entry in the `orders`
list (the `getUpdatedOrders` flag is reused solely to include `update_date`),
plus the new top-level `id`, `billing_address`, and `shipping_address`.
`billing_address` is always present for a returned order (its source, the
invoice address, is already required by the builder); `shipping_address` is
`null` when the order has no loadable delivery address.

**Not found** (no matching row, or no matching row has Miguel products):

```json
{ "result": false, "debug": "", "error": { "code": "order.not_found", "message": "Order XKBKNABJK not found" } }
```

**Missing `code` param:**

```json
{ "result": false, "debug": "", "error": { "code": "argument.not_set", "message": "Argument code not set" } }
```

## Resolution logic — `Miguel::getOrderByCode(string $code)`

Returns `array<string,mixed>|false`.

1. `SELECT id_order FROM {prefix}orders WHERE reference = pSQL($code) ORDER BY id_order DESC`
   (newest first, deterministic).
2. Zero rows → return `false`.
3. Iterate rows newest→oldest, calling the existing
   `createOrderDetailArray(['id_order' => $id, 'getUpdatedOrders' => true])`.
   Return the **first** result that is not `false` (the newest matching order
   that actually contains Miguel products).
4. Every match returns `false` (no Miguel products anywhere) → return `false`.

This folds three cases into one clean "not found": unknown reference, order
without Miguel products, and — via newest-first selection — the shared-reference
(split-order) case.

## The `id` field (shared builder change)

In `createOrderDetailArray()`, add as the first key:

```php
$body_orders['id'] = (int) $order->id;
```

Because this builder is shared, `id` appears in **all three** consumers:

- the new `order` response,
- the `orders` / `updated_since` listing (as requested),
- the outbound `POST /v1/orders` push in `hookActionOrderStatusUpdate` (the push
  payload gains `id` too — intended, for consistency).

Type is **integer**; note the sibling `user.id` remains a stringified customer
id (pre-existing, unchanged).

## Structured billing & shipping addresses (shared builder change)

Add two top-level objects to the order payload, siblings of `user`, shaped after
the Miguel-v2 `OrderAddressModel` but in the module's snake_case convention:

| Field | Source (PrestaShop `Address`) |
|-------|-------------------------------|
| `full_name` | `firstname` + ' ' + `lastname` (trimmed) |
| `company` | `company` |
| `address1` | `address1` |
| `address2` | `address2` |
| `city` | `city` |
| `state` | `State($id_state)->name` (null when no state) |
| `zip` | `postcode` |
| `country` | `Country($id_country)->iso_code` (e.g. `CZ`) |
| `phone` | `phone`, falling back to `phone_mobile` |

- **Billing** ← `id_address_invoice` (already loaded in the builder today for the
  flattened `user.address`). **Shipping** ← `id_address_delivery` (newly loaded).
- Empty strings are coerced to `null` (mirrors the model's nullable `string?`
  fields). An address whose `Address` object does not load (only possible for
  shipping — invoice is already required) serializes as `null`.
- The flattened `user.address` string is **unchanged and retained** (the backend
  `PrestashopOrder.UserModel.Address` is a required field).

Implementation: a new static helper
`MiguelApiCreateOrderRequest::structureAddress(?\Address $address): ?array`
(co-located with the existing `composeAddress()`), called twice in
`createOrderDetailArray()`:

```php
$body_orders['billing_address'] = MiguelApiCreateOrderRequest::structureAddress($address_invoice);
$body_orders['shipping_address'] = MiguelApiCreateOrderRequest::structureAddress(new Address($order->id_address_delivery));
```

Like `id`, these flow into the `order` response, the `orders` list, and the
outbound `/v1/orders` push (extra fields, ignored by the v1 endpoint if unmapped).

## Product line quantity (order-item builder change)

Each product line gains a `quantity` field, sourced from the order line's
`product_quantity` (available from `OrderDetail::getList`, which is
`SELECT *`).

- `MiguelApiCreateOrderItem` gets a `quantity` constructor argument, a
  `quantity` key in `jsonSerialize()`, and a `getQuantity()` accessor.
- In `MiguelApiCreateOrderRequest::createProductsArray()`, pass
  `(int) $product['product_quantity']` when building the item — for a simple
  product and for **every** pack-expanded sub-item (the sub-items inherit the
  pack line's quantity). Prices remain per-unit / per-pack-contribution as today,
  so `item_total = unit_price × quantity` holds on the backend. This corrects a
  pre-existing undercount where multi-quantity simple lines and multi-pack orders
  were effectively treated as quantity 1.
- **Deduplication unchanged**: `removeDuplicateProducts()` still keeps the
  higher-priced line when a code repeats, and reports that winning line's
  quantity (quantities are **not** summed).

## Files touched

| File | Change |
|------|--------|
| `src/utils/miguel-api-dispatcher.php` | New `case 'order':` — GET-only, requires `code`, calls `getOrderByCode`, maps `false` → `orderNotFound`. |
| `miguel.php` | New `getOrderByCode()`; add `id`, `billing_address`, `shipping_address` to `createOrderDetailArray()` (load `id_address_delivery`); add `'order'` to the connect `endpoints` map. |
| `src/utils/miguel-api-create-order-request.php` | New `structureAddress(?\Address): ?array` helper; pass `product_quantity` into each `MiguelApiCreateOrderItem`. |
| `src/utils/miguel-api-create-order-item.php` | Add `quantity` constructor arg, `quantity` in `jsonSerialize()`, `getQuantity()` accessor. |
| `src/utils/miguel-api-error.php` | New `orderNotFound($code)` factory → code `order.not_found`, message `Order {code} not found`. |
| `docs/openapi.yaml` | Add `order.not_found` to the `ApiError` enum; add `id` (integer) + `billing_address`/`shipping_address` to the `Order` schema; add `quantity` to the `OrderProduct` schema; add an `OrderAddress` schema and an `OrderResponse` schema; document the new `order` resource (marked front-controller-only). |
| `CHANGELOG.md` | Four bullets under the existing `## v1.3.0` → Added. |

### Dispatcher `case 'order'` (shape)

```php
case 'order':
    if ($method !== 'GET') {
        return MiguelApiResponse::error(MiguelApiError::methodNotAllowed($method));
    }
    if (!isset($get['code'])) {
        return MiguelApiResponse::error(MiguelApiError::argumentNotSet('code'));
    }
    $order = $this->module->getOrderByCode($get['code']);
    if ($order === false) {
        return MiguelApiResponse::error(MiguelApiError::orderNotFound($get['code']));
    }

    return MiguelApiResponse::success($order, 'order');
```

### Connect payload

Add to `$ps['endpoints']` in `getPrestashopDetails()`:

```php
'order' => $endpointBase . 'order',
```

The backend can also feature-detect single-order support via the presence of
`endpoints.order`.

## OpenAPI notes

- `ApiError.code` enum: add `order.not_found`.
- `Order` schema: add `id` (integer, e.g. `5002`); add `billing_address`
  (`OrderAddress`) and `shipping_address` (`OrderAddress`, nullable); update the
  `update_date` description to note it is present in the single-order response as
  well.
- New `OrderAddress` schema: nullable-string `full_name`, `company`, `address1`,
  `address2`, `city`, `state`, `zip`, `country` (ISO code), `phone`.
- `OrderProduct` schema: add `quantity` (integer, default 1, e.g. `2`).
- New `OrderResponse` schema: `{ result: true, debug: "", order: Order }`.
- New resource documentation for `order`, explicitly annotated as
  front-controller-only (no direct-access `.php` script exists, unlike the other
  three resources). Include a `code` query param and a `oneOf` of `OrderResponse`
  / `ErrorEnvelope` with `order.not_found` and `argument.not_set` examples.

## Testing

**Dispatcher-contract tests** (`tests/Unit/ApiDispatcherTest.php`, no fixtures):

- `order` without `code` → `result: false`, `argument.not_set`.
- `order` with a non-GET method → `method.not_allowed`.
- `order` with an unknown `code` → `result: false`, `order.not_found`.

**Fixture-based tests** (new `tests/Unit/OrderTest.php`, driving the dispatcher
directly since there is no legacy script to `include`):

- Order with reference `ABCTEST` + a product with a reference → `result: true`,
  data key `order`, `order.code === 'ABCTEST'`, `order.id === (int) $order->id`.
- The returned order carries a non-null `billing_address` array with the
  expected keys (`full_name`, `zip`, `country`, …) and a `shipping_address`
  (non-null in the fixture, which sets `id_address_delivery = 1`).
- Each product line carries a `quantity` equal to the order detail's
  `product_quantity` (set the fixture's `product_quantity` to a value > 1 and
  assert it round-trips).
- Order whose only product has an empty reference (reuse the
  `testEmptyReferenceInProduct` fixture pattern) → `order.not_found`.
- Shared reference: two orders with the same reference, the newest carrying a
  Miguel product → returns the newest (assert `order.id` is the newest id).

**Regression** (`tests/Unit/OrdersTest.php`):

- An `orders` / `updated_since` list entry now carries an integer `id` and a
  `billing_address` object.

## Out of scope

- No legacy `order.php` direct-access script.
- No change to the `orders`, `products`, or `order-state-callback` resources
  beyond the shared order-payload additions (`id`, `billing_address`,
  `shipping_address`, and product-line `quantity`) automatically appearing.
- No version bump.
- No removal of the flattened `user.address` string (kept for backward compat).
