<?php

declare(strict_types=1);

namespace RentManager;

final class Catalog
{
    /** @var array<string,mixed>|null */
    private static ?array $data = null;

    /** @return array<string,mixed> */
    public static function data(): array
    {
        if (self::$data === null) {
            $json = file_get_contents(__DIR__ . '/Data/catalog.json');
            if ($json === false) {
                throw new RentManagerTransportError('Unable to read generated Rent Manager catalog.');
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                throw new RentManagerTransportError('Generated Rent Manager catalog is invalid JSON.');
            }
            self::$data = $decoded;
        }
        return self::$data;
    }

    /** @return list<array<string,mixed>> */
    public static function resourceSpecs(): array
    {
        return self::data()['resource_specs'] ?? [];
    }

    /** @return array<string,list<string>> */
    public static function modelFields(): array
    {
        return self::data()['model_fields'] ?? [];
    }

    /** @return list<array<string,string|null>> */
    public static function endpoints(): array
    {
        return self::data()['endpoints'] ?? [];
    }
}
