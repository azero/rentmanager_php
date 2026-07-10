<?php

declare(strict_types=1);

namespace RentManager;

use function RentManager\parse_link_header;
use function RentManager\total_results_from_headers;

class RentManagerClient
{
    private const RETRYABLE_STATUS_CODES = [408, 425, 429, 500, 502, 503, 504];

    public readonly string $baseUrl;
    private ?string $token = null;
    private TokenStore $effectiveTokenStore;

    public Resource $contacts;
    public Resource $tasks;
    public OwnersResource $owners;
    public TenantsResource $tenants;
    public ProspectsResource $prospects;
    public VendorsResource $vendors;
    public PropertiesResource $properties;
    public UnitsResource $units;
    public Resource $leases;
    public UsersResource $users;
    public Resource $locations;
    public Resource $propertyGroups;
    public Resource $amenities;
    public Resource $glAccounts;
    public Resource $chargeTypes;
    public Resource $unitTypes;
    public RecurringChargesResource $recurringCharges;
    public ReportWriterReportsResource $reportWriterReports;
    public ServiceManagerNamespace $serviceManager;

    /** @var array<string,Resource> */
    private array $resources = [];

    public function __construct(
        public readonly string $corpId,
        public readonly string $username,
        public readonly string $password,
        public ?int $locationId = null,
        ?string $baseUrl = null,
        private readonly float $timeout = 15.0,
        private readonly int $maxRetries = 2,
        private readonly float $retryBackoffSeconds = 0.4,
        private readonly ?TokenStore $tokenStore = null,
        private readonly ?TransportInterface $transport = null,
    ) {
        $this->baseUrl = rtrim($baseUrl ?? "https://{$corpId}.api.rentmanager.com", '/');
        $this->effectiveTokenStore = $tokenStore ?? new InMemoryTokenStore();
        $this->attachResources();
    }

