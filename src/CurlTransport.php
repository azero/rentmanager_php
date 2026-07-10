<?php

declare(strict_types=1);

namespace RentManager;

use function RentManager\redact_sensitive_url;

final class CurlTransport implements TransportInterface
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?array $params = null,
        mixed $json = null,
        mixed $data = null,
        mixed $files = null,
        float $timeout = 15.0,
    ): Response {
        if ($params) {
            $query = http_build_query($params);
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }

        if ($files !== null) {
            $headers = $this->withoutHeader($headers, 'Content-Type');
        } elseif ($data !== null && $json === null) {
            $headers = $this->withHeader($headers, 'Content-Type', 'application/x-www-form-urlencoded');
        }

        if (function_exists('curl_init')) {
            return $this->requestWithCurl($method, $url, $headers, $json, $data, $files, $timeout);
        }

        return $this->requestWithStream($method, $url, $headers, $json, $data, $files, $timeout);
    }

    private function requestWithCurl(
        string $method,
        string $url,
        array $headers,
        mixed $json,
        mixed $data,
        mixed $files,
        float $timeout,
    ): Response {
        $handle = \curl_init($url);
        if ($handle === false) {
            throw new RentManagerTransportError('Unable to initialize cURL.');
        }

        $responseHeaders = [];
        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) {
                    return $length;
                }
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[trim($name)] = trim($value);
                return $length;
            },
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $timeout,
        ]);

        if ($files !== null) {
            \curl_setopt($handle, CURLOPT_POSTFIELDS, $this->multipartPayload($files, is_array($data) ? $data : []));
        } elseif ($json !== null) {
            \curl_setopt($handle, CURLOPT_POSTFIELDS, RentManagerClient::jsonDumps($json));
        } elseif ($data !== null) {
            \curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data) : (string) $data);
        }

        $body = \curl_exec($handle);
        if ($body === false) {
            $message = \curl_error($handle) ?: 'cURL request failed.';
            \curl_close($handle);
            throw new RentManagerTransportError($message);
        }
        $status = (int) \curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        \curl_close($handle);

        return new Response($status, (string) $body, $responseHeaders, strtoupper($method), redact_sensitive_url($url));
    }

    private function requestWithStream(
        string $method,
        string $url,
        array $headers,
        mixed $json,
        mixed $data,
        mixed $files,
        float $timeout,
    ): Response {
        $body = null;
        if ($files !== null) {
            [$body, $contentType] = $this->multipartStreamPayload($files, is_array($data) ? $data : []);
            $headers['Content-Type'] = $contentType;
        } elseif ($json !== null) {
            $body = RentManagerClient::jsonDumps($json);
        } elseif ($data !== null) {
            $body = is_array($data) ? http_build_query($data) : (string) $data;
        }

        $headerLines = [];
        foreach ($headers as $key => $value) {
            $headerLines[] = $key . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => implode("\r\n", $headerLines),
                'content' => $body ?? '',
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            $error = error_get_last();
            throw new RentManagerTransportError($error['message'] ?? 'HTTP stream request failed.');
        }

        [$status, $responseHeaders] = $this->parseStreamResponseHeaders($http_response_header);
        return new Response($status, (string) $responseBody, $responseHeaders, strtoupper($method), redact_sensitive_url($url));
    }

    /** @param array<string,string> $headers @return array<string,string> */
    private function withoutHeader(array $headers, string $name): array
    {
        foreach (array_keys($headers) as $key) {
            if (strcasecmp((string) $key, $name) === 0) {
                unset($headers[$key]);
            }
        }
        return $headers;
    }

    /** @param array<string,string> $headers @return array<string,string> */
    private function withHeader(array $headers, string $name, string $value): array
    {
        $headers = $this->withoutHeader($headers, $name);
        $headers[$name] = $value;
        return $headers;
    }

    /** @param array<string,mixed> $data */
    private function multipartPayload(mixed $files, array $data): array
    {
        $payload = $data;
        if (is_array($files)) {
            foreach ($files as $name => $file) {
                if (is_string($file) && is_file($file)) {
                    $payload[(string) $name] = new \CURLFile($file);
                } else {
                    $payload[(string) $name] = $file;
                }
            }
        }
        return $payload;
    }

    /** @param array<string,mixed> $data @return array{0:string,1:string} */
    private function multipartStreamPayload(mixed $files, array $data): array
    {
        $boundary = 'rmphp-' . bin2hex(random_bytes(12));
        $parts = [];

        foreach ($data as $name => $value) {
            $parts[] = "--{$boundary}\r\n"
                . 'Content-Disposition: form-data; name="' . addslashes((string) $name) . "\"\r\n\r\n"
                . (string) $value . "\r\n";
        }

        if (is_array($files)) {
            foreach ($files as $name => $file) {
                $fieldName = addslashes((string) $name);
                if (is_string($file) && is_file($file)) {
                    $filename = addslashes(basename($file));
                    $contents = file_get_contents($file);
                    if ($contents === false) {
                        throw new RentManagerTransportError("Unable to read multipart file {$file}.");
                    }
                    $parts[] = "--{$boundary}\r\n"
                        . "Content-Disposition: form-data; name=\"{$fieldName}\"; filename=\"{$filename}\"\r\n"
                        . "Content-Type: application/octet-stream\r\n\r\n"
                        . $contents . "\r\n";
                    continue;
                }

                $parts[] = "--{$boundary}\r\n"
                    . "Content-Disposition: form-data; name=\"{$fieldName}\"\r\n\r\n"
                    . (is_scalar($file) || $file === null ? (string) $file : RentManagerClient::jsonDumps($file))
                    . "\r\n";
            }
        }

        $parts[] = "--{$boundary}--\r\n";
        return [implode('', $parts), 'multipart/form-data; boundary=' . $boundary];
    }

    /** @param list<string> $rawHeaders @return array{0:int,1:array<string,string>} */
    private function parseStreamResponseHeaders(array $rawHeaders): array
    {
        $status = 0;
        $headers = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) {
                $status = (int) $match[1];
                $headers = [];
                continue;
            }
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[trim($name)] = trim($value);
        }
        return [$status, $headers];
    }
}
