<?php

declare(strict_types=1);

require_once __DIR__ . '/_shared.php';

function env_truthy(string $name): bool
{
    $value = getenv($name);
    return is_string($value) && in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function optional_env(string $name): ?string
{
    $value = getenv($name);
    if (!is_string($value)) {
        return null;
    }
    $value = trim($value);
    return $value === '' ? null : $value;
}

function digits_only(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?? '';
}

function recipient_phone_for_rent_manager(string $value): string
{
    $digits = digits_only($value);
    if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
        return substr($digits, 1);
    }
    return $digits;
}

function row_value(mixed $row, string $field): mixed
{
    if (is_array($row)) {
        return $row[$field] ?? null;
    }
    if (is_object($row)) {
        return $row->{$field} ?? null;
    }
    return null;
}

load_env_file();

$phone = $argv[1] ?? optional_env('RM_DEMO_TENANT_PHONE');
$message = $argv[2] ?? optional_env('RM_DEMO_TEXT_MESSAGE') ?? 'This is a RentManager-PHP demo text.';
$tenantId = optional_env('RM_DEMO_TENANT_ID');
$sendingPhoneNumber = optional_env('RM_DEMO_SENDING_PHONE_NUMBER');
$historyCategoryId = optional_env('RM_DEMO_HISTORY_CATEGORY_ID');
$shouldSend = env_truthy('RM_DEMO_SEND_TEXT');

if (!$phone) {
    throw new RuntimeException('Usage: php examples/send_tenant_text.php +15551234567 "Message text"');
}

$payload = [
    'PhoneNumber' => recipient_phone_for_rent_manager($phone),
    'Message' => $message,
];

if ($tenantId !== null) {
    $payload['ParentID'] = ctype_digit($tenantId) ? (int) $tenantId : $tenantId;
    $payload['ParentType'] = 'Tenant';
}

echo "Outgoing text payload:\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (!$shouldSend) {
    echo "\nDry run only. Set RM_DEMO_SEND_TEXT=1 in .env to POST this to Rent Manager.\n";
    exit(0);
}

$client = build_client_from_env();

if ($tenantId !== null) {
    $tenant = $client->tenants->get($tenantId, ['fields' => ['TenantID', 'Name']]);
    echo "\nValidated tenant: " . json_encode($tenant, JSON_UNESCAPED_SLASHES) . PHP_EOL;
}

if ($sendingPhoneNumber === null) {
    $textNumbers = $client->resource('text_numbers');
    $availableTextNumbers = $textNumbers?->list([
        'fields' => ['TextNumberID', 'TextNumber', 'StrippedTextNumber', 'IsActive'],
        'filters' => ['IsActive,eq,true'],
        'page_size' => 1,
    ]);
    $firstTextNumber = is_array($availableTextNumbers) ? ($availableTextNumbers[0] ?? null) : null;
    $strippedTextNumber = $firstTextNumber !== null ? row_value($firstTextNumber, 'StrippedTextNumber') : null;
    if ($strippedTextNumber) {
        $sendingPhoneNumber = (string) $strippedTextNumber;
    }
}

if ($sendingPhoneNumber !== null) {
    $payload['SendingPhoneNumberString'] = digits_only($sendingPhoneNumber);
}

$payload['LocationID'] = -1;
$payload['HistoryCategoryID'] = $historyCategoryId !== null && ctype_digit($historyCategoryId)
    ? (int) $historyCategoryId
    : -1;

echo "\nFinal outgoing text payload:\n";
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

$result = $client->post('TextMessagingConversations/SendText', $payload);

echo "\nRent Manager response:\n";
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
