# RentManager-PHP

[![CI](https://github.com/azero/rentmanager_php/actions/workflows/ci.yml/badge.svg)](https://github.com/azero/rentmanager_php/actions/workflows/ci.yml)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4.svg)](https://www.php.net/)
[![License: PolyForm Noncommercial 1.0.0](https://img.shields.io/badge/license-PolyForm%20Noncommercial%201.0.0-blue.svg)](LICENSE)

A dependency-free PHP 8.2+ SDK for Rent Manager WAPI12, ported from the companion RentManager-Python project. It provides a client, a generic caller for any WAPI resource or action, generated resource facades, permissive model objects, RQL query helpers, pagination, structured errors, token storage, multipart uploads, downloads, and optional workflow helpers.

The public Rent Manager WAPI12 overview documents the REST shape, authentication, query parameters, pagination, rate-limit headers, concurrency behavior, and standard response codes, but it does not publish a complete endpoint/model catalog. This package therefore bundles the endpoint information used by the Python implementation while retaining a generic caller for newly discovered resources.

> [!IMPORTANT]
> This is an independent community project. It is not affiliated with, endorsed by, or supported by London Computer Systems or Rent Manager. Rent Manager API access is a separate service and may require an eligible account or subscription.

## Requirements

- PHP 8.2 or newer
- The JSON extension, included with supported PHP versions
- cURL is recommended; PHP streams are used as a fallback
- Valid Rent Manager WAPI12 credentials for live API calls

## Installation

Clone the repository and require its autoloader:

```php
require_once '/path/to/rentmanager_php/autoload.php';
```

For a Composer-managed application, register this Git repository as a VCS repository until a tagged package is published:

```bash
composer config repositories.rentmanager-php vcs https://github.com/azero/rentmanager_php
composer require azero/rentmanager-php:dev-main
```

## Basic usage

```php
<?php

require_once __DIR__ . '/autoload.php';

use RentManager\RentManagerClient;
use RentManager\RQL;

$client = new RentManagerClient(
    corpId: getenv('RM_CORP_ID'),
    username: getenv('RM_USERNAME'),
    password: getenv('RM_PASSWORD'),
    locationId: (int) getenv('RM_LOCATION_ID'),
);

$tenants = $client->tenants->list([
    'fields' => ['TenantID', 'Name'],
    'filters' => [RQL::eq('IsActive', true)],
    'page_size' => 100,
]);

foreach ($tenants as $tenant) {
    echo $tenant->TenantID . ' ' . $tenant->Name . PHP_EOL;
}
```

## Configuration and credentials

Copy `.env.example` to `.env` for the included examples:

```dotenv
RM_CORP_ID=your-corp-id
RM_USERNAME=your-api-user
RM_PASSWORD=your-password
RM_LOCATION_ID=1
RM_TOKEN_FILE=.rentmanager-token.json
```

The examples load `.env`; the SDK itself does not. `.env`, token caches, exports, private keys, and common credential-file patterns are excluded by `.gitignore`. Never put real values in `.env.example`.

Shell environment variables take precedence over `.env` values. Set `RM_ENV_FILE` to use a different environment file.

For persistent token caching, explicitly provide a `FileTokenStore`:

```php
use RentManager\FileTokenStore;

$client = new RentManagerClient(
    corpId: getenv('RM_CORP_ID'),
    username: getenv('RM_USERNAME'),
    password: getenv('RM_PASSWORD'),
    tokenStore: new FileTokenStore(__DIR__ . '/.rentmanager-token.json'),
);
```

## Running examples

Find a tenant by name:

```powershell
php examples\find_tenant.php "Smith"
```

Look up records containing an email address:

```powershell
php examples\email_lookup.php tenant@example.com
```

Prepare a tenant text-message payload without sending it:

```powershell
php examples\send_tenant_text.php +15551234567 "This is a RentManager-PHP demo text."
```

The text-message example remains a dry run unless `RM_DEMO_SEND_TEXT=1` is explicitly set. Review the printed payload before enabling that setting.

Run broad read-only API smoke checks with configured credentials:

```powershell
php examples\read_only_smoke.php
```

Export the newest service-ticket details to the ignored `exports` directory:

```powershell
php examples\service_ticket_export.php
```

## Generic and generated resources

```php
$rows = $client->get('CustomResource', query: ['filters' => 'Name,ct,Smith']);
$result = $client->action('RecurringCharges/PostRecurringCharges', ['PostDate' => '2026-04-23']);

$payments = $client->account_group_master_payments->list();
$resource = $client->resource('account_group_master_payments');
```

Inspect the bundled endpoint catalog:

```php
use RentManager\EndpointRegistry;

foreach (EndpointRegistry::default()->coverageReport() as $endpoint) {
    echo "{$endpoint['method']} {$endpoint['path']} {$endpoint['confidence']}" . PHP_EOL;
}
```

### Generated namespace naming

- Model names use a singular PascalCase resource name when singularization is clear.
- Client properties use snake case, such as `Vendor1099Categories` becoming `$client->vendor_1099_categories`.
- Existing grouped namespaces remain grouped, such as `$client->serviceManager->issues`.
- Standard resources inherit `list`, `get`, `create`, `update`, `delete`, `paginate`, and `iterPages` from `Resource`.
- Catalog-derived registry rows use `live_help_catalog` confidence and identify the authenticated WAPI12 Help catalog as their source.
- Tests use mock transports for write and delete behavior; live examples do not perform broad write/delete testing.

## Workflow helpers

The `RentManager\Workflows` namespace includes:

- `lookup_by_email($client, $email)`
- `get_newest_service_tickets($client, $limit = 10)`
- `get_service_ticket_details($client, $ticketId)`
- `export_newest_service_ticket_details($client, $directory, $limit = 10)`
- `create_service_ticket($client, $payload)`
- `update_ticket_line_items($client, $ticketId, $lineItems)`

Service-ticket exports can contain tenant, vendor, history, and attachment metadata. Export directories and files are created with private permissions where the operating system supports them. Keep exports local and review them before sharing.

The text-message example is dry-run by default. It will send only when `RM_DEMO_SEND_TEXT=1` is explicitly set.

## Development

Run all checks without installing dependencies:

```bash
php tests/lint.php
php tests/run.php
```

Or, with Composer installed:

```bash
composer check
```

The tests use a mock transport and do not require Rent Manager credentials. CI runs linting and tests across all supported PHP versions.

`AsyncRentManagerClient` is a compatibility subclass of the synchronous client because PHP has no built-in async/await equivalent without additional dependencies.

## Security

See [SECURITY.md](SECURITY.md) for private vulnerability reporting and credential-handling guidance.

## License

This project is source-available under the [PolyForm Noncommercial License 1.0.0](LICENSE). Noncommercial reuse and modification are permitted as long as the license and required attribution notices are preserved. Commercial use is not granted.

This project includes work derived from the MIT-licensed RentManager-Python project. See [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).
