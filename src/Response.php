<?php

declare(strict_types=1);

namespace RentManager;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $statusCode,
        public readonly string $body = '',
        public readonly array $headers = [],
        public readonly ?string $method = null,
        public readonly ?string $url = null,
        public readonly string $reasonPhrase = '',
    ) {
    }

    public function isError(): bool
    {
        return $this->statusCode >= 400;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }
        return null;
    }
}
