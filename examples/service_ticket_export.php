<?php

declare(strict_types=1);

require_once __DIR__ . '/_shared.php';

use function RentManager\Workflows\export_newest_service_ticket_details;

$directory = $argv[1] ?? getenv('RM_EXAMPLE_TICKET_EXPORT_DIR') ?: dirname(__DIR__) . '/exports';
$limit = isset($argv[2]) ? (int) $argv[2] : 10;

$manifest = export_newest_service_ticket_details(build_client_from_env(), (string) $directory, $limit);
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
