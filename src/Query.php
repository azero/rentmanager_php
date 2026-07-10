<?php

declare(strict_types=1);

namespace RentManager;

final class RQL
{
    public const OPERATORS = ['eq', 'ne', 'in', 'ni', 'ct', 'sw', 'ew', 'lt', 'le', 'gt', 'ge', 'bt', 'gtn', 'gen', 'ltn', 'len', 'hv'];

    public static function filter(string $field, string $operator, mixed $value = ''): string
    {
        if ($field === '') {
            throw new \InvalidArgumentException('RQL field must be a non-empty string.');
        }
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new \InvalidArgumentException("Unsupported RQL operator '{$operator}'.");
        }
        if (is_array($value)) {
            $formatted = '(' . implode(',', array_map([self::class, 'formatValue'], $value)) . ')';
        } else {
            $formatted = self::formatValue($value);
        }
        return "{$field},{$operator},{$formatted}";
    }

    public static function eq(string $field, mixed $value): string { return self::filter($field, 'eq', $value); }
    public static function ne(string $field, mixed $value): string { return self::filter($field, 'ne', $value); }
    public static function in(string $field, iterable $value): string { return self::filter($field, 'in', is_array($value) ? $value : iterator_to_array($value)); }
    public static function ni(string $field, iterable $value): string { return self::filter($field, 'ni', is_array($value) ? $value : iterator_to_array($value)); }
    public static function ct(string $field, mixed $value): string { return self::filter($field, 'ct', $value); }
    public static function sw(string $field, mixed $value): string { return self::filter($field, 'sw', $value); }
    public static function ew(string $field, mixed $value): string { return self::filter($field, 'ew', $value); }
    public static function lt(string $field, mixed $value): string { return self::filter($field, 'lt', $value); }
    public static function le(string $field, mixed $value): string { return self::filter($field, 'le', $value); }
    public static function gt(string $field, mixed $value): string { return self::filter($field, 'gt', $value); }
    public static function ge(string $field, mixed $value): string { return self::filter($field, 'ge', $value); }
    public static function bt(string $field, mixed $start, mixed $end): string { return self::filter($field, 'bt', [$start, $end]); }
    public static function gtn(string $field, mixed $value): string { return self::filter($field, 'gtn', $value); }
    public static function gen(string $field, mixed $value): string { return self::filter($field, 'gen', $value); }
    public static function ltn(string $field, mixed $value): string { return self::filter($field, 'ltn', $value); }
    public static function len(string $field, mixed $value): string { return self::filter($field, 'len', $value); }
    public static function hv(string $field, mixed $value = ''): string { return self::filter($field, 'hv', $value); }

    private static function formatValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('c');
        }
        if ($value === null) {
            return 'null';
        }
        return (string) $value;
    }
}

final class QueryParams
{
    /** @param array<string,mixed> $extra */
    public function __construct(
        public mixed $fields = null,
        public mixed $embed = null,
        public mixed $embeds = null,
        public mixed $filter = null,
        public mixed $filters = null,
        public ?int $pageNumber = null,
        public ?int $pageSize = null,
        public ?bool $noContent = null,
        public mixed $orderBy = null,
        public mixed $saveOptions = null,
        public array $extra = [],
    ) {
    }

    /** @return array<string,mixed> */
    public function toParams(): array
    {
        $params = [];
        if ($fields = self::asCsv($this->fields)) {
            $params['fields'] = $fields;
        }
        $embeds = array_values(array_filter([self::asCsv($this->embed), self::asCsv($this->embeds)]));
        if ($embeds) {
            $params['embed'] = implode(',', $embeds);
        }
        $filters = [...self::asFilterList($this->filter), ...self::asFilterList($this->filters)];
        if ($filters) {
            $params['filters'] = implode(';', $filters);
        }
        if ($this->pageNumber !== null) {
            $params['pagenumber'] = $this->pageNumber;
        }
        if ($this->pageSize !== null) {
            $params['pagesize'] = $this->pageSize;
        }
        if ($this->noContent !== null) {
            $params['nocontent'] = $this->noContent ? 'true' : 'false';
        }
        if ($orderBy = self::asCsv($this->orderBy)) {
            $params['orderby'] = $orderBy;
        }
        if ($saveOptions = $this->formatSaveOptions()) {
            $params['SaveOptions'] = $saveOptions;
        }
        return array_merge($params, $this->extra);
    }

    public static function fromKwargs(array $kwargs): array
    {
        $extra = $kwargs;
        $take = static function (array &$source, string ...$names): mixed {
            foreach ($names as $name) {
                if (array_key_exists($name, $source)) {
                    $value = $source[$name];
                    unset($source[$name]);
                    return $value;
                }
            }
            return null;
        };
        return (new self(
            fields: $take($extra, 'fields'),
            embed: $take($extra, 'embed'),
            embeds: $take($extra, 'embeds'),
            filter: $take($extra, 'filter'),
            filters: $take($extra, 'filters'),
            pageNumber: ($v = $take($extra, 'page_number', 'pagenumber')) === null ? null : (int) $v,
            pageSize: ($v = $take($extra, 'page_size', 'pagesize')) === null ? null : (int) $v,
            noContent: ($v = $take($extra, 'no_content', 'nocontent')) === null ? null : self::asBool($v),
            orderBy: $take($extra, 'order_by', 'orderby'),
            saveOptions: $take($extra, 'save_options', 'SaveOptions'),
            extra: $extra,
        ))->toParams();
    }

    private static function asCsv(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_array($value)) {
            return implode(',', array_map('strval', $value));
        }
        return (string) $value;
    }

    private static function asBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
        }
        return (bool) $value;
    }

    /** @return list<string> */
    private static function asFilterList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (is_array($value)) {
            return array_map('strval', $value);
        }
        return [(string) $value];
    }

    private function formatSaveOptions(): ?string
    {
        if ($this->saveOptions === null) {
            return null;
        }
        if (is_string($this->saveOptions)) {
            return $this->saveOptions;
        }
        if (is_array($this->saveOptions)) {
            $parts = [];
            foreach ($this->saveOptions as $key => $value) {
                $parts[] = is_string($key) ? $key . ',' . ($value ? 'true' : 'false') : (string) $value;
            }
            return implode(';', $parts);
        }
        return (string) $this->saveOptions;
    }
}
