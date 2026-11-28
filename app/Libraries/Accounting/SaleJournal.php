<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\OperationJournal;
use App\Libraries\Accounting\Engine\PostingEngine;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The journal of a sale and the plan of the cost of its goods, each in the mode its operation is posted
 * in. The stock movements, and the checks the cost makes of the document, still come from the code. A
 * return is left as the code makes it until the returns are taken over.
 */
final class SaleJournal
{
    public const SALE = 'sale';
    public const COST = 'sale_cost';

    private RoutingResolver $resolver;
    private OperationJournal $sales;
    private OperationJournal $costs;

    /**
     * @param Closure(): int $baseDecimals
     */
    public function __construct(private readonly SalePostingBuilder $builder, private readonly int $decimals, private readonly Closure $baseDecimals, ?BaseConnection $db = null, ?PostingEngine $engine = null)
    {
        $db ??= Database::connect();

        $this->resolver = new RoutingResolver($db);
        $this->sales    = new OperationJournal(self::SALE, $db, $engine);
        $this->costs    = new OperationJournal(self::COST, $db, $engine);
    }

    public static function forDatabase(BaseConnection $db, SalePostingBuilder $builder): self
    {
        return new self($builder, totals_decimals(), static fn (): int => (new Currencies($db))->baseDecimals(), $db);
    }

    /**
     * @throws PostingException
     */
    public function invoice(SaleDocument $sale, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        if ($sale->isReturn()) {
            return $this->builder->invoice($sale, $revision, $employeeId);
        }

        return $this->sales->draft(
            fn (): ?JournalDraft => $this->builder->invoice($sale, $revision, $employeeId),
            fn () => SaleFacts::invoice($sale, $revision, $employeeId, $this->decimals, $sale->currency === null ? 2 : ($this->baseDecimals)(), $this->resolver),
            SalePostingBuilder::SOURCE_SALE,
            $sale->saleId,
            $revision,
        );
    }

    /**
     * @throws PostingException
     */
    public function costPlan(SaleDocument $sale, int $revision, ?int $employeeId = null): StockPostingPlan
    {
        $plan = $this->builder->costPlan($sale, $revision, $employeeId);

        if ($sale->isReturn()) {
            return $plan;
        }

        $journal = $this->costs->draft(
            static fn (): ?JournalDraft => $plan->journal,
            static fn () => SaleFacts::cost($sale, $plan->movements, $revision, $employeeId),
            SalePostingBuilder::SOURCE_SALE,
            $sale->saleId,
            $revision,
        );

        return new StockPostingPlan($journal, $plan->movements, $plan->receipt, $plan->receiptMovements);
    }
}
