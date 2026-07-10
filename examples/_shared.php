<?php

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use RentManager\RentManagerClient;
use RentManager\FileTokenStore;

function load_env_file(?string $path = null, bool $override = false): void
{
    $path ??= getenv('RM_ENV_FILE') ?: dirname(__DIR__) . '/.env';
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = preg_replace('/^\xEF\xBB\xBF/', '', trim($line));
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            $value = substr($value, 1, -1);
        }
        if ($override || getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

function build_client_from_env(): RentManagerClient
{
    load_env_file();
    foreach (['RM_CORP_ID', 'RM_USERNAME', 'RM_PASSWORD'] as $name) {
        if (!getenv($name)) {
            throw new RuntimeException("Missing required environment variable {$name}.");
        }
    }

    $location = getenv('RM_LOCATION_ID');
    $tokenFile = getenv('RM_TOKEN_FILE') ?: '.rentmanager-token.json';
    if (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/])/', (string) $tokenFile)) {
        $tokenFile = dirname(__DIR__) . '/' . ltrim((string) $tokenFile, "\\/");
    }
    return new RentManagerClient(
        corpId: (string) getenv('RM_CORP_ID'),
        username: (string) getenv('RM_USERNAME'),
        password: (string) getenv('RM_PASSWORD'),
        locationId: $location ? (int) $location : null,
        tokenStore: new FileTokenStore((string) $tokenFile),
    );
}
