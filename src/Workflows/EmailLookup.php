<?php

declare(strict_types=1);

namespace RentManager\Workflows;

use RentManager\Models\RMBaseModel;

/** @return array<string,mixed> */
function lookup_by_email(mixed $client, string $email, bool $includeContacts = true): array
{
    $target = strtolower(trim($email));
    if ($target === '') {
        return ['sender_email' => $email, 'total_matches' => 0, 'matches' => []];
    }

    $resources = [
        'owner' => $client->owners ?? null,
        'tenant' => $client->tenants ?? null,
        'prospect' => $client->prospects ?? null,
        'vendor' => $client->vendors ?? null,
    ];
    if ($includeContacts) {
        $resources['contact'] = $client->contacts ?? null;
    }

    $matches = [];
    foreach ($resources as $entity => $resource) {
        if (!$resource || !method_exists($resource, 'list')) {
            continue;
        }
        foreach ((array) $resource->list() as $record) {
            if (contains_email($record, $target)) {
                $matches[] = match_record($entity, $record);
            }
        }
    }

    $matches = dedupe_matches($matches);
    return ['sender_email' => $email, 'total_matches' => count($matches), 'matches' => $matches];
}

function contains_email(mixed $value, string $target): bool
{
    $value = as_array($value);
    if (is_array($value)) {
        foreach ($value as $child) {
            if (contains_email($child, $target)) {
                return true;
            }
        }
        return false;
    }
    return is_string($value) && strtolower($value) === $target;
}

function as_array(mixed $value): mixed
{
    return $value instanceof RMBaseModel ? $value->toArray() : (is_object($value) ? get_object_vars($value) : $value);
}

/** @return array<string,mixed> */
function match_record(string $entity, mixed $record): array
{
    $row = as_array($record);
    $identity = [];
    foreach (['OwnerID', 'TenantID', 'ProspectID', 'VendorID', 'ContactID', 'ID', 'Id', 'Name', 'Email'] as $field) {
        if (is_array($row) && array_key_exists($field, $row)) {
            $identity[$field] = $row[$field];
        }
    }
    return ['entity' => $entity, 'identity' => $identity, 'record' => $row];
}

/** @param list<array<string,mixed>> $matches @return list<array<string,mixed>> */
function dedupe_matches(array $matches): array
{
    $seen = [];
    $result = [];
    foreach ($matches as $match) {
        $identity = $match['identity'] ?? [];
        $key = ($match['entity'] ?? '') . ':' . json_encode($identity ?: ($match['record'] ?? null));
        if (!isset($seen[$key])) {
            $seen[$key] = true;
            $result[] = $match;
        }
    }
    return $result;
}
