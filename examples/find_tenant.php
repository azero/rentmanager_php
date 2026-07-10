<?php

declare(strict_types=1);

require_once __DIR__ . '/_shared.php';

use RentManager\RQL;

load_env_file();

$name = $argv[1] ?? getenv('RM_DEMO_TENANT_NAME');
if (!is_string($name) || trim($name) === '') {
    throw new RuntimeException('Usage: php examples/find_tenant.php "Example Tenant"');
}

$name = trim($name);
$terms = array_values(array_unique(array_filter([
    $name,
    ...preg_split('/\s+/', $name) ?: [],
])));

$client = build_client_from_env();
$seen = [];
$matches = [];

foreach ($terms as $term) {
    $tenants = $client->tenants->list([
        'fields' => ['TenantID', 'Name', 'FirstName', 'LastName', 'IsActive'],
        'filters' => [RQL::ct('Name', $term)],
        'page_size' => 20,
    ]);

    foreach ($tenants as $tenant) {
        $key = (string) ($tenant->TenantID ?? json_encode($tenant));
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $matches[] = $tenant;
    }
}

echo json_encode([
    'searched_name' => $name,
    'match_count' => count($matches),
    'matches' => $matches,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
