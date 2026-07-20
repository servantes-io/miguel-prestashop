# Single-Order-by-Code Endpoint Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a front-controller-only `order` API resource that returns a single order by its PrestaShop reference, and enrich every order payload with an integer `id`, structured `billing_address` / `shipping_address`, and a per-line product `quantity`.

**Architecture:** All order payloads are built by two shared builders in the module — `Miguel::createOrderDetailArray()` (the order envelope) and `MiguelApiCreateOrderRequest::createProductsArray()` (the product lines). Enriching those builders makes the new fields appear in all three consumers at once (the `orders` list, the new `order` response, and the outbound `/v1/orders` push). The new endpoint is a thin `case 'order'` in `MiguelApiDispatcher` delegating to a new `Miguel::getOrderByCode()` lookup.

**Tech Stack:** PHP 7.4, PrestaShop 1.7.8 module APIs (`Order`, `Address`, `Country`, `State`, `OrderDetail`, `Db`, `pSQL`), PHPUnit run entirely in Docker.

## Global Constraints

- **PHP 7.4 compatible** — no PHP 8 syntax (no named args, no union return types; nullable `?Type` params are fine).
- **PrestaShop min version 1.7** — use only APIs already used elsewhere in the module.
- **No version bump** — `miguel.php` stays `$this->version = '1.3.0'` (unreleased). Changelog notes go under the existing `## v1.3.0` → Added section.
- **JSON field names are snake_case** (module convention; the backend `PrestashopOrder` parser deserializes by snake_case `[JsonPropertyName]`).
- **Keep the existing flattened `user.address` string** — it is a required field on the backend model; all changes are additive.
- **Every API response is HTTP 200** with the `{ result, debug, <key> }` envelope; errors go in the body via `MiguelApiError`.
- **Run tests with:** `make test-docker` (whole suite) or `make test-docker ARGS="--filter <ClassName>"` (one class). There is no host PHP; always use Docker.
- **Every commit message ends with:**
  `Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>`

---

## Task 1: Product line `quantity`

**Files:**
- Modify: `src/utils/miguel-api-create-order-item.php`
- Modify: `src/utils/miguel-api-create-order-request.php` (both `new MiguelApiCreateOrderItem(...)` call sites — the pack sub-item at ~line 112 and the simple product at ~line 152)
- Create: `tests/Unit/CreateOrderItemTest.php`
- Create: `tests/Unit/OrderDetailArrayTest.php` (shared fixture helper + first builder-output test; reused by Tasks 2 and 3)

**Interfaces:**
- Produces: `new MiguelApiCreateOrderItem(string $code, float $regular_price, float $sold_price, int $quantity)` — quantity is now a **required** 4th argument. `jsonSerialize()` gains `'quantity' => int`. New accessor `getQuantity(): int`.
- Produces: `OrderDetailArrayTest::buildOrder(string $reference, string $productReference, int $quantity = 1): array` returning `[Order, Product, OrderDetail]` (all saved) — reused by later tasks.

- [ ] **Step 1: Write the failing unit test for the item**

Create `tests/Unit/CreateOrderItemTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel\Utils\MiguelApiCreateOrderItem;
use Tests\Unit\Utility\DatabaseTestCase;

class CreateOrderItemTest extends DatabaseTestCase
{
    public function testJsonSerializeIncludesQuantity()
    {
        $item = new MiguelApiCreateOrderItem('9788024271101', 199.0, 149.0, 3);

        $json = $item->jsonSerialize();

        $this->assertSame(3, $json['quantity']);
        $this->assertSame('9788024271101', $json['code']);
        $this->assertSame(199.0, $json['price']['regular_without_vat']);
        $this->assertSame(149.0, $json['price']['sold_without_vat']);
    }

    public function testGetQuantity()
    {
        $item = new MiguelApiCreateOrderItem('X', 1.0, 1.0, 5);

        $this->assertSame(5, $item->getQuantity());
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make test-docker ARGS="--filter CreateOrderItemTest"`
Expected: FAIL — `ArgumentCountError` (constructor wants 3 args) or missing `quantity` key.

