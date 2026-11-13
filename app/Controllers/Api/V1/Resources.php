<?php

namespace App\Controllers\Api\V1;

use App\Controllers\BaseController;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ReportDocument;
use App\Libraries\Accounting\OperationalReports\ReportDocuments;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Integrations\ApiContext;
use App\Libraries\Integrations\ApiKey;
use App\Libraries\Integrations\ApiScope;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;

/**
 * The read-only API, version 1. Three reads and no write: who the key is, what the customers owe, and the sales
 * documents of a stretch of days. Each read needs its scope, a key limited to a branch reads that branch alone,
 * and nothing of the books' inside is given: no journal, no cost and no tax account, only what a document or a
 * customer shows.
 */
class Resources extends BaseController
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;
    private const DEFAULT_DAYS  = 30;
    private const MAX_DAYS      = 366;

    public function ping(): ResponseInterface
    {
        $key = $this->key();

        if ($key === null) {
            return $this->unauthorized();
        }

        return $this->response->setJSON(['data' => [
            'status'     => 'ok',
            'prefix'     => $key->prefix,
            'scopes'     => array_map(static fn (ApiScope $scope): string => $scope->value, $key->scopes),
            'branch_id'  => $key->branchId,
            'rate_limit' => $key->rateLimit,
            'expires_at' => $key->expiresAt,
        ]]);
    }

    public function customerBalances(): ResponseInterface
    {
        $key = $this->key();

        if ($key === null) {
            return $this->unauthorized();
        }

        if (! $key->allows(ApiScope::ReadCustomers)) {
            return $this->refuse(ResponseInterface::HTTP_FORBIDDEN, 'insufficient_scope', 'The key lacks the scope ' . ApiScope::ReadCustomers->value . '.');
        }

        if ($key->branchId !== null) {
            return $this->refuse(ResponseInterface::HTTP_FORBIDDEN, 'branch_limited_key', 'What a customer owes is a figure of the whole company: it is given to a key that is not limited to a branch.');
        }

        if (! (new AccountingSettings())->isEnabled()) {
            return $this->refuse(ResponseInterface::HTTP_CONFLICT, 'accounting_disabled', 'The balances come from the books, and accounting is off.');
        }

        $page = $this->page();

        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $found = (new CustomerLedger())->customersWithBalance('', 'customer_name', 'asc', $page['limit'], $page['offset']);

        return $this->response->setJSON([
            'data' => array_map(static fn (object $row): array => ['customer_id' => (int) $row->customer_id, 'name' => (string) $row->customer_name, 'balance' => bcadd((string) $row->balance, '0', 4)], $found['rows']),
            'meta' => ['total' => $found['total'], 'limit' => $page['limit'], 'offset' => $page['offset']],
        ]);
    }

    public function salesDocuments(): ResponseInterface
    {
        $key = $this->key();

        if ($key === null) {
            return $this->unauthorized();
        }

        if (! $key->allows(ApiScope::ReadDocuments)) {
            return $this->refuse(ResponseInterface::HTTP_FORBIDDEN, 'insufficient_scope', 'The key lacks the scope ' . ApiScope::ReadDocuments->value . '.');
        }

        if (! (new AccountingSettings())->isEnabled()) {
            return $this->refuse(ResponseInterface::HTTP_CONFLICT, 'accounting_disabled', 'The documents come from the books, and accounting is off.');
        }

        $page = $this->page();

        if ($page instanceof ResponseInterface) {
            return $page;
        }

        $days = $this->days();

        if ($days instanceof ResponseInterface) {
            return $days;
        }

        $kinds = $this->kinds();

        if ($kinds instanceof ResponseInterface) {
            return $kinds;
        }

        $customerId = $this->request->getGet('customer_id');

        if ($customerId !== null && (! is_string($customerId) || ! ctype_digit($customerId) || (int) $customerId < 1)) {
            return $this->invalid('customer_id');
        }

        $scope     = ReportScope::resolve(null, $key->branchId === null ? null : [$key->branchId]);
        $documents = (new ReportDocuments())->read($scope, $days['from'], $days['to'], $kinds, $customerId === null ? null : (int) $customerId);

        return $this->response->setJSON([
            'data' => array_map($this->documentRow(...), array_slice($documents, $page['offset'], $page['limit'])),
            'meta' => ['total' => count($documents), 'limit' => $page['limit'], 'offset' => $page['offset'], 'from' => $days['from'], 'to' => $days['to']],
        ]);
    }

    private function key(): ?ApiKey
    {
        return ApiContext::key();
    }

    /**
     * @return array<string, mixed>
     */
    private function documentRow(ReportDocument $document): array
    {
        $today = date('Y-m-d');

        return [
            'id'            => $document->id,
            'kind'          => $document->kind->value,
            'number'        => $document->number,
            'date'          => $document->date,
            'branch'        => $document->branchCode,
            'customer_id'   => $document->customerId,
            'customer_name' => $document->customerName,
            'value'         => $document->value,
            'tax'           => $document->tax,
            'total'         => $document->total,
            'due_date'      => $document->figures?->dueDate,
            'paid'          => $document->figures?->paid,
            'credited'      => $document->figures?->applied,
            'open'          => $document->figures?->open(),
            'overdue_days'  => $document->figures?->daysOverdue($today),
        ];
    }

    /**
     * @return array{limit: int, offset: int}|ResponseInterface
     */
    private function page(): array|ResponseInterface
    {
        $limit  = $this->request->getGet('limit');
        $offset = $this->request->getGet('offset');

        if ($limit !== null && (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > self::MAX_LIMIT)) {
            return $this->invalid('limit');
        }

        if ($offset !== null && (! is_string($offset) || ! ctype_digit($offset))) {
            return $this->invalid('offset');
        }

        return ['limit' => $limit === null ? self::DEFAULT_LIMIT : (int) $limit, 'offset' => $offset === null ? 0 : (int) $offset];
    }

    /**
     * @return array{from: string, to: string}|ResponseInterface
     */
    private function days(): array|ResponseInterface
    {
        $to   = $this->request->getGet('to');
        $from = $this->request->getGet('from');
        $to   = $to === null ? date('Y-m-d') : $to;

        if (! $this->isDay($to)) {
            return $this->invalid('to');
        }

        $from = $from === null ? (new DateTimeImmutable($to))->modify('-' . self::DEFAULT_DAYS . ' days')->format('Y-m-d') : $from;

        if (! $this->isDay($from) || $from > $to) {
            return $this->invalid('from');
        }

        if ((new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days >= self::MAX_DAYS) {
            return $this->refuse(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY, 'range_too_long', 'A call reads at most ' . self::MAX_DAYS . ' days.');
        }

        return ['from' => $from, 'to' => $to];
    }

    /**
     * @return list<DocumentKind>|ResponseInterface
     */
    private function kinds(): array|ResponseInterface
    {
        $kind = $this->request->getGet('kind');

        if ($kind === null || $kind === 'all') {
            return [];
        }

        $found = is_string($kind) ? DocumentKind::tryFrom($kind) : null;

        return $found === null || $found === DocumentKind::DebitNote ? $this->invalid('kind') : [$found];
    }

    private function isDay(mixed $value): bool
    {
        $day = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        return $day !== false && $day->format('Y-m-d') === $value;
    }

    private function invalid(string $parameter): ResponseInterface
    {
        return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setJSON(['error' => 'invalid_parameter', 'message' => "The parameter {$parameter} is not valid.", 'parameter' => $parameter]);
    }

    private function unauthorized(): ResponseInterface
    {
        return $this->refuse(ResponseInterface::HTTP_UNAUTHORIZED, 'unauthorized', 'The key is missing or not accepted.');
    }

    private function refuse(int $status, string $code, string $message): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON(['error' => $code, 'message' => $message]);
    }
}
