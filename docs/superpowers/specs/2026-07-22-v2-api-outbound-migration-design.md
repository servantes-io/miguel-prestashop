# V2 API outbound migration — design

- **Date:** 2026-07-22
- **Status:** Approved
- **Scope:** Outbound only — the module's HTTP calls *to* the Miguel backend.

## Summary

The module currently talks to the Miguel backend over two v1 order endpoints:
`POST /v1/orders` (order sync on status change) and `GET /v1/orders?user_email=`
(the customer "purchased e-books" page). This work migrates both to the Miguel
**API v2** shape described by
`https://miguel-test.servantes.cz/v2/swagger/v2/swagger.json`.

The migration is **outbound only**. The module's own inbound HTTP API (the front
controller and legacy scripts serving the `orders`, `order`, `products`, and
`order-state-callback` resources) is **not touched** — its request/response
contract, the `{ "result": ..., "debug": "" }` envelope, and the `endpoints`
advertised on connect all stay exactly as they are. No new inbound endpoints are
added in this work.

## Goals

- `POST /v2/orders` replaces `POST /v1/orders` for order sync.
- `GET /v2/orders?userEmail=` (paginated) replaces
  `GET /v1/orders?user_email=` for the purchased-books page.
- The V2 request/response shapes are isolated in dedicated, unit-tested mapper
  classes.
- `views/templates/front/purchased.tpl` is **not** modified — the purchased-page
  read path maps V2 responses back into the same internal array the template
  already consumes.

## Non-goals

- No changes to the inbound module API (resources, envelope, error codes).
- No changes to `createOrderDetailArray()` (it stays v1-only; see below).
- No new module endpoints returning V2-shaped objects (explicitly dropped from
  scope).
- No change to `POST /v2/eshop/prestashop/connect` — already v2.

## Key constraint: `createOrderDetailArray()` is shared

`Miguel::createOrderDetailArray()` builds the v1 order body and is called from
**three** places:

1. `hookActionOrderStatusUpdate()` → `POST /v1/orders` — **outbound** (migrates).
2. `getUpdatedOrders()` → inbound `orders` resource — **stays v1**.
3. `getOrderByCode()` → inbound `order` resource — **stays v1**.

Because 2 and 3 must keep emitting the v1 shape, the outbound V2 payload is built
by a **separate** mapper. `createOrderDetailArray()` is left untouched.

## Architecture

Dedicated V2 mapper classes; thin call-site changes; version-agnostic transport.

New files under `src/utils/`:

- `miguel-api-v2-order-request.php` — `MiguelApiV2OrderRequest`: maps a PrestaShop
  `Order` (+ its resolved `Customer`, `Currency`, `Address`, `Language`,
  `OrderDetail` list) into the V2 `OrderCreate` array for `POST /v2/orders`.
- `miguel-api-v2-order-mapper.php` — `MiguelApiV2OrderMapper`: maps the V2
  `GET /v2/orders` (paginated list) response into the internal purchased-page
  array structure that `purchased.tpl` consumes.

Reused as-is:

- `MiguelApiCreateOrderRequest::createProductsArray()` — pack-splitting + duplicate
  removal, already unit-tested. The V2 request builder calls it to obtain
  `MiguelApiCreateOrderItem[]`, then serializes each item to the V2 shape via the
  item's existing getters (`getCode()`, `getQuantity()`, `getSoldPrice()`).
- `MiguelApiCreateOrderRequest::composeAddress()` / `structureAddress()` — the
  V2 request builder reuses `structureAddress()` and remaps its keys to V2
  `OrderAddressModel`. All keys match except `full_name` → **`fullName`**
  (`company`, `address1`, `address2`, `city`, `state`, `zip`, `country`, `phone`
  are already identical). `composeAddress()` is reused verbatim for the
  `user.address` string.
- `Miguel::curlGet()` / `curlPost()` — version-agnostic (they take a URI and a
  body); only the URIs and payloads change.

