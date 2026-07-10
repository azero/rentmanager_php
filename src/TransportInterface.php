<?php

declare(strict_types=1);

namespace RentManager;

interface TransportInterface
{
    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|null $params
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $params = null,
        mixed $json = null,
        mixed $data = null,
        mixed $files = null,
        float $timeout = 15.0,
    ): Response;
}
