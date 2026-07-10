<?php

declare(strict_types=1);

namespace RentManager;

use RentManager\Models\RMBaseModel;
use function RentManager\path_segment;

class Resource
{
    public function __construct(
        protected RentManagerClient $client,
        public string $path = '',
        public ?string $modelName = null,
    ) {
    }

    /** @param array<string,mixed> $query */
    public function list(array $query = []): mixed
    {
        return $this->coerce($this->client->get($this->path, query: $query));
    }

    /** @param array<string,mixed> $query */
    public function get(int|string $itemId, array $query = []): mixed
    {
        return $this->coerce($this->client->get($this->path . '/' . path_segment($itemId), query: $query));
    }

    /** @param array<string,mixed> $query */
    public function create(mixed $payload, array $query = []): mixed
    {
        return $this->coerce($this->client->post($this->path, json: $payload, query: $query));
    }

    /** @param array<string,mixed> $query */
    public function update(int|string $itemId, mixed $payload, array $query = []): mixed
    {
        return $this->coerce($this->client->post($this->path . '/' . path_segment($itemId), json: $payload, query: $query));
    }

    /** @param list<int>|null $ids @param array<string,mixed> $query */
    public function delete(int|string|null $itemId = null, ?array $ids = null, array $query = []): mixed
    {
        if ($itemId !== null) {
            return $this->client->delete($this->path . '/' . path_segment($itemId), query: $query);
        }
        if ($ids === null || $ids === []) {
            throw new \InvalidArgumentException('Resource::delete requires an item id or a non-empty ids list.');
        }
        return $this->client->delete($this->path, json: $ids, query: $query);
    }

    /** @param array<string,mixed> $query */
    public function paginate(array $query = []): Page
    {
        $pageNumber = isset($query['page_number']) ? (int) $query['page_number'] : (isset($query['pagenumber']) ? (int) $query['pagenumber'] : 1);
        $pageSize = isset($query['page_size']) ? (int) $query['page_size'] : (isset($query['pagesize']) ? (int) $query['pagesize'] : 1000);
        unset($query['page_number'], $query['pagenumber'], $query['page_size'], $query['pagesize']);
        return $this->client->paginate($this->path, pageNumber: $pageNumber, pageSize: $pageSize, query: $query);
    }

    /** @param array<string,mixed> $query @return \Generator<int,Page> */
    public function iterPages(array $query = []): \Generator
    {
        $pageSize = isset($query['page_size']) ? (int) $query['page_size'] : (isset($query['pagesize']) ? (int) $query['pagesize'] : 1000);
        $startPage = isset($query['start_page']) ? (int) $query['start_page'] : 1;
        $maxPages = isset($query['max_pages']) ? (int) $query['max_pages'] : null;
        unset($query['page_size'], $query['pagesize'], $query['start_page'], $query['max_pages']);
        yield from $this->client->iterPages($this->path, pageSize: $pageSize, startPage: $startPage, maxPages: $maxPages, query: $query);
    }

    protected function childGet(string $path, ?string $modelName = null, array $query = []): mixed
    {
        return $this->coerce($this->client->get($path, query: $query), $modelName);
    }

    protected function childPost(string $path, mixed $payload = null, ?string $modelName = null, array $query = []): mixed
    {
        return $this->coerce($this->client->post($path, json: $payload, query: $query), $modelName);
    }

    protected function coerce(mixed $value, ?string $modelName = null): mixed
    {
        $modelName ??= $this->modelName;
        if ($modelName === null || $modelName === 'dict') {
            return $value;
        }
        $class = 'RentManager\\Models\\' . $modelName;
        if (!class_exists($class)) {
            $class = RMBaseModel::class;
        }
        if (is_array($value) && array_is_list($value)) {
            return array_map(static fn (mixed $row): mixed => is_array($row) ? new $class($row) : $row, $value);
        }
        if (is_array($value)) {
            return new $class($value);
        }
        return $value;
    }
}

class OwnersResource extends Resource
{
    public function history(int|string $ownerId, array $query = []): mixed { return $this->childGet('Owners/' . path_segment($ownerId) . '/History', 'HistoryItem', $query); }
    public function ownerChecks(int|string $ownerId, array $query = []): mixed { return $this->childGet('Owners/' . path_segment($ownerId) . '/OwnerChecks', 'OwnerCheck', $query); }
    public function payments(int|string $ownerId, array $query = []): mixed { return $this->childGet('Owners/' . path_segment($ownerId) . '/Payments', 'Payment', $query); }
    public function charges(int|string $ownerId, array $query = []): mixed { return $this->childGet('Owners/' . path_segment($ownerId) . '/Charges', 'Charge', $query); }
    public function contact(int|string $ownerId, array $query = []): mixed { return $this->childGet('Owners/' . path_segment($ownerId) . '/Contact', 'Contact', $query); }
}

