<?php

declare(strict_types=1);

require_once __DIR__ . '/_shared.php';

use RentManager\Catalog;
use RentManager\RentManagerAPIError;
use RentManager\RQL;
use function RentManager\Workflows\get_newest_service_tickets;
use function RentManager\Workflows\get_service_ticket_details;
use function RentManager\Workflows\lookup_by_email;

function smoke_row_to_array(mixed $row): array
{
    if (is_array($row)) {
        return $row;
    }
    if (is_object($row) && method_exists($row, 'toArray')) {
        return $row->toArray();
    }
    if (is_object($row) && $row instanceof JsonSerializable) {
        $json = json_encode($row);
        $decoded = json_decode($json ?: '{}', true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function smoke_count_rows(mixed $value): int
{
    if (is_array($value)) {
        return count($value);
    }
    return $value === null ? 0 : 1;
}

function smoke_error(Throwable $error): array
{
    return [
        'type' => $error::class,
        'message' => $error->getMessage(),
        'status_code' => $error instanceof RentManagerAPIError ? $error->statusCode : null,
    ];
}

function smoke_first_id(array $row, array $spec): int|string|null
{
    $candidates = [];
    $modelName = $spec['model_name'] ?? null;
    if (is_string($modelName) && $modelName !== '') {
        $candidates[] = $modelName . 'PrimaryKeyID';
    }
    foreach (($spec['fields'] ?? []) as $field) {
        if (is_string($field) && str_ends_with($field, 'PrimaryKeyID')) {
            $candidates[] = $field;
        }
    }
    if (is_string($modelName) && $modelName !== '') {
        $candidates[] = $modelName . 'ID';
    }
    foreach (($spec['fields'] ?? []) as $field) {
        if (is_string($field) && (str_ends_with($field, 'ID') || in_array($field, ['ID', 'Id'], true))) {
            $candidates[] = $field;
        }
    }
    array_push($candidates, 'ID', 'Id');
    foreach (array_unique($candidates) as $field) {
        if (isset($row[$field]) && (is_int($row[$field]) || is_string($row[$field])) && (string) $row[$field] !== '') {
            return $row[$field];
        }
    }
    return null;
}

function smoke_try(string $name, callable $fn, array &$bucket): mixed
{
    try {
        $result = $fn();
        $bucket[] = ['name' => $name, 'ok' => true];
        return $result;
    } catch (Throwable $error) {
        $bucket[] = ['name' => $name, 'ok' => false, 'error' => smoke_error($error)];
        return null;
    }
}

$startedAt = microtime(true);
$client = build_client_from_env();
$tenantId = getenv('RM_DEMO_TENANT_ID') ?: null;

$checks = [];
$resourceResults = [];
$resourceGetResults = [];
$endpointResults = [];
$childChecks = [];
$workflowChecks = [];

$authToken = smoke_try('authenticate', static fn () => $client->authenticate(), $checks);
if (!is_string($authToken) || $authToken === '') {
    echo json_encode([
        'read_only' => true,
        'duration_seconds' => round(microtime(true) - $startedAt, 2),
        'blocked' => true,
        'reason' => 'Authentication failed; no read-only resource checks were run.',
        'sdk_checks' => [
            'total' => count($checks),
            'passed' => count(array_filter($checks, static fn (array $row): bool => $row['ok'] === true)),
            'failed' => count(array_filter($checks, static fn (array $row): bool => $row['ok'] === false)),
            'failures' => array_values(array_filter($checks, static fn (array $row): bool => $row['ok'] === false)),
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(1);
}
smoke_try('users.currentUser', static fn () => $client->users->currentUser(['fields' => ['UserID', 'Name', 'Username']]), $checks);
smoke_try('tenants.paginate', static fn () => $client->tenants->paginate(['page_size' => 1]), $checks);
smoke_try('tenants.iterPages', static function () use ($client): int {
    $count = 0;
    foreach ($client->tenants->iterPages(['page_size' => 1, 'max_pages' => 2]) as $_page) {
        $count++;
    }
    return $count;
}, $checks);

$resourceSpecs = Catalog::resourceSpecs();
usort($resourceSpecs, static fn (array $a, array $b): int => strcmp((string) ($a['client_attr'] ?? ''), (string) ($b['client_attr'] ?? '')));

foreach ($resourceSpecs as $spec) {
    $attr = $spec['client_attr'] ?? null;
    $operations = $spec['operations'] ?? [];
    if (!is_string($attr) || !in_array('list', $operations, true)) {
        continue;
    }

    $resource = $client->resource($attr);
    if ($resource === null) {
        $resourceResults[] = ['resource' => $attr, 'ok' => false, 'error' => ['type' => 'missing_resource', 'message' => 'Resource facade not attached.']];
        continue;
    }

    try {
        $rows = $resource->list(['page_size' => 1]);
        $resourceResults[] = ['resource' => $attr, 'ok' => true, 'row_count' => smoke_count_rows($rows)];

        if (in_array('get', $operations, true) && is_array($rows) && isset($rows[0])) {
            $id = smoke_first_id(smoke_row_to_array($rows[0]), $spec);
            if ($id !== null) {
                try {
                    $resource->get($id);
                    $resourceGetResults[] = ['resource' => $attr, 'ok' => true];
                } catch (Throwable $error) {
                    $resourceGetResults[] = ['resource' => $attr, 'ok' => false, 'error' => smoke_error($error)];
                }
            }
        }
    } catch (Throwable $error) {
        $resourceResults[] = ['resource' => $attr, 'ok' => false, 'error' => smoke_error($error)];
    }
}

foreach (Catalog::endpoints() as $endpoint) {
    if (($endpoint['method'] ?? null) !== 'GET') {
        continue;
    }
    $path = (string) ($endpoint['path'] ?? '');
    if ($path === '' || str_contains($path, '{')) {
        continue;
    }

    try {
        $client->get(ltrim($path, '/'), query: ['page_size' => 1]);
        $endpointResults[] = ['endpoint' => $path, 'ok' => true];
    } catch (Throwable $error) {
        $endpointResults[] = ['endpoint' => $path, 'ok' => false, 'error' => smoke_error($error)];
    }
}

if ($tenantId !== null && $tenantId !== '') {
    foreach ([
        'tenants.get' => static fn () => $client->tenants->get($tenantId, ['fields' => ['TenantID', 'Name', 'Status']]),
        'tenants.transactions' => static fn () => $client->tenants->transactions($tenantId, ['page_size' => 1]),
        'tenants.history' => static fn () => $client->tenants->history($tenantId, ['page_size' => 1]),
        'tenants.contacts' => static fn () => $client->tenants->contacts($tenantId, ['page_size' => 1]),
        'tenants.leases' => static fn () => $client->tenants->leases($tenantId, ['page_size' => 1]),
        'tenants.recurringCharges' => static fn () => $client->tenants->recurringCharges($tenantId, ['page_size' => 1]),
        'tenants.userDefinedValues' => static fn () => $client->tenants->userDefinedValues($tenantId, ['page_size' => 1]),
        'tenants.userDefinedFields' => static fn () => $client->tenants->userDefinedFields($tenantId, ['page_size' => 1]),
        'tenants.addresses' => static fn () => $client->tenants->addresses($tenantId, ['page_size' => 1]),
    ] as $name => $fn) {
        smoke_try($name, $fn, $childChecks);
    }
}

foreach ([
    'serviceManager.statuses.list' => static fn () => $client->serviceManager->statuses->list(['page_size' => 1]),
    'serviceManager.categories.list' => static fn () => $client->serviceManager->categories->list(['page_size' => 1]),
    'serviceManager.priorities.list' => static fn () => $client->serviceManager->priorities->list(['page_size' => 1]),
    'serviceManager.issues.list' => static fn () => $client->serviceManager->issues->list(['page_size' => 1]),
] as $name => $fn) {
    smoke_try($name, $fn, $childChecks);
}

$newestTickets = smoke_try('workflow.get_newest_service_tickets', static fn () => get_newest_service_tickets($client, limit: 1, pageSize: 5, maxPages: 1), $workflowChecks);
if (is_array($newestTickets) && isset($newestTickets[0])) {
    $ticket = smoke_row_to_array($newestTickets[0]);
    $ticketId = $ticket['ServiceManagerIssueID'] ?? $ticket['IssueID'] ?? $ticket['ID'] ?? null;
    if ($ticketId !== null) {
        smoke_try('workflow.get_service_ticket_details', static fn () => get_service_ticket_details($client, $ticketId), $workflowChecks);
    }
}

$email = getenv('RM_EXAMPLE_EMAIL');
if (is_string($email) && $email !== '' && $email !== 'tenant@example.com') {
    smoke_try('workflow.lookup_by_email', static fn () => lookup_by_email($client, $email), $workflowChecks);
}

$resourceOk = array_values(array_filter($resourceResults, static fn (array $row): bool => $row['ok'] === true));
$resourceFail = array_values(array_filter($resourceResults, static fn (array $row): bool => $row['ok'] === false));
$getOk = array_values(array_filter($resourceGetResults, static fn (array $row): bool => $row['ok'] === true));
$getFail = array_values(array_filter($resourceGetResults, static fn (array $row): bool => $row['ok'] === false));
$endpointOk = array_values(array_filter($endpointResults, static fn (array $row): bool => $row['ok'] === true));
$endpointFail = array_values(array_filter($endpointResults, static fn (array $row): bool => $row['ok'] === false));

$summary = [
    'read_only' => true,
    'duration_seconds' => round(microtime(true) - $startedAt, 2),
    'sdk_checks' => [
        'total' => count($checks),
        'passed' => count(array_filter($checks, static fn (array $row): bool => $row['ok'] === true)),
        'failed' => count(array_filter($checks, static fn (array $row): bool => $row['ok'] === false)),
        'failures' => array_values(array_filter($checks, static fn (array $row): bool => $row['ok'] === false)),
    ],
    'resource_list_checks' => [
        'total' => count($resourceResults),
        'passed' => count($resourceOk),
        'failed' => count($resourceFail),
        'failures_sample' => array_slice($resourceFail, 0, 40),
    ],
    'resource_get_checks' => [
        'total' => count($resourceGetResults),
        'passed' => count($getOk),
        'failed' => count($getFail),
        'failures_sample' => array_slice($getFail, 0, 40),
    ],
    'catalog_collection_get_checks' => [
        'total' => count($endpointResults),
        'passed' => count($endpointOk),
        'failed' => count($endpointFail),
        'failures_sample' => array_slice($endpointFail, 0, 40),
    ],
    'child_read_checks' => [
        'total' => count($childChecks),
        'passed' => count(array_filter($childChecks, static fn (array $row): bool => $row['ok'] === true)),
        'failed' => count(array_filter($childChecks, static fn (array $row): bool => $row['ok'] === false)),
        'failures' => array_values(array_filter($childChecks, static fn (array $row): bool => $row['ok'] === false)),
    ],
    'workflow_read_checks' => [
        'total' => count($workflowChecks),
        'passed' => count(array_filter($workflowChecks, static fn (array $row): bool => $row['ok'] === true)),
        'failed' => count(array_filter($workflowChecks, static fn (array $row): bool => $row['ok'] === false)),
        'failures' => array_values(array_filter($workflowChecks, static fn (array $row): bool => $row['ok'] === false)),
    ],
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