Changed call sites in `miguel.php`:

- `hookActionOrderStatusUpdate()` — build the body with `MiguelApiV2OrderRequest`
  and `POST` it to `/v2/orders` instead of `/v1/orders`.
- `getOrderedBooks()` — replace the single `GET /v1/orders?user_email=` with the
  paginated `GET /v2/orders?userEmail=` read flow (below), mapping the result
  through `MiguelApiV2OrderMapper`.

## Outbound: `POST /v2/orders` (`OrderCreate`)

`MiguelApiV2OrderRequest` produces:

| V2 `OrderCreate` field | Source |
|---|---|
| `code` | `order->reference` |
| `user` (`WatermarkUser`) | `{ id: (string) id_customer, name: firstname+' '+lastname, address: composeAddress(invoiceAddress), email: customer->email, language: language->iso_code }` |
| `currencyCode` | `currency->iso_code` |
| `purchasedAt` | `date_add` (ISO 8601) **when the order is paid, otherwise `null`** |
| `eshopId` | `(string) order->id` |
| `eshopCreatedAt` | `order->date_add` (ISO 8601) |
| `eshopUpdatedAt` | `order->date_upd` (ISO 8601) |
| `billingAddress` (`OrderAddressModel`) | `structureAddress(invoiceAddress)`, key `full_name`→`fullName` |
| `shippingAddress` (`OrderAddressModel`) | `structureAddress(deliveryAddress)`, key `full_name`→`fullName` |
| `items[]` (`OrderCreateItem`) | per item: `{ code: getCode(), quantity: getQuantity(), unitPrice: { withoutVat: getSoldPrice() } }` |
| `source` | `null` |
| `socialDrmContent` | `null` |
| `sendEmail` | omitted (inherit the eshop default) |

**Paid semantics.** V2 `OrderCreate` has no `paid` field; paid is derived from
`purchasedAt` being non-null. The current "paid" determination is preserved: use
the `newOrderStatus->paid` flag when the hook provides it, otherwise
`order->hasBeenPaid()`. When paid, `purchasedAt = date_add`; when not, `null`.

**Regular price is dropped.** V2 `OrderCreateItem` has no regular/list-price
field (only `unitPrice`/`itemPrice`), so today's `regular_without_vat` has no home
and is not sent. `unitPrice.withoutVat` carries the sold price. `itemPrice` is
omitted — V2 defaults it to `unitPrice × quantity`.

**Empty-order guard preserved.** As today, if the built item list is empty (no
products, or none with a reference), the order is not sent.

Dates are formatted as ISO 8601 (`DATE_ISO8601`, matching the existing code).

## Outbound: purchased-books read flow

As of the current v2 spec, the list item (`Interfaces.IOrderListItem`) carries
`formats[]` (`Interfaces.IOrderItemFormat` = `{ format, downloadUrl }`), so the
paginated list alone supplies everything the purchased page needs — no per-order
detail call. `getOrderedBooks()` uses a single list read. For the logged-in
customer:

1. `GET /v2/orders?userEmail={email}&limit=100&page=N`, following `meta.nextPage`
   until exhausted, collecting the returned orders (`data[]`, each an `IOrder`)
   keyed by `code`.
2. Load the customer's PrestaShop orders (as today, via
   `Order::getCustomerOrders()`). For each PrestaShop order whose `reference`
   matches a returned order `code`, map that order's `items[]`.