    public static function jsonDumps(mixed $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new RentManagerTransportError('Unable to encode JSON payload: ' . $error->getMessage());
        }
    }

    public function authenticate(bool $force = false): string
    {
        if (!$force) {
            $cached = $this->token ?: $this->store()->load();
            if ($cached) {
                return $this->token = $cached;
            }
        }

        $response = $this->requestRaw('POST', 'Authentication/AuthorizeUser', auth: false, json: $this->authPayload());
        $payload = $this->decodeResponse($response);
        $token = is_array($payload) ? ($payload['Token'] ?? null) : $payload;
        if (!is_string($token) || trim($token) === '') {
            throw new RentManagerTransportError('Authentication response did not contain an API token.');
        }
        $this->setToken($token);
        return $token;
    }

    public function authorizeToken(string $token): mixed
    {
        return $this->request('POST', 'Authentication/AuthorizeToken', auth: false, params: ['token' => $token]);
    }

    public function changeLocation(int $locationId): mixed
    {
        $result = $this->post('Authentication/ChangeLocation', params: ['locationID' => $locationId]);
        $this->locationId = $locationId;
        return $result;
    }

    /** @param array<string,mixed>|null $params @param array<string,mixed> $query @param array<string,string>|null $headers */
    public function request(
        string $method,
        string $endpoint,
        ?array $params = null,
        mixed $json = null,
        mixed $data = null,
        mixed $files = null,
        bool $auth = true,
        ?array $headers = null,
        array $query = [],
    ): mixed {
        $response = $this->requestRaw(
            $method,
            $endpoint,
            auth: $auth,
            params: $this->mergeParams($params, $query),
            json: $json,
            data: $data,
            files: $files,
            headers: $headers,
        );
        return $this->decodeResponse($response);
    }

    /** @param array<string,mixed>|null $params @param array<string,mixed> $query */
    public function get(string $endpoint, ?array $params = null, array $query = []): mixed
    {
        return $this->request('GET', $endpoint, params: $params, query: $query);
    }

    /** @param array<string,mixed>|null $params @param array<string,mixed> $query */
    public function downloadBytes(string $endpoint, ?array $params = null, array $query = []): string
    {
        $response = $this->requestRaw('GET', $endpoint, params: $this->mergeParams($params, $query));
        return $response->body;
    }

    /** @param array<string,mixed>|null $params @param array<string,mixed> $query */
    public function post(string $endpoint, mixed $json = null, ?array $params = null, array $query = []): mixed
    {
        return $this->request('POST', $endpoint, params: $params, json: $json, query: $query);
    }

    /** @param array<string,mixed>|null $params @param array<string,mixed> $query */
    public function delete(string $endpoint, mixed $json = null, ?array $params = null, array $query = []): mixed
    {
        return $this->request('DELETE', $endpoint, params: $params, json: $json, query: $query);
    }

    /** @param array<string,mixed> $query */
    public function action(string $endpoint, mixed $payload = null, array $query = []): mixed
    {
        return $this->post($endpoint, json: $payload, query: $query);
    }

    /** @param array<string,mixed>|null $params @param array<string,mixed>|null $data */
    public function postMultipart(string $endpoint, mixed $files, ?array $data = null, ?array $params = null): mixed
    {
        return $this->request('POST', $endpoint, params: $params, data: $data, files: $files);
    }

    /** @param array<string,mixed>|null $params @param array<string,mixed> $query */
    public function paginate(
        string $endpoint,
        int $pageNumber = 1,
        int $pageSize = 1000,
        ?array $params = null,
        array $query = [],
    ): Page {
        $merged = $this->mergeParams($params, $query);
        $merged = array_merge($merged, (new QueryParams(pageNumber: $pageNumber, pageSize: $pageSize))->toParams());
        $response = $this->requestRaw('GET', $endpoint, params: $merged);
        return $this->pageFromResponse($response, $pageNumber, $pageSize);
    }

    /** @param array<string,mixed> $query @return \Generator<int,Page> */
    public function iterPages(string $endpoint, int $pageSize = 1000, int $startPage = 1, ?int $maxPages = null, array $query = []): \Generator
    {
        $pageNumber = $startPage;
        $yielded = 0;
        while (true) {
            $page = $this->paginate($endpoint, pageNumber: $pageNumber, pageSize: $pageSize, query: $query);
            yield $page;
            $yielded++;
            if ($maxPages !== null && $yielded >= $maxPages) {
                break;
            }
            if (!array_key_exists('next', $page->links)) {
                break;
            }
            $pageNumber++;
        }
    }

    public function resource(string $clientAttr): ?Resource
    {
        return $this->resources[$clientAttr] ?? null;
    }

    public function __get(string $name): mixed
    {
        if (isset($this->resources[$name])) {
            return $this->resources[$name];
        }
        trigger_error('Undefined property: ' . static::class . '::$' . $name, E_USER_NOTICE);
        return null;
    }

    /** @return array<string,mixed> */
    private function authPayload(): array
    {
        $payload = ['Username' => $this->username, 'Password' => $this->password];
        if ($this->locationId !== null) {
            $payload['LocationID'] = $this->locationId;
        }
        return $payload;
    }

    private function setToken(string $token): void
    {
        $this->token = $token;
        $this->store()->save($token);
    }

    private function clearToken(): void
    {
        $this->token = null;
        $this->store()->clear();
    }

    private function ensureToken(): void
    {
        if ($this->token) {
            return;
        }
        $cached = $this->store()->load();
        if ($cached) {
            $this->token = $cached;
            return;
        }
        $this->authenticate(force: true);
    }

    /** @param array<string,mixed>|null $params @param array<string,mixed> $query @return array<string,mixed> */
    private function mergeParams(?array $params, array $query): array
    {
        return array_merge($params ?? [], QueryParams::fromKwargs($query));
    }

    /** @param array<string,string>|null $headers @return array<string,string> */
    private function headers(?array $headers, bool $auth): array
    {
        $merged = array_merge(['Accept' => 'application/json', 'Content-Type' => 'application/json'], $headers ?? []);
        if ($auth && $this->token) {
            $merged['X-RM12Api-ApiToken'] = $this->token;
        }
        return $merged;
    }

    /** @param array<string,mixed>|null $params @param array<string,string>|null $headers */
    public function requestRaw(
        string $method,
        string $endpoint,
        bool $auth = true,
        bool $retryAuth = true,
        ?array $params = null,
        mixed $json = null,
        mixed $data = null,
        mixed $files = null,
        ?array $headers = null,
    ): Response {
        $attempts = max(0, $this->maxRetries) + 1;
        $lastError = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $response = $this->sendOnce($method, $endpoint, $auth, $params, $json, $data, $files, $headers);
            } catch (RentManagerTransportError $error) {
                $lastError = $error;
                if ($attempt >= $attempts) {
                    throw $error;
                }
                $this->sleep($attempt);
                continue;
            }

            if (in_array($response->statusCode, self::RETRYABLE_STATUS_CODES, true) && $attempt < $attempts) {
                $this->sleep($attempt);
                continue;
            }

            if ($response->statusCode === 401 && $auth && $retryAuth) {
                $this->clearToken();
                $this->authenticate(force: true);
                return $this->requestRaw($method, $endpoint, auth: $auth, retryAuth: false, params: $params, json: $json, data: $data, files: $files, headers: $headers);
            }

            if ($response->isError()) {
                throw ErrorFactory::fromResponse($response);
            }
            return $response;
        }

        throw $lastError ?? new RentManagerTransportError('Request failed.');
    }

    /** @param array<string,mixed>|null $params @param array<string,string>|null $headers */
    private function sendOnce(
        string $method,
        string $endpoint,
        bool $auth,
        ?array $params,
        mixed $json,
        mixed $data,
        mixed $files,
        ?array $headers,
    ): Response {
        if ($auth) {
            $this->ensureToken();
        }
        return $this->transport()->request(
            strtoupper($method),
            $this->baseUrl . '/' . trim($endpoint, '/'),
            $this->headers($headers, $auth),
            $params ?: null,
            $json,
            $data,
            $files,
            $this->timeout,
        );
    }

    private function decodeResponse(Response $response): mixed
    {
        if ($response->statusCode === 204 || $response->body === '') {
            return [];
        }
        $decoded = json_decode($response->body, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $response->body;
    }

    private function pageFromResponse(Response $response, int $pageNumber, int $pageSize): Page
    {
        $data = $this->decodeResponse($response);
        $rows = is_array($data) && array_is_list($data) ? $data : [$data];
        return new Page(
            data: $rows,
            statusCode: $response->statusCode,
            headers: $response->headers,
            pageNumber: $pageNumber,
            pageSize: $pageSize,
            totalResults: total_results_from_headers($response->headers),
            links: parse_link_header($response->header('Link')),
        );
    }

    private function store(): TokenStore
    {
        return $this->effectiveTokenStore;
    }

    private function transport(): TransportInterface
    {
        static $defaultTransport = null;
        return $this->transport ?? ($defaultTransport ??= new CurlTransport());
    }

    private function sleep(int $attempt): void
    {
        $seconds = max(0.0, $this->retryBackoffSeconds) * $attempt;
        if ($seconds > 0) {
            usleep((int) round($seconds * 1_000_000));
        }
    }

    private function attachResources(): void
    {
        $this->contacts = $this->register('contacts', new Resource($this, 'Contacts', 'Contact'));
        $this->tasks = $this->register('tasks', new Resource($this, 'Tasks', 'Task'));
        $this->owners = $this->register('owners', new OwnersResource($this, 'Owners', 'Owner'));
        $this->tenants = $this->register('tenants', new TenantsResource($this, 'Tenants', 'Tenant'));
        $this->prospects = $this->register('prospects', new ProspectsResource($this, 'Prospects', 'Prospect'));
        $this->vendors = $this->register('vendors', new VendorsResource($this, 'Vendors', 'Vendor'));
        $this->properties = $this->register('properties', new PropertiesResource($this, 'Properties', 'Property'));
        $this->units = $this->register('units', new UnitsResource($this, 'Units', 'Unit'));
        $this->leases = $this->register('leases', new Resource($this, 'Leases', 'Lease'));
        $this->users = $this->register('users', new UsersResource($this, 'Users', 'User'));
        $this->locations = $this->register('locations', new Resource($this, 'Locations', 'Location'));
        $this->propertyGroups = $this->register('property_groups', new Resource($this, 'PropertyGroups', 'PropertyGroup'));
        $this->amenities = $this->register('amenities', new Resource($this, 'Amenities', 'Amenity'));
        $this->glAccounts = $this->register('gl_accounts', new Resource($this, 'GLAccounts', 'GLAccount'));
        $this->chargeTypes = $this->register('charge_types', new Resource($this, 'ChargeTypes', 'ChargeType'));
        $this->unitTypes = $this->register('unit_types', new Resource($this, 'UnitTypes', 'UnitType'));
        $this->recurringCharges = $this->register('recurring_charges', new RecurringChargesResource($this, 'RecurringCharges', 'RecurringCharge'));
        $this->reportWriterReports = $this->register('report_writer_reports', new ReportWriterReportsResource($this, 'ReportWriterReports', 'ReportWriterReport'));
        $this->serviceManager = new ServiceManagerNamespace($this);

        foreach (Catalog::resourceSpecs() as $spec) {
            $attr = $spec['client_attr'] ?? null;
            if (!is_string($attr) || isset($this->resources[$attr])) {
                continue;
            }
            $this->resources[$attr] = new Resource($this, (string) $spec['path'], (string) $spec['model_name']);
        }
    }

    /**
     * @template T of Resource
     * @param T $resource
     * @return T
     */
    private function register(string $attr, Resource $resource): Resource
    {
        return $this->resources[$attr] = $resource;
    }
}

class AsyncRentManagerClient extends RentManagerClient
{
}
