<?php

declare(strict_types=1);

require_once __DIR__ . '/../autoload.php';

use RentManager\EndpointRegistry;
use RentManager\FileTokenStore;
use RentManager\InMemoryTokenStore;
use RentManager\QueryParams;
use RentManager\Resource;
use RentManager\Response;
use RentManager\RentManagerClient;
use RentManager\RentManagerAPIError;
use RentManager\RentManagerNotFoundError;
use RentManager\RentManagerTransportError;
use RentManager\RQL;
use RentManager\TransportInterface;
use function RentManager\parse_link_header;
use function RentManager\redact_sensitive_url;
use function RentManager\total_results_from_headers;
use function RentManager\Workflows\lookup_by_email;
use function RentManager\Workflows\get_service_ticket_details;
use function RentManager\Workflows\create_private_directory;
use function RentManager\Workflows\write_private_json;

final class MockTransport implements TransportInterface
{
    /** @var list<array<string,mixed>> */
    public array $requests = [];
    /** @param list<Response|\Throwable|callable> $responses */
    public function __construct(private array $responses)
    {
    }

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
        $this->requests[] = compact('method', 'url', 'headers', 'params', 'json', 'data', 'files', 'timeout');
        $next = array_shift($this->responses);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (is_callable($next)) {
            $next = $next($this->requests[array_key_last($this->requests)]);
        }
        if (!$next instanceof Response) {
            throw new RuntimeException('Mock response queue exhausted.');
        }
        return new Response($next->statusCode, $next->body, $next->headers, $method, $url, $next->reasonPhrase);
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assert_throws(string $class, callable $fn, string $message): void
{
    try {
        $fn();
    } catch (Throwable $error) {
        assert_true($error instanceof $class, $message . ' threw ' . $error::class);
        return;
    }
    throw new RuntimeException($message . ' did not throw.');
}

function test_query_and_pagination_helpers(): void
{
    assert_same('IsActive,eq,true', RQL::eq('IsActive', true), 'RQL boolean formatting');
    assert_same('TenantID,in,(1,2)', RQL::in('TenantID', [1, 2]), 'RQL list formatting');
    assert_same(
        ['fields' => 'A,B', 'embed' => 'X,Y', 'filters' => 'A,eq,1;B,ct,x', 'pagenumber' => 2, 'pagesize' => 50, 'nocontent' => 'false', 'orderby' => 'Name', 'SaveOptions' => 'ReturnEntity,true', 'custom' => 'ok'],
        QueryParams::fromKwargs(['fields' => ['A', 'B'], 'embed' => 'X', 'embeds' => ['Y'], 'filter' => 'A,eq,1', 'filters' => ['B,ct,x'], 'page_number' => 2, 'page_size' => 50, 'no_content' => false, 'order_by' => 'Name', 'save_options' => ['ReturnEntity' => true], 'custom' => 'ok']),
        'Query params map to WAPI names',
    );
    assert_same(['nocontent' => 'false'], QueryParams::fromKwargs(['no_content' => 'false']), 'String false maps to false boolean query value');
    assert_same(['next' => '/p2', 'last' => '/p9'], parse_link_header('</p2>; rel="next", </p9>; rel="last"'), 'Link header parsing');
    assert_same(42, total_results_from_headers(['x-total-results' => '42']), 'Total results header parsing');
    assert_same('https://example.test/path?token=%5BREDACTED%5D&ok=1', redact_sensitive_url('https://example.test/path?token=secret-token&ok=1'), 'Sensitive URL query values are redacted');
}

function test_auth_reauth_and_resource_calls(): void
{
    $transport = new MockTransport([
        new Response(200, '{"Token":"t1"}'),
        new Response(401, '{"DeveloperMessage":"expired"}'),
        new Response(200, '{"Token":"t2"}'),
        new Response(200, '[{"TenantID":7,"Name":"A","Unknown":"kept"}]'),
    ]);
    $client = new RentManagerClient('corp', 'user', 'pass', locationId: 3, maxRetries: 0, retryBackoffSeconds: 0, tokenStore: new InMemoryTokenStore(), transport: $transport);
    $rows = $client->tenants->list(['fields' => ['TenantID', 'Name']]);
    assert_same('/Authentication/AuthorizeUser', parse_url($transport->requests[0]['url'], PHP_URL_PATH), 'Auth endpoint');
    assert_same(['Username' => 'user', 'Password' => 'pass', 'LocationID' => 3], $transport->requests[0]['json'], 'Auth payload');
    assert_same('t1', $transport->requests[1]['headers']['X-RM12Api-ApiToken'], 'Token header sent');
    assert_same('t2', $transport->requests[3]['headers']['X-RM12Api-ApiToken'], 'Token refreshed after 401');
    assert_same(7, $rows[0]->TenantID, 'Resource coerces model');
    assert_same('kept', $rows[0]->Unknown, 'Model preserves unknown fields');

    $clientA = new RentManagerClient('corp-a', 'user-a', 'pass-a', maxRetries: 0, transport: new MockTransport([new Response(200, '{"Token":"a"}')]));
    $clientBTransport = new MockTransport([new Response(200, '{"Token":"b"}')]);
    $clientB = new RentManagerClient('corp-b', 'user-b', 'pass-b', maxRetries: 0, transport: $clientBTransport);
    assert_same('a', $clientA->authenticate(), 'First default client authenticates');
    assert_same('b', $clientB->authenticate(), 'Second default client does not reuse first token');
    assert_same('user-b', $clientBTransport->requests[0]['json']['Username'], 'Second default client uses own credentials');
}

function test_retry_error_and_token_store(): void
{
    $transport = new MockTransport([
        new RentManagerTransportError('network'),
        new Response(200, '"cached-token"'),
    ]);
    $client = new RentManagerClient('corp', 'user', 'pass', maxRetries: 1, retryBackoffSeconds: 0, tokenStore: new InMemoryTokenStore(), transport: $transport);
    assert_same('cached-token', $client->authenticate(force: true), 'Transport retry succeeds');

    $path = sys_get_temp_dir() . '/rm-token-' . uniqid('', true) . '.json';
    $store = new FileTokenStore($path);
    assert_same(null, $store->load(), 'Missing token file returns null');
    $store->save('abc');
    assert_same('abc', $store->load(), 'File token round trip');
    $store->clear();
    assert_same(null, $store->load(), 'Token clear is idempotent');
    assert_throws(RentManagerTransportError::class, static fn () => (new FileTokenStore(sys_get_temp_dir()))->save('abc'), 'Token store write failure is reported');

    $transport = new MockTransport([new Response(404, '{"error":{"message":"missing"}}', [], null, null, 'Not Found')]);
    $client = new RentManagerClient('corp', 'u', 'p', maxRetries: 0, tokenStore: new InMemoryTokenStore('t'), transport: $transport);
    try {
        $client->get('Missing');
        throw new RuntimeException('Expected not found error.');
    } catch (RentManagerNotFoundError $error) {
        assert_same('missing', $error->getMessage(), 'Nested error message parsed');
    }

    $transport = new MockTransport([new Response(200, '{}')]);
    $client = new RentManagerClient('corp', 'u', 'p', maxRetries: 0, tokenStore: new InMemoryTokenStore('t'), transport: $transport);
    $client->tenants->get('tenant/with space');
    assert_same('/Tenants/tenant%2Fwith%20space', parse_url($transport->requests[0]['url'], PHP_URL_PATH), 'String path segments are encoded');
    $transport = new MockTransport([new Response(200, '[]')]);
    $client = new RentManagerClient('corp', 'u', 'p', maxRetries: 0, tokenStore: new InMemoryTokenStore('t'), transport: $transport);
    $client->serviceManager->issues->lineItems('issue/with space');
    assert_same('/ServiceManagerIssues/issue%2Fwith%20space/LineItems', parse_url($transport->requests[0]['url'], PHP_URL_PATH), 'Child resource path segments are encoded');
    assert_throws(\InvalidArgumentException::class, static fn () => $client->tenants->delete(), 'Delete requires an id or ids');
    $handle = fopen('php://temp', 'rb');
    assert_throws(RentManagerAPIError::class, static fn () => RentManagerClient::jsonDumps($handle), 'JSON encoding failure is reported');
    if (is_resource($handle)) {
        fclose($handle);
    }
}

function test_pagination_registry_generated_resources(): void
{
    $transport = new MockTransport([
        new Response(200, '[{"A":1}]', ['X-Total-Results' => '2', 'Link' => '</next>; rel="next"']),
        new Response(200, '[{"A":2}]'),
    ]);
    $client = new RentManagerClient('corp', 'u', 'p', maxRetries: 0, tokenStore: new InMemoryTokenStore('t'), transport: $transport);
    $pages = iterator_to_array($client->iterPages('Things', pageSize: 1, maxPages: 2));
    assert_same(2, count($pages), 'iterPages follows next link');
    assert_same(2, $pages[0]->totalResults, 'Page total results');
    assert_same(['pagenumber' => 1, 'pagesize' => 1], $transport->requests[0]['params'], 'Pagination params');

    $coverage = EndpointRegistry::default()->coverageReport();
    assert_true(count($coverage) > 1500, 'Registry includes generated endpoint catalog');
    assert_true($client->resource('account_group_master_payments') instanceof Resource, 'Generated resource attached');
}

function test_workflows(): void
{
    $ownerResource = new class {
        public function list(): array { return [['OwnerID' => 1, 'Email' => 'A@EXAMPLE.COM']]; }
    };
    $emptyResource = new class {
        public function list(): array { return []; }
    };
    $client = (object) ['owners' => $ownerResource, 'tenants' => $emptyResource, 'prospects' => $emptyResource, 'vendors' => $emptyResource, 'contacts' => $emptyResource];
    $result = lookup_by_email($client, 'a@example.com');
    assert_same(1, $result['total_matches'], 'Email lookup recursively matches case-insensitively');

    $issues = new class {
        public function get($id, array $query = []): array { return ['ServiceManagerIssueID' => $id, 'LineItems' => [['Cost' => 2, 'Price' => 3]]]; }
        public function history($id): array { return []; }
        public function properties($id): array { return []; }
        public function lineItems($id): array { return [['Cost' => 2, 'Price' => 3]]; }
        public function attachments($id): array { return []; }
        public function files($id): array { return []; }
        public function documents($id): array { return []; }
        public function userDefinedValues($id): array { return []; }
        public function userDefinedFields($id): array { return []; }
    };
    $client = (object) ['serviceManager' => (object) ['issues' => $issues]];
    $details = get_service_ticket_details($client, 5);
    assert_same(1, $details['billing_summary']['row_count'], 'Service ticket billing summary');

    $exportDir = sys_get_temp_dir() . '/rm-export-' . uniqid('', true);
    create_private_directory($exportDir);
    $exportFile = $exportDir . '/details.json';
    write_private_json($exportFile, ['ticket_id' => 5]);
    assert_same("{\n    \"ticket_id\": 5\n}\n", str_replace("\r\n", "\n", (string) file_get_contents($exportFile)), 'Service ticket JSON export');
    unlink($exportFile);
    rmdir($exportDir);
}

$tests = [
    'query and pagination helpers' => 'test_query_and_pagination_helpers',
    'auth reauth and resources' => 'test_auth_reauth_and_resource_calls',
    'retry errors and token store' => 'test_retry_error_and_token_store',
    'pagination registry generated resources' => 'test_pagination_registry_generated_resources',
    'workflows' => 'test_workflows',
];

foreach ($tests as $name => $test) {
    $test();
    echo "[PASS] {$name}\n";
}

echo "All tests passed.\n";
