<?php

declare(strict_types=1);

namespace RentManager;

function redact_sensitive_url(?string $url): ?string
{
    if ($url === null || $url === '') {
        return $url;
    }

    $parts = parse_url($url);
    if ($parts === false || !isset($parts['query'])) {
        return $url;
    }

    parse_str($parts['query'], $query);
    $sensitive = ['token', 'api_token', 'apitoken', 'access_token', 'refresh_token', 'password', 'secret'];
    foreach ($query as $key => $_value) {
        if (in_array(strtolower((string) $key), $sensitive, true)) {
            $query[$key] = '[REDACTED]';
        }
    }

    $rebuilt = '';
    if (isset($parts['scheme'])) {
        $rebuilt .= $parts['scheme'] . '://';
    }
    if (isset($parts['user'])) {
        $rebuilt .= $parts['user'];
        if (isset($parts['pass'])) {
            $rebuilt .= ':[REDACTED]';
        }
        $rebuilt .= '@';
    }
    if (isset($parts['host'])) {
        $rebuilt .= $parts['host'];
    }
    if (isset($parts['port'])) {
        $rebuilt .= ':' . $parts['port'];
    }
    $rebuilt .= $parts['path'] ?? '';
    $rebuilt .= '?' . http_build_query($query);
    if (isset($parts['fragment'])) {
        $rebuilt .= '#' . $parts['fragment'];
    }

    return $rebuilt;
}

function path_segment(int|string $value): string
{
    return rawurlencode((string) $value);
}
