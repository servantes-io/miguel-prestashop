# CHANGELOG

## v1.2.3

Added:

- Switched communication to the new **Miguel API v2**.
- Enhanced product data in `getAllProducts` endpoint:
  - Added `price` (tax included) and `price_without_tax`.
  - Added `tax_rate` for each product and combination.
  - Added `currency` ISO code to the response.
- Improved context initialization (Shop, Language, Currency) to ensure accurate price calculation via `getPriceStatic`.
- Support for product combinations: individual prices and references for variants are now correctly handled.

## v1.2.2

Fixed:

- detection of paid state by getting info from Prestashop instead of calculating it in module

## v1.2.0

Added:

- Support for PrestaShop v9.0

## v1.1.1

Added:

- User-Agent header to every request to or from Miguel backend

## v1.1.0

Added:

- Proper support for bundles (aka Pack of products)

## v1.0.5

Fixes:

- Communication from our backend to Prestshop module: when trying to fetch missed orders

## v1.0.4

Fixes:

- Communication with our backend server: better handling of empty product references in orders

## v1.0.3

Fixes:

- Communication with our backend server

## v1.0.2

Fixes:

- Syntax error when there is some error in orders.php, products.php and order-state-callback.php endpoints

Refactorings:

- Add more automated tests
- Introduce namespaces

## v1.0.1

- Refactoring for better future support
- Changes for Prestashop Marketplace
- Refactor API endpoints to remove duplicate code
- Add some unit tests

## v1.0.0

Init version.