class TenantsResource extends Resource
{
    public function transactions(int|string $tenantId, array $query = []): mixed { return $this->childGet('Tenants/' . path_segment($tenantId) . '/Transactions', 'Transaction', $query); }
    public function history(int|string $tenantId, array $query = []): mixed { return $this->childGet('Tenants/' . path_segment($tenantId) . '/History', 'HistoryItem', $query); }
    public function contacts(int|string $tenantId, array $query = []): mixed { return $this->childGet('Tenants/' . path_segment($tenantId) . '/Contacts', 'Contact', $query); }
    public function leases(int|string $tenantId, array $query = []): mixed { return $this->childGet('Tenants/' . path_segment($tenantId) . '/Leases', 'Lease', $query); }
    public function recurringCharges(int|string $tenantId, array $query = []): mixed { return $this->childGet('Tenants/' . path_segment($tenantId) . '/RecurringCharges', 'RecurringCharge', $query); }
    public function createRecurringCharge(int|string $tenantId, mixed $payload, array $query = []): mixed { return $this->childPost('Tenants/' . path_segment($tenantId) . '/RecurringCharges', $payload, 'RecurringCharge', $query); }
    public function userDefinedValues(int|string $tenantId, array $query = []): mixed { return $this->childGet('Tenants/' . path_segment($tenantId) . '/UserDefinedValues', 'UserDefinedValue', $query); }
    public function updateUserDefinedValues(int|string $tenantId, mixed $payload, array $query = []): mixed { return $this->childPost('Tenants/' . path_segment($tenantId) . '/UserDefinedValues', $payload, 'UserDefinedValue', $query); }
    public function userDefinedFields(int|string $tenantId, array $query = []): mixed { return $this->childGet('Tenants/' . path_segment($tenantId) . '/UserDefinedFields', 'UserDefinedField', $query); }
    public function addresses(int|string $tenantId, array $query = []): mixed { return $this->childGet('Tenants/' . path_segment($tenantId) . '/Addresses', null, $query); }
    public function uploadUserDefinedValueAttachment(int|string $tenantId, int|string $udfId, mixed $file, string $filename, array $data = []): mixed
    {
        $payload = ['UserDefinedFieldID' => $udfId, 'ParentID' => $tenantId] + $data;
        return $this->client->postMultipart('Tenants/' . path_segment($tenantId) . '/UploadUserDefinedValueAttachment', files: [
            'file' => $file,
            'udf' => RentManagerClient::jsonDumps($payload),
            'filename' => $filename,
        ]);
    }
}

class ProspectsResource extends Resource
{
    public function history(int|string $prospectId, array $query = []): mixed { return $this->childGet('Prospects/' . path_segment($prospectId) . '/History', 'HistoryItem', $query); }
    public function contacts(int|string $prospectId, array $query = []): mixed { return $this->childGet('Prospects/' . path_segment($prospectId) . '/Contacts', 'Contact', $query); }
}

class VendorsResource extends Resource
{
    public function history(int|string $vendorId, array $query = []): mixed { return $this->childGet('Vendors/' . path_segment($vendorId) . '/History', 'HistoryItem', $query); }
    public function transactions(int|string $vendorId, array $query = []): mixed { return $this->childGet('Vendors/' . path_segment($vendorId) . '/Transactions', 'Transaction', $query); }
    public function contacts(int|string $vendorId, array $query = []): mixed { return $this->childGet('Vendors/' . path_segment($vendorId) . '/Contacts', 'Contact', $query); }
    public function bills(int|string $vendorId, array $query = []): mixed { return $this->childGet('Vendors/' . path_segment($vendorId) . '/Bills', 'VendorBill', $query); }
}

class PropertiesResource extends Resource
{
    public function units(int|string $propertyId, array $query = []): mixed { return $this->childGet('Properties/' . path_segment($propertyId) . '/Units', 'Unit', $query); }
}

class UnitsResource extends Resource
{
    public function leases(int|string $unitId, array $query = []): mixed { return $this->childGet('Units/' . path_segment($unitId) . '/Leases', 'Lease', $query); }
    public function userDefinedValues(int|string $unitId, array $query = []): mixed { return $this->childGet('Units/' . path_segment($unitId) . '/UserDefinedValues', 'UserDefinedValue', $query); }
    public function linkAmenities(int|string $unitId, array|int $unitAmenityIds): mixed { return $this->client->post('Units/' . path_segment($unitId) . '/LinkAmenities', json: is_array($unitAmenityIds) ? $unitAmenityIds : [$unitAmenityIds]); }
    public function unlinkAmenities(int|string $unitId, array|int $unitAmenityIds): mixed { return $this->client->delete('Units/' . path_segment($unitId) . '/UnLinkAmenities', json: is_array($unitAmenityIds) ? $unitAmenityIds : [$unitAmenityIds]); }
}

