<?php

declare(strict_types=1);

namespace RentManager\Workflows;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use RentManager\RentManagerAPIError;
use RentManager\RentManagerNotFoundError;

const SERVICE_TICKET_FIELDS = [
    'ServiceManagerIssueID',
    'IssueID',
    'TicketID',
    'Title',
    'Description',
    'CreateDate',
    'DateCreated',
    'IssueDate',
    'StatusID',
    'CategoryID',
    'PriorityID',
    'PropertyID',
    'UnitID',
    'TenantID',
    'VendorID',
    'AssignedToUserID',
    'CreateUserID',
];

const ISSUE_DETAIL_EMBEDS = ['Status', 'Category', 'Priority', 'Property', 'Unit', 'Tenant', 'Vendor', 'History', 'LineItems'];

function parse_ticket_datetime(mixed $value): DateTimeImmutable
{
    if ($value instanceof DateTimeImmutable) {
        return $value;
    }
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }
    if (is_string($value) && trim($value) !== '') {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
        }
    }
    return new DateTimeImmutable('@0');
}

function ticket_created_at(array|object $ticket): DateTimeImmutable
{
    $row = is_object($ticket) && method_exists($ticket, 'toArray') ? $ticket->toArray() : (array) $ticket;
    foreach (['CreateDate', 'DateCreated', 'IssueDate'] as $key) {
        if (array_key_exists($key, $row)) {
            $parsed = parse_ticket_datetime($row[$key]);
            if ($parsed->getTimestamp() !== 0) {
                return $parsed;
            }
        }
    }
    return parse_ticket_datetime(null);
}

/** @return list<mixed> */
function get_newest_service_tickets(mixed $client, int $limit = 10, int $pageSize = 100, int $maxPages = 10): array
{
    $tickets = [];
    foreach ($client->serviceManager->issues->iterPages(['page_size' => $pageSize, 'max_pages' => $maxPages, 'fields' => SERVICE_TICKET_FIELDS]) as $page) {
        foreach ($page->data as $ticket) {
            $tickets[] = $ticket;
        }
    }
    usort($tickets, static fn (mixed $a, mixed $b): int => ticket_created_at(normalize_record($b))->getTimestamp() <=> ticket_created_at(normalize_record($a))->getTimestamp());
    return array_slice($tickets, 0, $limit);
}

/** @return array<string,mixed> */
function get_service_ticket_details(mixed $client, int|string $ticketId, bool $includeChildren = true): array
{
    $details = normalize_record($client->serviceManager->issues->get($ticketId, ['embed' => ISSUE_DETAIL_EMBEDS]));
    if (!$includeChildren) {
        return $details;
    }

    $children = [
        'history' => 'history',
        'properties' => 'properties',
        'line_items' => 'lineItems',
        'attachments' => 'attachments',
        'files' => 'files',
        'documents' => 'documents',
        'user_defined_values' => 'userDefinedValues',
        'user_defined_fields' => 'userDefinedFields',
    ];
    $unavailable = [];
    $errors = [];
    foreach ($children as $key => $method) {
        try {
            $value = $client->serviceManager->issues->{$method}($ticketId);
            $details[$key] = collection_list($value);
        } catch (RentManagerAPIError $error) {
            if (is_unsupported_endpoint($error)) {
                $unavailable[] = $key;
            } else {
                $errors[] = ['endpoint' => $key, 'message' => $error->getMessage(), 'status_code' => $error->statusCode];
            }
        }
    }
    $details['unavailable_endpoints'] = $unavailable;
    $details['errors'] = $errors;
    $details['billing_summary'] = billing_summary($details['line_items'] ?? []);
    return $details;
}

/** @return array<string,mixed> */
function export_newest_service_ticket_details(mixed $client, string $directory, int $limit = 10): array
{
    $tickets = get_newest_service_tickets($client, $limit);
    $stamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Ymd-His');
    $root = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'service-ticket-export-' . $stamp;
    create_private_directory($root);

    $manifest = ['export_dir' => $root, 'ticket_count' => 0, 'tickets' => [], 'errors' => []];
    foreach ($tickets as $ticket) {
        $row = normalize_record($ticket);
        $id = extract_ticket_id($row);
        if ($id === null) {
            $manifest['errors'][] = ['message' => 'Unable to determine ticket id.', 'ticket' => $row];
            continue;
        }
        $ticketDir = $root . DIRECTORY_SEPARATOR . 'ticket-' . $id;
        create_private_directory($ticketDir);
        $details = get_service_ticket_details($client, $id);
        write_private_json($ticketDir . DIRECTORY_SEPARATOR . 'details.json', $details);
        $manifest['tickets'][] = ['ticket_id' => $id, 'directory' => $ticketDir];
        $manifest['ticket_count']++;
    }
    write_private_json($root . DIRECTORY_SEPARATOR . 'manifest.json', $manifest);
    return $manifest;
}

function create_private_directory(string $path): void
{
    if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
        throw new \RuntimeException("Unable to create export directory {$path}.");
    }
    @chmod($path, 0700);
}

function write_private_json(string $path, mixed $value): void
{
    $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
        throw new \RuntimeException("Unable to write export file {$path}.");
    }
    @chmod($path, 0600);
}

function create_service_ticket(mixed $client, array $payload): mixed
{
    return $client->serviceManager->issues->create($payload);
}

function update_ticket_line_items(mixed $client, int|string $ticketId, array $lineItems): mixed
{
    return $client->serviceManager->issues->addLineItems($ticketId, $lineItems);
}

/** @return array<string,mixed> */
function normalize_record(mixed $record): array
{
    if (is_object($record) && method_exists($record, 'toArray')) {
        return $record->toArray();
    }
    return is_array($record) ? $record : ['value' => $record];
}

/** @return list<mixed> */
function collection_list(mixed $value): array
{
    if (is_object($value) && method_exists($value, 'toArray')) {
        $value = $value->toArray();
    }
    if ($value === [] || $value === null) {
        return [];
    }
    if (is_array($value) && array_is_list($value)) {
        return $value;
    }
    if (is_array($value) && count($value) === 0) {
        return [];
    }
    return [$value];
}

function is_unsupported_endpoint(RentManagerAPIError $error): bool
{
    $message = strtolower($error->getMessage());
    return $error instanceof RentManagerNotFoundError && str_contains($message, 'no http resource was found');
}

function extract_ticket_id(array $row): ?int
{
    foreach (['ServiceManagerIssueID', 'IssueID', 'TicketID', 'ID', 'Id'] as $key) {
        if (isset($row[$key]) && is_numeric($row[$key])) {
            return (int) $row[$key];
        }
    }
    return null;
}

/** @param list<mixed> $rows @return array<string,mixed> */
function billing_summary(array $rows): array
{
    $totalCost = 0.0;
    $totalPrice = 0.0;
    foreach ($rows as $row) {
        $row = normalize_record($row);
        $totalCost += (float) ($row['Cost'] ?? $row['TotalCost'] ?? 0);
        $totalPrice += (float) ($row['Price'] ?? $row['Amount'] ?? $row['TotalPrice'] ?? 0);
    }
    return ['row_count' => count($rows), 'total_cost' => $totalCost, 'total_price' => $totalPrice];
}