- [ ] **Step 3: Add `quantity` to `MiguelApiCreateOrderItem`**

In `src/utils/miguel-api-create-order-item.php`, replace the property block, constructor, and `jsonSerialize`, and add the accessor:

```php
    private $code;
    private $regular_price;
    private $sold_price;
    private $quantity;

    /**
     * Constructor
     *
     * @param string $code Product code
     * @param float $regular_price Regular price without VAT
     * @param float $sold_price Sold price without VAT
     * @param int $quantity Number of units ordered for this line
     */
    public function __construct(string $code, float $regular_price, float $sold_price, int $quantity)
    {
        $this->code = $code;
        $this->regular_price = $regular_price;
        $this->sold_price = $sold_price;
        $this->quantity = $quantity;
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return [
            'code' => $this->code,
            'quantity' => $this->quantity,
            'price' => [
                'regular_without_vat' => $this->regular_price,
                'sold_without_vat' => $this->sold_price,
            ],
        ];
    }
```

Add this accessor next to the other getters:

```php
    /**
     * Get the ordered quantity
     *
     * @return int Quantity
     */
    public function getQuantity()
    {
        return $this->quantity;
    }
```

- [ ] **Step 4: Update both call sites in `createProductsArray`**

In `src/utils/miguel-api-create-order-request.php`, the **pack sub-item** construction becomes (add the 4th arg — the pack line's quantity):

```php
                        $items[] = new MiguelApiCreateOrderItem(
                            $item_data['product']->reference,
                            $pack_item_original_price,
                            $pack_item_unit_price,
                            (int) $product['product_quantity']
                        );
```

In `createArrayFromSimpleProduct`, thread the quantity through. Change the signature call and body so the simple item carries `product_quantity`:

```php
        return new MiguelApiCreateOrderItem(
            $product['product_reference'],
            $product['original_product_price'],
            $product['unit_price_tax_excl'],
            (int) $product['product_quantity']
        );
```

- [ ] **Step 5: Run the item test to verify it passes**

Run: `make test-docker ARGS="--filter CreateOrderItemTest"`
Expected: PASS (2 tests).

- [ ] **Step 6: Write the failing builder-output test (integration)**

Create `tests/Unit/OrderDetailArrayTest.php` with the shared helper and the quantity assertion:

```php
<?php

namespace Tests\Unit;

use Miguel;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class OrderDetailArrayTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
    }

    /**
     * Persists an order + product + order detail and returns [Order, Product, OrderDetail].
     *
     * @return array
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

        return [$order, $product, $orderDetail];
    }

    public function testProductLineCarriesQuantity()
    {
        list($order) = $this->buildOrder('QTYTEST', '9788024271101', 4);

        $result = (new Miguel())->createOrderDetailArray(['id_order' => $order->id]);

        $this->assertIsArray($result);
        $this->assertSame(4, $result['products'][0]['quantity']);
    }
}
```

- [ ] **Step 7: Run it to verify it passes**

Run: `make test-docker ARGS="--filter OrderDetailArrayTest"`
Expected: PASS. (The implementation from Steps 3–4 already satisfies it; this test locks in the end-to-end behavior.)

- [ ] **Step 8: Commit**

```bash
git add src/utils/miguel-api-create-order-item.php src/utils/miguel-api-create-order-request.php tests/Unit/CreateOrderItemTest.php tests/Unit/OrderDetailArrayTest.php
git commit -m "$(cat <<'EOF'
feat: add product-line quantity to order payloads

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Order `id` field

**Files:**
- Modify: `miguel.php` (`createOrderDetailArray`, around the `$body_orders = [];` block near line 439)
- Modify: `tests/Unit/OrderDetailArrayTest.php` (add one test)

**Interfaces:**
- Consumes: `OrderDetailArrayTest::buildOrder(...)` from Task 1.
- Produces: every order payload from `createOrderDetailArray()` now has a top-level `'id' => (int)` as its first key.

- [ ] **Step 1: Write the failing test**

Add to `tests/Unit/OrderDetailArrayTest.php`:

```php
    public function testOrderCarriesIntegerId()
    {
        list($order) = $this->buildOrder('IDTEST', '9788024271101');

        $result = (new Miguel())->createOrderDetailArray(['id_order' => $order->id]);

        $this->assertIsArray($result);
        $this->assertSame((int) $order->id, $result['id']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `make test-docker ARGS="--filter OrderDetailArrayTest::testOrderCarriesIntegerId"`
Expected: FAIL — undefined index `id`.

- [ ] **Step 3: Add `id` to the builder**

In `miguel.php`, in `createOrderDetailArray`, change:

```php
        $body_orders = [];
        $body_orders['code'] = $order->reference;
```

to:

```php
        $body_orders = [];
        $body_orders['id'] = (int) $order->id;
        $body_orders['code'] = $order->reference;
```

- [ ] **Step 4: Run it to verify it passes**

Run: `make test-docker ARGS="--filter OrderDetailArrayTest"`
Expected: PASS (all tests in the class).

- [ ] **Step 5: Commit**

```bash
git add miguel.php tests/Unit/OrderDetailArrayTest.php
git commit -m "$(cat <<'EOF'
feat: add integer order id to order payloads

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Structured billing & shipping addresses

**Files:**
- Modify: `src/utils/miguel-api-create-order-request.php` (add `structureAddress` + private `emptyToNull` helper)
- Modify: `miguel.php` (`createOrderDetailArray` — add `billing_address` / `shipping_address` after the `user` block, near line 447)
- Modify: `tests/Unit/OrderDetailArrayTest.php` (add tests)

**Interfaces:**
- Consumes: `OrderDetailArrayTest::buildOrder(...)` from Task 1.
- Produces: `MiguelApiCreateOrderRequest::structureAddress(?\Address $address): ?array` — returns `null` for a null/unloadable address, otherwise an array with keys `full_name, company, address1, address2, city, state, zip, country, phone` (each a `?string`, empty coerced to `null`).
- Produces: order payloads now have top-level `billing_address` (array) and `shipping_address` (array|null).

- [ ] **Step 1: Write the failing tests**

Add to `tests/Unit/OrderDetailArrayTest.php` (also add `use Address;` and `use Miguel\Utils\MiguelApiCreateOrderRequest;` at the top of the file, next to the existing imports):

```php
    public function testStructureAddressReturnsNullForUnloadableAddress()
    {
        $this->assertNull(MiguelApiCreateOrderRequest::structureAddress(null));
        $this->assertNull(MiguelApiCreateOrderRequest::structureAddress(new Address(0)));
    }

    public function testStructureAddressReturnsExpectedKeys()
    {
        $structured = MiguelApiCreateOrderRequest::structureAddress(new Address(1));

        $this->assertIsArray($structured);
        foreach (['full_name', 'company', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'phone'] as $key) {
            $this->assertArrayHasKey($key, $structured);
        }
    }

    public function testOrderCarriesStructuredAddresses()
    {
        list($order) = $this->buildOrder('ADDRTEST', '9788024271101');

        $result = (new Miguel())->createOrderDetailArray(['id_order' => $order->id]);

        $this->assertIsArray($result['billing_address']);
        // Fixture sets id_address_delivery = 1, so shipping is present too.
        $this->assertIsArray($result['shipping_address']);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `make test-docker ARGS="--filter OrderDetailArrayTest"`
Expected: FAIL — `structureAddress` undefined / undefined index `billing_address`.

- [ ] **Step 3: Add the `structureAddress` helper**

In `src/utils/miguel-api-create-order-request.php`, add these two static methods to the `MiguelApiCreateOrderRequest` class (place `structureAddress` right after `composeAddress`):

```php
    /**
     * Build a structured address array from a PrestaShop Address, or null.
     *
     * @param \Address|null $address
     *
     * @return array<string,string|null>|null
     */
    public static function structureAddress(?\Address $address)
    {
        if (null === $address || false == \Validate::isLoadedObject($address)) {
            return null;
        }

        $country = new \Country((int) $address->id_country);
        $country_iso = \Validate::isLoadedObject($country) ? $country->iso_code : null;

        $state_name = null;
        if ((int) $address->id_state > 0) {
            $state = new \State((int) $address->id_state);
            if (\Validate::isLoadedObject($state)) {
                $state_name = $state->name;
            }
        }

        $full_name = trim($address->firstname . ' ' . $address->lastname);
        $phone = strlen((string) $address->phone) > 0 ? $address->phone : $address->phone_mobile;

        return [
            'full_name' => self::emptyToNull($full_name),
            'company' => self::emptyToNull($address->company),
            'address1' => self::emptyToNull($address->address1),
            'address2' => self::emptyToNull($address->address2),
            'city' => self::emptyToNull($address->city),
            'state' => self::emptyToNull($state_name),
            'zip' => self::emptyToNull($address->postcode),
            'country' => self::emptyToNull($country_iso),
            'phone' => self::emptyToNull($phone),
        ];
    }

    /**
     * @param mixed $value
     *
     * @return string|null
     */
    private static function emptyToNull($value)
    {
        if (null === $value) {
            return null;
        }
        $value = (string) $value;

        return '' === $value ? null : $value;
    }
```

- [ ] **Step 4: Emit the addresses from the builder**

In `miguel.php` `createOrderDetailArray`, immediately after the `$body_orders['user'] = [ ... ];` block, add:

```php
        $body_orders['billing_address'] = MiguelApiCreateOrderRequest::structureAddress($address_invoice);
        $address_delivery = new Address((int) $order->id_address_delivery);
        $body_orders['shipping_address'] = MiguelApiCreateOrderRequest::structureAddress($address_delivery);
```

(`$address_invoice` is already loaded and validated earlier in the method.)

- [ ] **Step 5: Run to verify pass**

Run: `make test-docker ARGS="--filter OrderDetailArrayTest"`
Expected: PASS (all tests).

- [ ] **Step 6: Commit**

```bash
git add src/utils/miguel-api-create-order-request.php miguel.php tests/Unit/OrderDetailArrayTest.php
git commit -m "$(cat <<'EOF'
feat: add structured billing/shipping addresses to order payloads

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 4: `order.not_found` error + `getOrderByCode` lookup

**Files:**
- Modify: `src/utils/miguel-api-error.php` (new factory)
- Modify: `miguel.php` (new `getOrderByCode` method — place it right after `getUpdatedOrders`, near line 757)
- Modify: `tests/Unit/ApiErrorTest.php` (factory test)
- Create: `tests/Unit/GetOrderByCodeTest.php` (lookup tests)

**Interfaces:**
- Produces: `MiguelApiError::orderNotFound($code): MiguelApiError` — code `order.not_found`, message `Order {code} not found`.
- Produces: `Miguel::getOrderByCode(string $code)` — returns the order array (via `createOrderDetailArray`, `getUpdatedOrders` flag on) for the **newest** matching row that has Miguel products, or `false` when none match / none have Miguel products.

- [ ] **Step 1: Write the failing error-factory test**

Add to `tests/Unit/ApiErrorTest.php` a test mirroring the existing ones in that file:

```php
    public function testOrderNotFound()
    {
        $error = \Miguel\Utils\MiguelApiError::orderNotFound('XKBKNABJK');

        $this->assertSame('order.not_found', $error->getCode());
        $this->assertSame('Order XKBKNABJK not found', $error->getMessage());
    }
```

(If `ApiErrorTest` references `MiguelApiError` via a `use` import, call it unqualified to match the file's style.)

- [ ] **Step 2: Run to verify failure**

Run: `make test-docker ARGS="--filter ApiErrorTest::testOrderNotFound"`
Expected: FAIL — `orderNotFound` undefined.

- [ ] **Step 3: Add the factory**

In `src/utils/miguel-api-error.php`, add after `resourceNotFound`:

```php
    public static function orderNotFound($code): MiguelApiError
    {
        return new self('order.not_found', "Order $code not found");
    }
```

- [ ] **Step 4: Run to verify pass**

Run: `make test-docker ARGS="--filter ApiErrorTest::testOrderNotFound"`
Expected: PASS.

- [ ] **Step 5: Write the failing `getOrderByCode` tests**

Create `tests/Unit/GetOrderByCodeTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class GetOrderByCodeTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
    }

    /**
     * @return \Order
     */
    private function persistOrder(string $reference, string $productReference)
    {
        $order = $this->entityCreator->createOrder();
        $order->reference = $reference;
        $order->save();

        $product = new Product();
        $product->name = 'Test product';
        $product->reference = $productReference;
        $product->save();

        $orderDetail = $this->entityCreator->createOrderDetail($order, $product);
        $orderDetail->save();

        return $order;
    }

    public function testReturnsOrderForKnownCode()
    {
        $order = $this->persistOrder('CODEFOUND', '9788024271101');

        $result = (new Miguel())->getOrderByCode('CODEFOUND');

        $this->assertIsArray($result);
        $this->assertSame('CODEFOUND', $result['code']);
        $this->assertSame((int) $order->id, $result['id']);
    }

    public function testReturnsFalseForUnknownCode()
    {
        $this->assertFalse((new Miguel())->getOrderByCode('NOSUCHCODE'));
    }

    public function testReturnsFalseWhenNoMiguelProducts()
    {
        $this->persistOrder('NOREF', '');

        $this->assertFalse((new Miguel())->getOrderByCode('NOREF'));
    }

    public function testReturnsNewestMatchingOrderForSharedReference()
    {
        $older = $this->persistOrder('DUPREF', '9788024271101');
        $newer = $this->persistOrder('DUPREF', '9788024271101');

        $this->assertGreaterThan((int) $older->id, (int) $newer->id);

        $result = (new Miguel())->getOrderByCode('DUPREF');

        $this->assertIsArray($result);
        $this->assertSame((int) $newer->id, $result['id']);
    }
}
```

- [ ] **Step 6: Run to verify failure**

Run: `make test-docker ARGS="--filter GetOrderByCodeTest"`
Expected: FAIL — `getOrderByCode` undefined.

- [ ] **Step 7: Implement `getOrderByCode`**

In `miguel.php`, add after `getUpdatedOrders`:

```php
    /**
     * Return the newest order (by id) matching a reference that still has Miguel
     * products, or false when none match / none have Miguel products.
     *
     * @param string $code order reference
     *
     * @return array<string,mixed>|false
     */
    public function getOrderByCode($code)
    {
        $request = 'SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `reference` = "' . pSQL($code) . '" ORDER BY `id_order` DESC';
        $db = Db::getInstance(false);
        $result = $db->executeS($request);

        if (false == $result) {
            return false;
        }

        foreach ($result as $row) {
            $order_data = $this->createOrderDetailArray(['id_order' => $row['id_order'], 'getUpdatedOrders' => true]);
            if ($order_data) {
                return $order_data;
            }
        }

        return false;
    }
```

- [ ] **Step 8: Run to verify pass**

Run: `make test-docker ARGS="--filter GetOrderByCodeTest"`
Expected: PASS (4 tests).

- [ ] **Step 9: Commit**

```bash
git add src/utils/miguel-api-error.php miguel.php tests/Unit/ApiErrorTest.php tests/Unit/GetOrderByCodeTest.php
git commit -m "$(cat <<'EOF'
feat: add getOrderByCode lookup and order.not_found error

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 5: `order` dispatcher resource + connect endpoint

**Files:**
- Modify: `src/utils/miguel-api-dispatcher.php` (new `case 'order'`)
- Modify: `miguel.php` (`getPrestashopDetails` — add `'order'` to the `endpoints` map, near line 621)
- Modify: `tests/Unit/ApiDispatcherTest.php` (contract tests + connect-endpoint test)
- Create: `tests/Unit/OrderResourceTest.php` (fixture-based end-to-end response test)

**Interfaces:**
- Consumes: `Miguel::getOrderByCode()` and `MiguelApiError::orderNotFound()` from Task 4; `MiguelApiDispatcher::dispatch($resource, $method, $get, $rawBody)` (existing).
- Produces: dispatching `('order', 'GET', ['code' => X], '')` returns a `MiguelApiResponse` whose data key is `order` and whose data is the single order array (or an error envelope).

- [ ] **Step 1: Write the failing dispatcher contract tests**

Add to `tests/Unit/ApiDispatcherTest.php`:

```php
    public function testOrderWithoutCodeReturnsArgumentNotSet()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('order', 'GET', [], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('argument.not_set', $response->getData()->getCode());
    }

    public function testOrderWrongMethodReturnsMethodNotAllowed()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('order', 'POST', ['code' => 'X'], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('method.not_allowed', $response->getData()->getCode());
    }

    public function testOrderUnknownCodeReturnsOrderNotFound()
    {
        $_SERVER['Authorization'] = 'Bearer 1234';

        $response = $this->dispatcher()->dispatch('order', 'GET', ['code' => 'NOSUCHCODE'], '');

        $this->assertFalse($response->getResult());
        $this->assertSame('order.not_found', $response->getData()->getCode());
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `make test-docker ARGS="--filter ApiDispatcherTest"`
Expected: FAIL — `order` falls through to `resource.not_found`.

- [ ] **Step 3: Add the `case 'order'` to the dispatcher**

In `src/utils/miguel-api-dispatcher.php`, inside the `switch ($resource)`, add a case before `default:`:

```php
            case 'order':
                if ($method !== 'GET') {
                    return MiguelApiResponse::error(MiguelApiError::methodNotAllowed($method));
                }
                if (!isset($get['code']) || !is_string($get['code'])) {
                    return MiguelApiResponse::error(MiguelApiError::argumentNotSet('code'));
                }
                $order = $this->module->getOrderByCode($get['code']);
                if (false === $order) {
                    return MiguelApiResponse::error(MiguelApiError::orderNotFound($get['code']));
                }

                return MiguelApiResponse::success($order, 'order');
```

- [ ] **Step 4: Run to verify pass**

Run: `make test-docker ARGS="--filter ApiDispatcherTest"`
Expected: PASS.

- [ ] **Step 5: Write the failing connect-endpoint test**

Add to `tests/Unit/ApiDispatcherTest.php`:

```php
    public function testConnectPayloadReportsOrderEndpoint()
    {
        $details = (new Miguel())->getPrestashopDetails();

        $this->assertArrayHasKey('order', $details['endpoints']);
        $this->assertStringEndsWith('resource=order', $details['endpoints']['order']);
    }
```

- [ ] **Step 6: Run to verify failure**

Run: `make test-docker ARGS="--filter ApiDispatcherTest::testConnectPayloadReportsOrderEndpoint"`
Expected: FAIL — no `order` key in `endpoints`.

- [ ] **Step 7: Add `order` to the connect endpoints map**

In `miguel.php` `getPrestashopDetails`, extend the `$ps['endpoints']` array:

```php
        $ps['endpoints'] = [
            'orders' => $endpointBase . 'orders',
            'order' => $endpointBase . 'order',
            'products' => $endpointBase . 'products',
            'orderStateCallback' => $endpointBase . 'order-state-callback',
        ];
```

- [ ] **Step 8: Run to verify pass**

Run: `make test-docker ARGS="--filter ApiDispatcherTest"`
Expected: PASS.

- [ ] **Step 9: Write the failing end-to-end response test**

Create `tests/Unit/OrderResourceTest.php`:

```php
<?php

namespace Tests\Unit;

use Miguel;
use Miguel\Utils\MiguelApiDispatcher;
use Miguel\Utils\MiguelSettings;
use Product;
use Tests\Unit\Utility\DatabaseTestCase;

class OrderResourceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        error_reporting(E_ALL ^ E_WARNING ^ E_DEPRECATED);
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);

        MiguelSettings::setEnabled(true);
        MiguelSettings::save(MiguelSettings::API_TOKEN_PRODUCTION_KEY, '1234');
        $_SERVER['Authorization'] = 'Bearer 1234';
    }

    protected function tearDown(): void
    {
        unset($_SERVER['Authorization'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_MIGUEL_TOKEN']);
        parent::tearDown();
    }

    public function testReturnsSingleOrderByCode()
    {
        $order = $this->entityCreator->createOrder();
        $order->reference = 'ABCTEST';
        $order->save();

        $product = new Product();
        $product->name = 'Test product';
        $product->reference = '9788024271101';
        $product->save();

        $orderDetail = $this->entityCreator->createOrderDetail($order, $product);
        $orderDetail->product_quantity = 2;
        $orderDetail->save();

        $response = (new MiguelApiDispatcher(new Miguel()))->dispatch('order', 'GET', ['code' => 'ABCTEST'], '');

        $this->assertTrue($response->getResult());
        $this->assertSame('order', $response->getDataKey());

        $data = $response->getData();
        $this->assertSame('ABCTEST', $data['code']);
        $this->assertSame((int) $order->id, $data['id']);
        $this->assertSame(2, $data['products'][0]['quantity']);
        $this->assertIsArray($data['billing_address']);
    }
}
```

- [ ] **Step 10: Run to verify it passes**

Run: `make test-docker ARGS="--filter OrderResourceTest"`
Expected: PASS. (Everything it needs was implemented in Tasks 1–5; this locks in the full response contract.)

- [ ] **Step 11: Commit**

```bash
git add src/utils/miguel-api-dispatcher.php miguel.php tests/Unit/ApiDispatcherTest.php tests/Unit/OrderResourceTest.php
git commit -m "$(cat <<'EOF'
feat: add single-order-by-code API resource

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Task 6: OpenAPI spec + changelog

**Files:**
- Modify: `docs/openapi.yaml`
- Modify: `CHANGELOG.md`

**Interfaces:** none (documentation only).

- [ ] **Step 1: Add `order.not_found` to the error enum**

In `docs/openapi.yaml`, in `components.schemas.ApiError.properties.code.enum`, add `- order.not_found` after `- resource.not_found`.

- [ ] **Step 2: Add `id`, `billing_address`, `shipping_address` to the `Order` schema, `quantity` to `OrderProduct`, and a new `OrderAddress` schema**

In the `OrderProduct` schema `properties`, add before `price`:

```yaml
        quantity:
          type: integer
          description: Number of units ordered for this line.
          default: 1
          example: 2
```

Add a new `OrderAddress` schema under `components.schemas` (place it just before `OrderUser`):

```yaml
    OrderAddress:
      type: object
      description: >
        Structured postal address. Every field is nullable (empty PrestaShop
        values are emitted as null). The whole object is null when the order has
        no loadable address of that kind (only possible for shipping).
      properties:
        full_name:
          type: string
          nullable: true
          example: Jan Novák
        company:
          type: string
          nullable: true
        address1:
          type: string
          nullable: true
          example: Václavské náměstí 1
        address2:
          type: string
          nullable: true
        city:
          type: string
          nullable: true
          example: Praha
        state:
          type: string
          nullable: true
          description: Region/state name, when the address has one.
        zip:
          type: string
          nullable: true
          example: "11000"
        country:
          type: string
          nullable: true
          description: ISO country code.
          example: CZ
        phone:
          type: string
          nullable: true
          example: "+420123456789"
```

In the `Order` schema `properties`, add `id` (before `code`) and the two addresses (after `user`):

```yaml
        id:
          type: integer
          description: PrestaShop order id.
          example: 5002
```

```yaml
        billing_address:
          $ref: '#/components/schemas/OrderAddress'
        shipping_address:
          allOf:
            - $ref: '#/components/schemas/OrderAddress'
          nullable: true
          description: Null when the order has no loadable delivery address.
```

Also update the `Order.properties.update_date.description` to end with: `Present in the orders listing and the single-order response.`

- [ ] **Step 3: Add the `OrderResponse` schema**

Under `components.schemas`, after `OrdersResponse`, add:

```yaml
    OrderResponse:
      type: object
      description: Successful response of the single-order endpoint.
      required: [result, debug, order]
      properties:
        result:
          type: boolean
          enum: [true]
          example: true
        debug:
          type: string
          example: ""
        order:
          $ref: '#/components/schemas/Order'
```

- [ ] **Step 4: Document the new `order` resource path**

In `docs/openapi.yaml` under `paths`, add a new entry after the `/modules/miguel/orders.php` block. OpenAPI path keys cannot contain a query string, so — matching the convention the doc already uses for the other resources — use a `/modules/miguel/order` stand-in path and make the front-controller-only nature explicit in the description:

```yaml
  /modules/miguel/order:
    get:
      tags: [orders]
      summary: Fetch a single order by its code
      operationId: getOrder
      description: >
        Returns the one order whose reference equals `code` and that contains at
        least one Miguel product. When the reference maps to several orders (a
        split checkout), the newest matching order is returned.


        **Front-controller only** — unlike the other resources there is no
        direct-access `.php` script for this endpoint (the path above is a
        documentation stand-in, consistent with the other paths in this file).
        The real URL is
        `{shop}/index.php?fc=module&module=miguel&controller=api&resource=order&code=...`
        (or, with friendly URLs, `{shop}/module/miguel/api?resource=order&code=...`).
      parameters:
        - in: query
          name: code
          required: true
          schema:
            type: string
          description: Order reference.
          example: XKBKNABJK
      responses:
        "200":
          description: >
            Always HTTP 200 — either the single-order envelope or an error
            envelope (missing `code` → `argument.not_set`; no such order / no
            Miguel products → `order.not_found`; auth failures →
            `api_key.not_set` / `api_key.invalid`).
          content:
            application/json:
              schema:
                oneOf:
                  - $ref: '#/components/schemas/OrderResponse'
                  - $ref: '#/components/schemas/ErrorEnvelope'
              examples:
                success:
                  value:
                    result: true
                    debug: ""
                    order:
                      id: 5002
                      code: XKBKNABJK
                      user:
                        id: "42"
                        full_name: Jan Novák
                        email: jan@example.com
                        address: "Jan Novák, Václavské náměstí 1, 11000, Praha, CZ"
                        lang: cs
                      billing_address:
                        full_name: Jan Novák
                        company: null
                        address1: Václavské náměstí 1
                        address2: null
                        city: Praha
                        state: null
                        zip: "11000"
                        country: CZ
                        phone: "+420123456789"
                      shipping_address:
                        full_name: Jan Novák
                        company: null
                        address1: Václavské náměstí 1
                        address2: null
                        city: Praha
                        state: null
                        zip: "11000"
                        country: CZ
                        phone: "+420123456789"
                      purchase_date: "2024-01-15T10:30:00+0000"
                      update_date: "2024-01-16T08:05:00+0000"
                      paid: true
                      currency_code: CZK
                      products:
                        - code: "9788024271101"
                          quantity: 2
                          price:
                            regular_without_vat: 199.0
                            sold_without_vat: 149.0
                not_found:
                  value:
                    result: false
                    debug: ""
                    error:
                      code: order.not_found
                      message: Order XKBKNABJK not found
```

- [ ] **Step 5: Add the changelog bullets**

In `CHANGELOG.md`, under the existing `## v1.3.0` → `Added:` list, append:

```markdown
- New `order` API resource: fetch a single order by its reference (`resource=order&code=…`), returning the order or an `order.not_found` error. Front-controller only.
- Order payloads now include the PrestaShop order `id`.
- Order payloads now include structured `billing_address` and `shipping_address` objects (full name, company, street, city, state, zip, country code, phone).
- Order product lines now include `quantity`.
```

- [ ] **Step 6: Verify the whole suite is green**

Run: `make test-docker`
Expected: PASS — all tests, including the pre-existing `OrdersTest`, `ProductsTest`, `ApiDispatcherTest`, and the new suites.

- [ ] **Step 7: Commit**

```bash
git add docs/openapi.yaml CHANGELOG.md
git commit -m "$(cat <<'EOF'
docs: document order resource, address/quantity/id fields

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Final verification

- [ ] Run the full suite once more: `make test-docker` → all green.
- [ ] `git log --oneline` shows the six feature/doc commits on `feat/order-by-code-endpoint`.
- [ ] Push the branch and open the PR (handled after plan execution).
