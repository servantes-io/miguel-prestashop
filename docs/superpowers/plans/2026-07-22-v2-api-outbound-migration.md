# V2 API Outbound Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the module's two outbound calls to Miguel — order sync and the purchased-books lookup — from the v1 order API to the v2 order API.

**Architecture:** Two dedicated, self-contained V2 mapper classes under `src/utils/` isolate the v2 shape. `MiguelApiV2OrderRequest` builds the `OrderCreate` body for `POST /v2/orders`; `MiguelApiV2OrderMapper` turns `GET /v2/orders` (list) and `GET /v2/orders/{code}` (detail) responses into the internal array the purchased template already consumes. Two call sites in `miguel.php` are rewired; the inbound v1 API, `createOrderDetailArray()`, and `purchased.tpl` are untouched.

**Tech Stack:** PHP 7.1+ (tested on PHP 7.4), PrestaShop 1.7.8–9.0, PHPUnit (run in Docker via `make test-docker`), PHPStan level 5.

## Global Constraints

- **Outbound only.** Do not modify the inbound API (dispatcher, controllers, `orders.php`/`products.php`/`order-state-callback.php`), its `{ "result": ..., "debug": "" }` envelope, error codes, or the `endpoints` reported on connect.
- **`Miguel::createOrderDetailArray()` stays untouched** — it feeds the inbound `orders` and `order` resources, which must keep emitting the v1 shape.
- **`views/templates/front/purchased.tpl` stays untouched** — the read path maps v2 responses back into the existing internal array.
- All PHP files start with `if (!defined('_PS_VERSION_')) { exit; }` after the licence header (follow the existing files in `src/utils/`).
- New util classes live in namespace `Miguel\Utils`. They are **not** composer-autoloaded — `miguel.php` `require_once`s each `src/utils/*.php` file explicitly (lines 15–21). Every new util file MUST be added to that require list, or the class will not load at runtime or in tests (the PHPUnit bootstrap requires `miguel.php`). Tests live in namespace `Tests\Unit`.
- Dates are ISO 8601 via `date(DATE_ISO8601, strtotime($psDate))`, matching existing code.
- Run the suite with `make test-docker` (all) or `make test-docker ARGS="--filter <TestClass>"` (one class). No host PHP.
- Commit after each task (frequent commits).

---

## File Structure

New:
- `src/utils/miguel-api-v2-order-request.php` — `MiguelApiV2OrderRequest`: `Order` → `OrderCreate` array (outbound POST body). Self-contained: loads the order's related entities and reuses the existing static helpers for products/addresses.
- `src/utils/miguel-api-v2-order-mapper.php` — `MiguelApiV2OrderMapper`: pure functions mapping decoded v2 list/detail responses into purchased-page rows.
- `tests/Unit/MiguelApiV2OrderRequestTest.php` — DB-backed tests for the builder.
- `tests/Unit/MiguelApiV2OrderMapperTest.php` — pure array-in/array-out tests for the mapper.

Modified:
- `miguel.php` — `hookActionOrderStatusUpdate()` (Task 2) and `getOrderedBooks()` (Task 4) call sites; `$this->version` bump (Task 5).
- `CHANGELOG.md` — `v1.4.0` entry (Task 5).

---

## Task 1: `MiguelApiV2OrderRequest` — OrderCreate builder

**Files:**
- Create: `src/utils/miguel-api-v2-order-request.php`
- Test: `tests/Unit/MiguelApiV2OrderRequestTest.php`

