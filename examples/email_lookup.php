<?php

declare(strict_types=1);

require_once __DIR__ . '/_shared.php';

use function RentManager\Workflows\lookup_by_email;

$email = $argv[1] ?? getenv('RM_EXAMPLE_EMAIL');
if (!$email) {
    throw new RuntimeException('Usage: php examples/email_lookup.php tenant@example.com');
}

$result = lookup_by_email(build_client_from_env(), (string) $email);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
