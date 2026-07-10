<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80200) {
    throw new RuntimeException('RentManager-PHP requires PHP 8.2 or newer.');
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'RentManager\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    foreach (['Support', 'Errors', 'TokenStore', 'Pagination', 'Query', 'Resource', 'Registry'] as $groupedFile) {
        require_once __DIR__ . '/src/' . $groupedFile . '.php';
    }

    $relative = substr($class, strlen($prefix));

    if (str_starts_with($relative, 'Models\\')) {
        require_once __DIR__ . '/src/Models/RMBaseModel.php';
        require_once __DIR__ . '/src/Models/GeneratedModels.php';
        return;
    }

    if ($relative === 'AsyncRentManagerClient') {
        require_once __DIR__ . '/src/RentManagerClient.php';
        return;
    }

    $path = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

foreach ([
    'src/Support.php',
    'src/Errors.php',
    'src/TokenStore.php',
    'src/Pagination.php',
    'src/Query.php',
    'src/Registry.php',
    'src/Workflows/EmailLookup.php',
    'src/Workflows/ServiceTickets.php',
] as $file) {
    require_once __DIR__ . '/' . $file;
}