3. `MiguelApiV2OrderMapper` turns a matched order into purchased-page rows — one
   row per `item` that has a linked Miguel product (`item.product !== null`),
   mapped to the existing internal shape:

   | Internal key (template) | V2 list source |
   |---|---|
   | `id_order` | PrestaShop order `id_order` |
   | `reference` | PrestaShop order `reference` |
   | `date_add` | PrestaShop order `date_add`, formatted via `Tools::displayDate` (unchanged) |
   | `order_state` | PrestaShop order `order_state` (unchanged) |
   | `paid` | `IOrder.paid` |
   | `product.book.title` | `item.product.product.title` (`IProductVariantAlone.product.title`) |
   | `product.formats[]` | `item.formats[]` → `[{ format: fmt.format, download_url: fmt.downloadUrl }]` |

   The template's existing "books are being prepared" branch is reached naturally
   when `formats` is empty/null (unpaid / not yet generated).

Because the mapper reproduces the exact array the template already reads,
`purchased.tpl` is unchanged. Should the backend ever move the download links
back off the list item, the fallback is a per-order `GET /v2/orders/{code}`
detail call — but that is not needed with the current spec.

## Error handling

- Transport helpers keep their current contract: `curlGet()` returns `false` on
  non-200; `curlPost()` returns `false` outside 2xx. Both already short-circuit
  to `false` when configuration is missing or the module is disabled.
- `POST /v2/orders` (the hook) is fire-and-forget, matching today: a `false`
  result is ignored (the hook returns `void`). Failures are logged via the
  existing `_logger` when `_LOGGER_` is defined.
- Purchased page: a failed or unparseable first list request degrades to the
  existing `['result' => false, 'debug' => ...]` outcome so the template shows
  "no books" rather than erroring. A PrestaShop order whose `reference` is not in
  the returned list is simply not shown (it is not a Miguel order).

## Testing

Follows the existing PHPUnit setup under `tests/Unit` (run via `make test-docker`
— PS 1.7.8.10 + PHP 7.4 + mysql 5.7; no host PHP).

New unit tests:

- `MiguelApiV2OrderRequestTest` — verifies the `OrderCreate` mapping: camelCase
  keys, `unitPrice.withoutVat` from sold price, `purchasedAt` null-when-unpaid vs
  set-when-paid, `eshopId`/`eshopCreatedAt`/`eshopUpdatedAt`, `billingAddress`/
  `shippingAddress` with the `full_name`→`fullName` remap, regular price absent,
  empty-order guard, and that
  pack/duplicate handling still works (reuses `createProductsArray`).
- `MiguelApiV2OrderMapperTest` — verifies list `{data, meta}` parsing +
  `nextPage` pagination, matching a returned order by `code`, and the
  order→internal-array mapping (title, formats→download_url, paid, empty/null
  formats path, skipping items with no linked product).

Existing inbound tests (`OrdersTest`, `OrderDetailArrayTest`, `GetOrderByCodeTest`,
`OrderResourceTest`, `ApiDispatcherTest`, …) must continue to pass **unchanged**,
proving the inbound v1 contract is intact.

## Backward compatibility & rollout

- Inbound API and `connect` payload are unchanged, so an already-connected shop
  keeps serving Miguel exactly as before.
- The change is a hard cutover on the outbound side: after deploy the module
  calls `/v2/orders`. The Miguel backend already exposes these endpoints (per the
  referenced v2 swagger), so no lockstep coordination beyond deploying the module
  is required.
- Bump the module version to `1.4.0` and add a CHANGELOG entry under a new
  `## v1.4.0` heading describing the outbound switch and the dropped
  `regular_without_vat` on order items.

## Files touched

New:
- `src/utils/miguel-api-v2-order-request.php`
- `src/utils/miguel-api-v2-order-mapper.php`
- `tests/Unit/MiguelApiV2OrderRequestTest.php`
- `tests/Unit/MiguelApiV2OrderMapperTest.php`

Modified:
- `miguel.php` — `hookActionOrderStatusUpdate()` and `getOrderedBooks()` call
  sites; version bump.
- `CHANGELOG.md` — `v1.4.0` entry.

Unchanged (intentionally): the inbound dispatcher/controllers, `docs/openapi.yaml`,
`createOrderDetailArray()`, `views/templates/front/purchased.tpl`.
