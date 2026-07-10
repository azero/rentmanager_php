<?php

declare(strict_types=1);

namespace RentManager;

interface TokenStore
{
    public function load(): ?string;
    public function save(string $token): void;
    public function clear(): void;
}

class InMemoryTokenStore implements TokenStore
{
    public function __construct(private ?string $token = null)
    {
    }

    public function load(): ?string
    {
        return $this->token;
    }

    public function save(string $token): void
    {
        $this->token = $token;
    }

    public function clear(): void
    {
        $this->token = null;
    }
}

class FileTokenStore implements TokenStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function load(): ?string
    {
        if (!is_file($this->path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($this->path), true);
        $token = is_array($decoded) ? ($decoded['token'] ?? null) : null;
        return is_string($token) && trim($token) !== '' ? $token : null;
    }

    public function save(string $token): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new RentManagerTransportError("Unable to create token cache directory {$directory}.");
        }
        if (is_dir($directory)) {
            @chmod($directory, 0700);
        }
        $written = @file_put_contents($this->path, json_encode(['token' => $token], JSON_UNESCAPED_SLASHES), LOCK_EX);
        if ($written === false) {
            throw new RentManagerTransportError("Unable to write token cache file {$this->path}.");
        }
        @chmod($this->path, 0600);
    }

    public function clear(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}
