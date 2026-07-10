<?php

declare(strict_types=1);

namespace RentManager;

use function RentManager\redact_sensitive_url;

class ErrorModel
{
    public function __construct(
        public readonly ?string $developerMessage = null,
        public readonly ?string $userMessage = null,
        public readonly ?string $message = null,
        public readonly mixed $details = null,
    ) {
    }
}

class RentManagerAPIError extends \RuntimeException
{
    /** @param array<string,string> $responseHeaders */
    public function __construct(
        string $message,
        public readonly ?int $statusCode = null,
        public readonly ?string $method = null,
        public readonly ?string $url = null,
        public readonly mixed $details = null,
        public readonly ?string $developerMessage = null,
        public readonly ?string $userMessage = null,
        public readonly array $responseHeaders = [],
    ) {
        parent::__construct($message, $statusCode ?? 0);
    }
}

class RentManagerBadRequestError extends RentManagerAPIError {}
class RentManagerAuthError extends RentManagerAPIError {}
class RentManagerPermissionError extends RentManagerAPIError {}
class RentManagerNotFoundError extends RentManagerAPIError {}
class RentManagerConflictError extends RentManagerAPIError {}
class RentManagerPreconditionError extends RentManagerAPIError {}
class RentManagerRateLimitError extends RentManagerAPIError {}
class RentManagerServerError extends RentManagerAPIError {}
class RentManagerTransportError extends RentManagerAPIError {}

final class ErrorFactory
{
    public static function parseErrorModel(Response $response): ErrorModel
    {
        $body = trim($response->body);
        $payload = $body === '' ? null : json_decode($body, true);
        if (!is_array($payload)) {
            return new ErrorModel(message: $body !== '' ? $body : ($response->reasonPhrase ?: null));
        }

        $nested = isset($payload['error']) && is_array($payload['error']) ? $payload['error'] : $payload;
        return new ErrorModel(
            developerMessage: $nested['DeveloperMessage'] ?? $nested['developerMessage'] ?? null,
            userMessage: $nested['UserMessage'] ?? $nested['userMessage'] ?? null,
            message: $nested['Message'] ?? $nested['message'] ?? $nested['error'] ?? null,
            details: $payload,
        );
    }

    public static function fromResponse(Response $response): RentManagerAPIError
    {
        $model = self::parseErrorModel($response);
        $class = match ($response->statusCode) {
            400 => RentManagerBadRequestError::class,
            401 => RentManagerAuthError::class,
            403 => RentManagerPermissionError::class,
            404 => RentManagerNotFoundError::class,
            409 => RentManagerConflictError::class,
            412 => RentManagerPreconditionError::class,
            429 => RentManagerRateLimitError::class,
            default => $response->statusCode >= 500 ? RentManagerServerError::class : RentManagerAPIError::class,
        };
        $message = $model->developerMessage
            ?? $model->userMessage
            ?? $model->message
            ?? $response->reasonPhrase
            ?: 'Rent Manager API request failed with status ' . $response->statusCode;

        return new $class(
            $message,
            statusCode: $response->statusCode,
            method: $response->method,
            url: redact_sensitive_url($response->url),
            details: $model->details,
            developerMessage: $model->developerMessage,
            userMessage: $model->userMessage,
            responseHeaders: $response->headers,
        );
    }
}