**Interfaces:**
- Consumes: `Miguel\Utils\MiguelApiCreateOrderRequest::createProductsArray(\Order $order, array $order_detail): MiguelApiCreateOrderItem[]` (existing, reused), `::structureAddress(?\Address): ?array`, `::composeAddress(\Address): string`; `Miguel\Utils\MiguelApiCreateOrderItem` getters `getCode()`, `getQuantity()`, `getSoldPrice()`.
- Produces: `MiguelApiV2OrderRequest::build(\Order $order, bool $paid): ?array` — the `OrderCreate` array, or `null` when the order has no sendable items (empty-order guard). Consumed by Task 2.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MiguelApiV2OrderRequestTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiV2OrderRequest;
use Order;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class MiguelApiV2OrderRequestTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
    }

    /**
     * @return Order
     */
    private function buildOrder(string $reference, string $productReference, int $quantity = 1)
    {
        $order = $this->entityCreator->createOrder();
        $order->reference = $reference;
        $order->save();

        $product = new Product();
        $product->name = 'Test product';
        $product->reference = $productReference;
        $product->save();

        $orderDetail = $this->entityCreator->createOrderDetail($order, $product);
        $orderDetail->product_quantity = $quantity;
        $orderDetail->save();

        return $order;
    }

    public function testBuildsV2OrderCreateShape()
    {
        $order = $this->buildOrder('V2CREATE', '9788024271101', 2);

        $body = MiguelApiV2OrderRequest::build($order, true);

        $this->assertIsArray($body);
        $this->assertSame('V2CREATE', $body['code']);
        $this->assertSame((string) $order->id, $body['eshopId']);
        $this->assertArrayHasKey('currencyCode', $body);
        // user is WatermarkUser: email + language required, camelCase keys
        $this->assertArrayHasKey('email', $body['user']);
        $this->assertArrayHasKey('language', $body['user']);
        $this->assertArrayHasKey('name', $body['user']);
        // items are OrderCreateItem: unitPrice.withoutVat, no regular price
        $item = $body['items'][0];
        $this->assertSame('9788024271101', $item['code']);
        $this->assertSame(2, $item['quantity']);
        $this->assertArrayHasKey('withoutVat', $item['unitPrice']);
        $this->assertArrayNotHasKey('price', $item);
        $this->assertArrayNotHasKey('regular_without_vat', $item);
    }

    public function testPurchasedAtIsSetWhenPaidAndNullWhenNot()
    {
        $order = $this->buildOrder('V2PAID', '9788024271101');

        $paidBody = MiguelApiV2OrderRequest::build($order, true);
        $unpaidBody = MiguelApiV2OrderRequest::build($order, false);

        $this->assertSame(date(DATE_ISO8601, strtotime($order->date_add)), $paidBody['purchasedAt']);
        $this->assertNull($unpaidBody['purchasedAt']);
    }

    public function testEshopDatesUseIso8601()
    {
        $order = $this->buildOrder('V2DATES', '9788024271101');

        $body = MiguelApiV2OrderRequest::build($order, true);

        $this->assertSame(date(DATE_ISO8601, strtotime($order->date_add)), $body['eshopCreatedAt']);
        $this->assertSame(date(DATE_ISO8601, strtotime($order->date_upd)), $body['eshopUpdatedAt']);
    }

    public function testAddressesUseCamelCaseFullName()
    {
        $order = $this->buildOrder('V2ADDR', '9788024271101');

        $body = MiguelApiV2OrderRequest::build($order, true);

        $this->assertIsArray($body['billingAddress']);
        $this->assertArrayHasKey('fullName', $body['billingAddress']);
        $this->assertArrayNotHasKey('full_name', $body['billingAddress']);
    }

    public function testReturnsNullWhenNoSendableItems()
    {
        // product without a reference is skipped by createProductsArray -> no items
        $order = $this->buildOrder('V2EMPTY', '');

        $this->assertNull(MiguelApiV2OrderRequest::build($order, true));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test-docker ARGS="--filter MiguelApiV2OrderRequestTest"`
Expected: FAIL — `Class "Miguel\Utils\MiguelApiV2OrderRequest" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `src/utils/miguel-api-v2-order-request.php`:

```php
<?php
/**
 * 2026 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2026 Servantes
 *  @license LICENSE.txt
 */

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Builds the Miguel API v2 `OrderCreate` payload for `POST /v2/orders`.
 *
 * Self-contained: it loads the order's related entities and reuses the shared
 * product/address helpers. It does NOT touch createOrderDetailArray(), which
 * keeps emitting the v1 shape for the inbound API.
 */
class MiguelApiV2OrderRequest
{
    /**
     * @param \Order $order the PrestaShop order
     * @param bool $paid whether the order counts as paid (drives purchasedAt)
     *
     * @return array<string,mixed>|null the OrderCreate body, or null when there
     *                                  are no sendable items
     */
    public static function build(\Order $order, bool $paid)
    {
        $customer = new \Customer($order->id_customer);
        $currency = new \Currency($order->id_currency);
        $language = new \Language($customer->id_lang);
        $invoiceAddress = new \Address((int) $order->id_address_invoice);
        $deliveryAddress = new \Address((int) $order->id_address_delivery);
        $orderDetail = \OrderDetail::getList($order->id);

        $items = [];
        foreach (MiguelApiCreateOrderRequest::createProductsArray($order, $orderDetail) as $item) {
            $items[] = [
                'code' => $item->getCode(),
                'quantity' => $item->getQuantity(),
                'unitPrice' => [
                    'withoutVat' => $item->getSoldPrice(),
                ],
            ];
        }

        if (count($items) < 1) {
            return null;
        }

        $createdAt = date(DATE_ISO8601, strtotime($order->date_add));

        return [
            'code' => $order->reference,
            'user' => [
                'id' => (string) $order->id_customer,
                'name' => $customer->firstname . ' ' . $customer->lastname,
                'address' => MiguelApiCreateOrderRequest::composeAddress($invoiceAddress),
                'email' => $customer->email,
                'language' => $language->iso_code,
            ],
            'currencyCode' => $currency->iso_code,
            'purchasedAt' => $paid ? $createdAt : null,
            'eshopId' => (string) $order->id,
            'eshopCreatedAt' => $createdAt,
            'eshopUpdatedAt' => date(DATE_ISO8601, strtotime($order->date_upd)),
            'billingAddress' => self::toV2Address(MiguelApiCreateOrderRequest::structureAddress($invoiceAddress)),
            'shippingAddress' => self::toV2Address(MiguelApiCreateOrderRequest::structureAddress($deliveryAddress)),
            'source' => null,
            'socialDrmContent' => null,
            'items' => $items,
        ];
    }

    /**
     * Remap the v1 structured-address keys to v2 OrderAddressModel. Only
     * full_name differs (-> fullName); the rest are identical.
     *
     * @param array<string,mixed>|null $address
     *
     * @return array<string,mixed>|null
     */
    private static function toV2Address($address)
    {
        if (null === $address) {
            return null;
        }

        $address['fullName'] = $address['full_name'];
        unset($address['full_name']);

        return $address;
    }
}
```

- [ ] **Step 4: Register the file so it loads**

The util classes are not autoloaded. In `miguel.php`, add the new file to the `require_once` block (after `miguel-api-create-order-request.php`, currently line 18):

```php
require_once 'src/utils/miguel-api-v2-order-request.php';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `make test-docker ARGS="--filter MiguelApiV2OrderRequestTest"`
Expected: PASS (5 tests). (Without Step 4 the class file is never included and the test still fails with "class not found".)

- [ ] **Step 6: Commit**

```bash
git add src/utils/miguel-api-v2-order-request.php tests/Unit/MiguelApiV2OrderRequestTest.php miguel.php
git commit -m "feat: add v2 OrderCreate request builder for outbound order sync"
```

---

## Task 2: Wire `hookActionOrderStatusUpdate()` to `POST /v2/orders`

**Files:**
- Modify: `miguel.php` — `hookActionOrderStatusUpdate()` (currently around lines 493–506)

**Interfaces:**
- Consumes: `MiguelApiV2OrderRequest::build(\Order $order, bool $paid): ?array` (Task 1); existing `Miguel::curlPost(string $uri, array $params)`.
- Produces: nothing new (hook returns void).

**Context:** The current hook calls `createOrderDetailArray($params)` and `curlPost('/v1/orders', ...)`. `$params` may contain `id_order` and, for the callback path, `newOrderStatus` (an object with a `->paid` flag). The v1 builder derives `paid` from `newOrderStatus->paid` when present, else `$order->hasBeenPaid()`. Reproduce that here.

- [ ] **Step 1: Read the current hook**

Run: `grep -n "function hookActionOrderStatusUpdate" -A 15 miguel.php`
Confirm it currently builds via `createOrderDetailArray($params)` and posts to `/v1/orders`.

- [ ] **Step 2: Replace the hook body**

Replace the body of `hookActionOrderStatusUpdate($params)` with:

```php
    public function hookActionOrderStatusUpdate($params)
    {
        if (false == MiguelSettings::getEnabled()) {
            return;
        } // ověření, že je api povoleno

        if (false == isset($params['id_order'])) {
            return;
        }
        $order = new Order((int) $params['id_order']);
        if (false == Validate::isLoadedObject($order)) {
            return;
        }

        $paid = isset($params['newOrderStatus'])
            ? (bool) $params['newOrderStatus']->paid
            : $order->hasBeenPaid();

        $body_order = MiguelApiV2OrderRequest::build($order, $paid);
        if (null === $body_order) {
            // no products, or none with a reference — nothing to send
            return;
        }

        $this->curlPost('/v2/orders', $body_order);
    }
```

Add the import near the other `use Miguel\Utils\...` statements at the top of `miguel.php` if not already present:

```php
use Miguel\Utils\MiguelApiV2OrderRequest;
```

- [ ] **Step 3: Verify the inbound suite is unaffected**

Run: `make test-docker ARGS="--filter OrderDetailArrayTest"`
Expected: PASS — `createOrderDetailArray()` is unchanged, so the v1 inbound tests still pass.

- [ ] **Step 4: Run the full suite**

Run: `make test-docker`
Expected: PASS — no regressions. (The hook's network POST is not unit-tested; its payload is covered by Task 1.)

- [ ] **Step 5: Commit**

```bash
git add miguel.php
git commit -m "feat: sync orders to POST /v2/orders on status update"
```

---

## Task 3: `MiguelApiV2OrderMapper` — list + detail response mappers

**Files:**
- Create: `src/utils/miguel-api-v2-order-mapper.php`
- Test: `tests/Unit/MiguelApiV2OrderMapperTest.php`

**Interfaces:**
- Consumes: nothing from the codebase — pure array transforms.
- Produces:
  - `MiguelApiV2OrderMapper::extractCodes(array $listResponse): string[]` — order codes from a decoded `GET /v2/orders` page (`data[].code`).
  - `MiguelApiV2OrderMapper::nextPage(array $listResponse): ?int` — `meta.nextPage` (null when absent/last page).
  - `MiguelApiV2OrderMapper::mapDetailToBooks(array $detail, array $orderMeta): array` — purchased-page rows from a decoded `GET /v2/orders/{code}` response. `$orderMeta` supplies the PrestaShop-side fields: `['id_order' => int, 'reference' => string, 'date_add' => string, 'order_state' => string]`. Consumed by Task 4.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MiguelApiV2OrderMapperTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiV2OrderMapper;
use PHPUnit\Framework\TestCase;

class MiguelApiV2OrderMapperTest extends TestCase
{
    public function testExtractCodesReadsDataCodes()
    {
        $list = ['data' => [['code' => 'A'], ['code' => 'B']], 'meta' => []];

        $this->assertSame(['A', 'B'], MiguelApiV2OrderMapper::extractCodes($list));
    }

    public function testExtractCodesEmptyWhenNoData()
    {
        $this->assertSame([], MiguelApiV2OrderMapper::extractCodes([]));
    }

    public function testNextPageReadsMeta()
    {
        $this->assertSame(2, MiguelApiV2OrderMapper::nextPage(['meta' => ['nextPage' => 2]]));
        $this->assertNull(MiguelApiV2OrderMapper::nextPage(['meta' => ['nextPage' => null]]));
        $this->assertNull(MiguelApiV2OrderMapper::nextPage([]));
    }

    public function testMapDetailToBooksBuildsTemplateRows()
    {
        $detail = [
            'paid' => true,
            'items' => [
                [
                    'code' => 'BK1',
                    'product' => ['product' => ['title' => 'The Book']],
                    'formats' => [
                        ['format' => 'epub', 'downloadUrl' => 'https://x/epub'],
                        ['format' => 'pdf', 'downloadUrl' => 'https://x/pdf'],
                    ],
                ],
            ],
        ];
        $meta = ['id_order' => 7, 'reference' => 'REF7', 'date_add' => '2026-07-22', 'order_state' => 'Payment accepted'];

        $rows = MiguelApiV2OrderMapper::mapDetailToBooks($detail, $meta);

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame(7, $row['id_order']);
        $this->assertSame('REF7', $row['reference']);
        $this->assertSame('2026-07-22', $row['date_add']);
        $this->assertSame('Payment accepted', $row['order_state']);
        $this->assertTrue($row['paid']);
        $this->assertSame('The Book', $row['product']['book']['title']);
        $this->assertSame('epub', $row['product']['formats'][0]['format']);
        $this->assertSame('https://x/epub', $row['product']['formats'][0]['download_url']);
        $this->assertSame('https://x/pdf', $row['product']['formats'][1]['download_url']);
    }

    public function testMapDetailSkipsItemsWithoutLinkedProduct()
    {
        $detail = [
            'paid' => false,
            'items' => [
                ['code' => 'NOPROD', 'product' => null, 'formats' => []],
                ['code' => 'BK2', 'product' => ['product' => ['title' => 'Kept']], 'formats' => []],
            ],
        ];
        $meta = ['id_order' => 1, 'reference' => 'R', 'date_add' => 'd', 'order_state' => 's'];

        $rows = MiguelApiV2OrderMapper::mapDetailToBooks($detail, $meta);

        $this->assertCount(1, $rows);
        $this->assertSame('Kept', $rows[0]['product']['book']['title']);
    }

    public function testMapDetailEmptyFormatsYieldsPreparingRow()
    {
        $detail = [
            'paid' => true,
            'items' => [
                ['code' => 'BK3', 'product' => ['product' => ['title' => 'Preparing']], 'formats' => null],
            ],
        ];
        $meta = ['id_order' => 1, 'reference' => 'R', 'date_add' => 'd', 'order_state' => 's'];

        $rows = MiguelApiV2OrderMapper::mapDetailToBooks($detail, $meta);

        $this->assertCount(1, $rows);
        $this->assertSame([], $rows[0]['product']['formats']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make test-docker ARGS="--filter MiguelApiV2OrderMapperTest"`
Expected: FAIL — `Class "Miguel\Utils\MiguelApiV2OrderMapper" not found`.

- [ ] **Step 3: Write the minimal implementation**

Create `src/utils/miguel-api-v2-order-mapper.php`:

```php
<?php
/**
 * 2026 Servantes
 *
 * This file is licenced under the Software License Agreement.
 * With the purchase or the installation of the software in your application
 * you accept the licence agreement.
 *
 * You must not modify, adapt or create derivative works of this source code
 *
 *  @author Roman Kříž <roman.kriz@servantes.cz>
 *  @copyright  2022 - 2026 Servantes
 *  @license LICENSE.txt
 */

namespace Miguel\Utils;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Maps Miguel API v2 order responses (list + detail) into the internal array
 * shape consumed by views/templates/front/purchased.tpl, so the template does
 * not need to change.
 */
class MiguelApiV2OrderMapper
{
    /**
     * @param array<string,mixed> $listResponse decoded GET /v2/orders page
     *
     * @return string[] order codes present on this page
     */
    public static function extractCodes(array $listResponse)
    {
        if (!isset($listResponse['data']) || !is_array($listResponse['data'])) {
            return [];
        }

        $codes = [];
        foreach ($listResponse['data'] as $order) {
            if (isset($order['code'])) {
                $codes[] = $order['code'];
            }
        }

        return $codes;
    }

    /**
     * @param array<string,mixed> $listResponse decoded GET /v2/orders page
     *
     * @return int|null next page index, or null on the last page
     */
    public static function nextPage(array $listResponse)
    {
        if (!isset($listResponse['meta']['nextPage'])) {
            return null;
        }

        return $listResponse['meta']['nextPage'];
    }

    /**
     * @param array<string,mixed> $detail decoded GET /v2/orders/{code} response
     * @param array<string,mixed> $orderMeta PrestaShop-side fields:
     *                                        id_order, reference, date_add, order_state
     *
     * @return array<int,array<string,mixed>> one purchased-page row per linked item
     */
    public static function mapDetailToBooks(array $detail, array $orderMeta)
    {
        $rows = [];
        $items = isset($detail['items']) && is_array($detail['items']) ? $detail['items'] : [];

        foreach ($items as $item) {
            if (empty($item['product'])) {
                // no linked Miguel product — skip (matches v1 "book" check)
                continue;
            }

            $formats = [];
            if (isset($item['formats']) && is_array($item['formats'])) {
                foreach ($item['formats'] as $format) {
                    $formats[] = [
                        'format' => $format['format'],
                        'download_url' => $format['downloadUrl'],
                    ];
                }
            }

            $title = isset($item['product']['product']['title'])
                ? $item['product']['product']['title']
                : '';

            $rows[] = [
                'id_order' => $orderMeta['id_order'],
                'reference' => $orderMeta['reference'],
                'date_add' => $orderMeta['date_add'],
                'order_state' => $orderMeta['order_state'],
                'paid' => !empty($detail['paid']),
                'product' => [
                    'book' => ['title' => $title],
                    'formats' => $formats,
                ],
            ];
        }

        return $rows;
    }
}
```

- [ ] **Step 4: Register the file so it loads**

In `miguel.php`, add the new file to the `require_once` block (after `miguel-api-v2-order-request.php` from Task 1):

```php
require_once 'src/utils/miguel-api-v2-order-mapper.php';
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `make test-docker ARGS="--filter MiguelApiV2OrderMapperTest"`
Expected: PASS (6 tests). (Without Step 4 the class file is never included and the test still fails with "class not found".)

- [ ] **Step 6: Commit**

```bash
git add src/utils/miguel-api-v2-order-mapper.php tests/Unit/MiguelApiV2OrderMapperTest.php miguel.php
git commit -m "feat: add v2 order response mapper for purchased-books page"
```

---

## Task 4: Wire `getOrderedBooks()` to the v2 list-then-detail flow

**Files:**
- Modify: `miguel.php` — `getOrderedBooks()` (currently around lines 970–1006) and its helper `arrayWithCode()` (now unused for v2 — leave it, it is still referenced by nothing else; remove only if PHPStan flags it).

**Interfaces:**
- Consumes: `MiguelApiV2OrderMapper::extractCodes()`, `::nextPage()`, `::mapDetailToBooks()` (Task 3); existing `Miguel::curlGet(string $uri): string|false`; `Tools::displayDate`.
- Produces: unchanged return contract — either `['result' => false, 'debug' => string]` or a list of purchased-page rows (same shape `purchased.tpl` reads today).

**Context:** Today `getOrderedBooks()` loads the customer's PrestaShop orders, does one `GET /v1/orders?user_email=`, then matches by reference. The v2 flow: page through `GET /v2/orders?userEmail=&limit=100` collecting codes, then for each PrestaShop order whose `reference` is in that set, `GET /v2/orders/{reference}` and map it.

- [ ] **Step 1: Read the current method**

Run: `grep -n "function getOrderedBooks" -A 45 miguel.php`
Confirm the current v1 flow and the exact `date_add` / `order_state` fields used per row.

- [ ] **Step 2: Replace the method body**

Replace `getOrderedBooks()` with:

```php
    public function getOrderedBooks()
    {
        $orders_prestashop = Order::getCustomerOrders((int) $this->context->customer->id);
        if (count($orders_prestashop) < 1) {
            return ['result' => false, 'debug' => 'no_orders_prestashop'];
        }

        $user_email = $this->context->customer->email;

        $codes = $this->collectMiguelOrderCodes($user_email);
        if (false === $codes) {
            return ['result' => false, 'debug' => 'get_err'];
        }
        if (count($codes) < 1) {
            return ['result' => false, 'debug' => 'no_orders_servantes'];
        }

        $code_set = array_flip($codes);
        $orders = [];
        foreach ($orders_prestashop as $order) {
            if (!isset($code_set[$order['reference']])) {
                continue;
            }

            $detail_json = $this->curlGet('/v2/orders/' . rawurlencode($order['reference']));
            if (false === $detail_json) {
                // order not in Miguel (404) or transient error — skip it
                continue;
            }
            $detail = json_decode($detail_json, true);
            if (!is_array($detail)) {
                continue;
            }

            $meta = [
                'id_order' => $order['id_order'],
                'reference' => $order['reference'],
                'date_add' => Tools::displayDate($order['date_add'], $this->context->language->id),
                'order_state' => $order['order_state'],
            ];
            foreach (MiguelApiV2OrderMapper::mapDetailToBooks($detail, $meta) as $row) {
                $orders[] = $row;
            }
        }

        return $orders;
    }

    /**
     * Page through GET /v2/orders?userEmail= and collect all order codes.
     *
     * @param string $user_email
     *
     * @return string[]|false codes, or false on the first request failure
     */
    private function collectMiguelOrderCodes($user_email)
    {
        $codes = [];
        $page = 1;
        do {
            $uri = '/v2/orders?userEmail=' . rawurlencode($user_email) . '&limit=100&page=' . $page;
            $json = $this->curlGet($uri);
            if (false === $json) {
                return $page === 1 ? false : $codes;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                return $page === 1 ? false : $codes;
            }
            foreach (MiguelApiV2OrderMapper::extractCodes($decoded) as $code) {
                $codes[] = $code;
            }
            $page = MiguelApiV2OrderMapper::nextPage($decoded);
        } while (null !== $page);

        return $codes;
    }
```

Add the import near the top of `miguel.php` with the other `use Miguel\Utils\...` lines:

```php
use Miguel\Utils\MiguelApiV2OrderMapper;
```

- [ ] **Step 3: Static analysis + full suite**

Run: `make test-docker`
Expected: PASS — the inbound tests are untouched; the purchased-page logic is covered by Task 3's mapper tests. (The network `curlGet` orchestration is not unit-tested.)

If a local PHPStan run is available it should also pass at level 5; otherwise CI (`phpstan` job) covers it. If PHPStan reports `arrayWithCode()` as unused, delete that method in this task and re-run.

- [ ] **Step 4: Commit**

```bash
git add miguel.php
git commit -m "feat: load purchased books via GET /v2/orders list + detail"
```

---

## Task 5: Version bump + CHANGELOG

**Files:**
- Modify: `miguel.php` — `$this->version` (currently `'1.3.0'`, around line 54)
- Modify: `CHANGELOG.md`

**Interfaces:** none.

- [ ] **Step 1: Bump the module version**

In `miguel.php`, change:

```php
        $this->version = '1.3.0';
```

to:

```php
        $this->version = '1.4.0';
```

- [ ] **Step 2: Add the CHANGELOG entry**

Insert a new section at the top of `CHANGELOG.md`, immediately after the `# CHANGELOG` heading and before `## v1.3.0`:

```markdown
## v1.4.0

Changed:

- Outbound order sync now uses the Miguel API **v2** order endpoint: orders are sent with `POST /v2/orders` (v2 `OrderCreate` shape) instead of `POST /v1/orders`.
- The customer "purchased e-books" page now reads from `GET /v2/orders` (list) and `GET /v2/orders/{code}` (detail) instead of `GET /v1/orders`.
- Order items sent to Miguel no longer include a regular/list price — v2 `OrderCreateItem` carries only the sold `unitPrice` (`withoutVat`). Unpaid orders now send `purchasedAt: null` (v2 derives paid state from `purchasedAt`).

The module's own inbound API (the `orders`, `order`, `products`, and `order-state-callback` resources) is unchanged.
```

- [ ] **Step 3: Verify the suite still passes**

Run: `make test-docker`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add miguel.php CHANGELOG.md
git commit -m "chore: bump module to v1.4.0 and document v2 outbound migration"
```

---

## Self-Review

**Spec coverage:**
- `POST /v2/orders` migration → Task 1 (builder) + Task 2 (wiring). ✓
- Purchased-page list-then-detail → Task 3 (mapper) + Task 4 (wiring). ✓
- Inbound API / `createOrderDetailArray()` / `purchased.tpl` untouched → asserted in Global Constraints; Task 2 Step 3 and Task 4 Step 3 verify inbound tests still pass. ✓
- OrderCreate field mapping (user, currency, purchasedAt, eshopId/dates, addresses, items, source/socialDrmContent) → Task 1 implementation + tests. ✓
- `full_name` → `fullName` remap → Task 1 `toV2Address()` + `testAddressesUseCamelCaseFullName`. ✓
- Regular price dropped / `itemPrice` omitted → Task 1 (`unitPrice` only) + `assertArrayNotHasKey('regular_without_vat')`. ✓
- Empty-order guard → Task 1 `build()` returns null + `testReturnsNullWhenNoSendableItems`; Task 2 skips on null. ✓
- Detail→template mapping (title, formats→download_url, paid, skip no-product, empty formats) → Task 3 tests. ✓
- Pagination via `meta.nextPage` → Task 3 `nextPage()` + Task 4 `collectMiguelOrderCodes()`. ✓
- 404-on-detail = skip → Task 4 Step 2 (`false === $detail_json` continue). ✓
- Version bump + CHANGELOG → Task 5. ✓

**Placeholder scan:** No TBD/TODO/"handle edge cases"; every code step shows full code. ✓

**Type consistency:** `build(\Order, bool): ?array` used identically in Tasks 1 and 2. `extractCodes`/`nextPage`/`mapDetailToBooks` signatures match between Task 3 definition and Task 4 usage. `$orderMeta` keys (`id_order`, `reference`, `date_add`, `order_state`) consistent between Task 3 test/impl and Task 4 construction. Item keys (`code`, `quantity`, `unitPrice.withoutVat`) consistent. ✓
