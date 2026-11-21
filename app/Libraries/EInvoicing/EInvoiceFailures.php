<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * The record of the sales whose document could not be issued under the Saudi rules, written after the sale was
 * undone. It names who completed the sale, the branch, the kind of sale, the reason and a fingerprint of the cart,
 * never the number of the sale, which no longer exists and may come back. The message of a failure that is not one
 * of the application's own is not kept, only its class, so that nothing the database said is copied here.
 */
final class EInvoiceFailures
{
    public const MESSAGE_MAX = 500;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function record(string $source, Throwable $failure, ?int $employeeId, ?int $branchId, ?int $saleType, ?string $fingerprint): void
    {
        if (! $this->db->tableExists($this->db->prefixTable('einvoice_failures'), false)) {
            return;
        }

        [$error, $message] = match (true) {
            $failure instanceof EInvoiceException                                                                                  => [$failure->error->value, $failure->getMessage()],
            $failure instanceof AccountingException || $failure instanceof PostingException || $failure instanceof BranchException => [$failure->error->value, $failure->getMessage()],
            default                                                                                                                => ['unexpected', $failure::class],
        };

        $this->db->table('einvoice_failures')->insert([
            'company_id'  => CompanyContext::id(),
            'branch_id'   => $branchId,
            'employee_id' => $employeeId,
            'source'      => mb_substr($source, 0, 20),
            'sale_type'   => $saleType,
            'error'       => mb_substr($error, 0, 64),
            'message'     => mb_substr($message, 0, self::MESSAGE_MAX),
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * @return list<object> The latest failures of the company, the newest first.
     */
    public function recent(int $limit = 50): array
    {
        if (! $this->db->tableExists($this->db->prefixTable('einvoice_failures'), false)) {
            return [];
        }

        return $this->db->table('einvoice_failures')->where('company_id', CompanyContext::id())->orderBy('failure_id', 'DESC')->limit($limit)->get()->getResult();
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    public static function fingerprintOf(array $items): string
    {
        $lines = array_map(static fn (array $line): array => [(int) ($line['item_id'] ?? 0), (string) ($line['quantity'] ?? ''), (string) ($line['price'] ?? '')], array_values($items));

        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR));
    }
}
