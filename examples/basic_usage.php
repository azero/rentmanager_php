<?php

declare(strict_types=1);

require_once __DIR__ . '/_shared.php';

use RentManager\RQL;

$client = build_client_from_env();
$tenants = $client->tenants->list([
    'fields' => ['TenantID', 'Name'],
    'filters' => [RQL::eq('IsActive', true)],
    'page_size' => 10,
]);

foreach ($tenants as $tenant) {
    echo $tenant->TenantID . ' ' . $tenant->Name . PHP_EOL;
}
