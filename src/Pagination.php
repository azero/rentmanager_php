<?php

declare(strict_types=1);

namespace RentManager;

final class Page
{
    /**
     * @param list<mixed> $data
     * @param array<string,string> $headers
     * @param array<string,string> $links
     */
    public function __construct(
        public readonly array $data,
        public readonly int $statusCode,
        public readonly array $headers,
        public readonly int $pageNumber,
        public readonly int $pageSize,
        public readonly ?int $totalResults = null,
        public readonly array $links = [],
    ) {
    }
}

/** @return array<string,string> */
function parse_link_header(?string $value): array
{
    $links = [];
    if (!$value) {
        return $links;
    }
    foreach (explode(',', $value) as $part) {
        $section = trim($part);
        if ($section === '' || !str_contains($section, ';')) {
            continue;
        }
        $pieces = explode(';', $section);
        $url = trim((string) array_shift($pieces));
        if (str_starts_with($url, '<') && str_ends_with($url, '>')) {
            $url = substr($url, 1, -1);
        }
        $rel = null;
        foreach ($pieces as $param) {
            [$key, $raw] = array_pad(explode('=', trim($param), 2), 2, '');
            if (strtolower($key) === 'rel') {
                $rel = trim($raw, '"');
            }
        }
        if ($rel) {
            $links[$rel] = $url;
        }
    }
    return $links;
}

/** @param array<string,string> $headers */
function total_results_from_headers(array $headers): ?int
{
    foreach ($headers as $key => $value) {
        if (strcasecmp($key, 'X-Total-Results') === 0 && is_numeric($value)) {
            return (int) $value;
        }
    }
    return null;
}