class UsersResource extends Resource
{
    public function currentUser(array $query = []): mixed { return $this->childGet('Users/CurrentUser', 'User', $query); }
}

class RecurringChargesResource extends Resource
{
    public function postRecurringCharges(mixed $payload, array $query = []): mixed { return $this->childPost('RecurringCharges/PostRecurringCharges', $payload, null, $query); }
}

class ReportWriterReportsResource extends Resource
{
    public function run(int|string $reportId, array $query = []): mixed { return $this->childGet('ReportWriterReports/' . path_segment($reportId) . '/RunReportWriterReport', null, $query); }
}

class ServiceManagerIssuesResource extends Resource
{
    public function linkProperty(int|string $issueId, int|string $propertyId): mixed { return $this->client->post('ServiceManagerIssues/' . path_segment($issueId) . '/LinkProperty', params: ['propertyID' => $propertyId]); }
    public function linkUnit(int|string $issueId, int|string $unitId): mixed { return $this->client->post('ServiceManagerIssues/' . path_segment($issueId) . '/LinkUnit', params: ['unitID' => $unitId]); }
    public function history(int|string $issueId, array $query = []): mixed { return $this->childGet('ServiceManagerIssues/' . path_segment($issueId) . '/History', 'HistoryItem', $query); }
    public function addHistory(int|string $issueId, mixed $payload, array $query = []): mixed { return $this->childPost('ServiceManagerIssues/' . path_segment($issueId) . '/History', $payload, 'HistoryItem', $query); }
    public function properties(int|string $issueId, array $query = []): mixed { return $this->childGet('ServiceManagerIssues/' . path_segment($issueId) . '/Properties', 'Property', $query); }
    public function attachments(int|string $issueId, array $query = []): mixed { return $this->childGet('ServiceManagerIssues/' . path_segment($issueId) . '/Attachments', null, $query); }
    public function files(int|string $issueId, array $query = []): mixed { return $this->childGet('ServiceManagerIssues/' . path_segment($issueId) . '/Files', null, $query); }
    public function documents(int|string $issueId, array $query = []): mixed { return $this->childGet('ServiceManagerIssues/' . path_segment($issueId) . '/Documents', null, $query); }
    public function lineItems(int|string $issueId, array $query = []): mixed { return $this->childGet('ServiceManagerIssues/' . path_segment($issueId) . '/LineItems', null, $query); }
    public function addLineItems(int|string $issueId, mixed $payload, array $query = []): mixed { return $this->childPost('ServiceManagerIssues/' . path_segment($issueId) . '/LineItems', $payload, null, $query); }
    public function userDefinedValues(int|string $issueId, array $query = []): mixed { return $this->childGet('ServiceManagerIssues/' . path_segment($issueId) . '/UserDefinedValues', 'UserDefinedValue', $query); }
    public function updateUserDefinedValues(int|string $issueId, mixed $payload, array $query = []): mixed { return $this->childPost('ServiceManagerIssues/' . path_segment($issueId) . '/UserDefinedValues', $payload, 'UserDefinedValue', $query); }
    public function userDefinedFields(int|string $issueId, array $query = []): mixed { return $this->childGet('ServiceManagerIssues/' . path_segment($issueId) . '/UserDefinedFields', 'UserDefinedField', $query); }
    public function uploadAttachment(int|string $issueId, mixed $files, ?array $data = null): mixed { return $this->client->postMultipart('ServiceManagerIssues/' . path_segment($issueId) . '/Attachments', files: $files, data: $data); }
    public function uploadSignatureFile(int|string $issueId, mixed $files, ?array $data = null): mixed { return $this->client->postMultipart('ServiceManagerIssues/' . path_segment($issueId) . '/UploadSignatureFile', files: $files, data: $data); }
}

class ServiceManagerNamespace
{
    public Resource $statuses;
    public Resource $categories;
    public Resource $priorities;

    public function __construct(RentManagerClient $client)
    {
        $this->issues = new ServiceManagerIssuesResource($client, 'ServiceManagerIssues', 'ServiceManagerIssue');
        $this->statuses = new Resource($client, 'ServiceManagerStatuses', 'ServiceManagerStatus');
        $this->categories = new Resource($client, 'ServiceManagerCategories', 'ServiceManagerCategory');
        $this->priorities = new Resource($client, 'ServiceManagerPriorities', 'ServiceManagerPriority');
    }

    public ServiceManagerIssuesResource $issues;
}
