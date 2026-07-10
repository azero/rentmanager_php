<?php

declare(strict_types=1);

namespace RentManager;

final class EndpointSpec
{
    public const VALID_CONFIDENCE_VALUES = ['documented', 'prior_art', 'field_discovered', 'live_help_catalog'];

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?string $model,
        public readonly string $confidence,
        public readonly string $source,
    ) {
        if (!in_array($confidence, self::VALID_CONFIDENCE_VALUES, true)) {
            throw new \InvalidArgumentException('EndpointSpec confidence must be one of: ' . implode(', ', self::VALID_CONFIDENCE_VALUES));
        }
    }

    /** @return array<string,string|null> */
    public function toArray(): array
    {
        return [
            'method' => $this->method,
            'path' => $this->path,
            'model' => $this->model,
            'confidence' => $this->confidence,
            'source' => $this->source,
        ];
    }
}

final class EndpointRegistry implements \IteratorAggregate
{
    /** @param list<EndpointSpec> $endpoints */
    public function __construct(private readonly array $endpoints)
    {
    }

    public static function default(): self
    {
        return new self(array_map(
            static fn (array $row): EndpointSpec => new EndpointSpec(
                (string) $row['method'],
                (string) $row['path'],
                isset($row['model']) ? (string) $row['model'] : null,
                (string) $row['confidence'],
                (string) $row['source'],
            ),
            Catalog::endpoints(),
        ));
    }

    /** @return \ArrayIterator<int,EndpointSpec> */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->endpoints);
    }

    /** @return list<array<string,string|null>> */
    public function coverageReport(): array
    {
        return array_map(static fn (EndpointSpec $endpoint): array => $endpoint->toArray(), $this->endpoints);
    }
}
