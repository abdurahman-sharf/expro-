<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reads, for a customer or for all of them, which items would have their credit moved by the order of E57
 * (the credit goes to the open item dated earliest, and among items of one day to the one created first, with
 * no priority for the invoice a credit note came from) without writing anything. It follows the settlement
 * of the credit step by step on what the ledger holds now: what no longer holds is released, what is over is
 * cut, and the credit that is free is applied in the order. The result is what CreditSettlement would leave,
 * which a test holds it to, not a second opinion on it.
 */
final class CreditOrderPreview
{
    private BaseConnection $db;
    private CustomerLedger $ledger;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->ledger = new CustomerLedger($this->db);
    }

    /**
     * @return list<int> The customers whose account holds a journal.
     */
    public function customerIds(): array
    {
        $rows = $this->db->query(
            'SELECT DISTINCT a.entity_id FROM ' . $this->db->prefixTable('accounting_accounts') . ' a
             JOIN ' . $this->db->prefixTable('accounting_journal_lines') . ' l ON l.account_id = a.account_id
             WHERE a.entity_type = ? ORDER BY a.entity_id',
            [EntityAccountService::CUSTOMER],
        )->getResult();

        return array_map(static fn (object $row): int => (int) $row->entity_id, $rows);
    }

    /**
     * @return list<CreditOrderPlan> Only the customers for whom there is something to say.
     */
    public function all(): array
    {
        $plans = [];

        foreach ($this->customerIds() as $customerId) {
            $plan = $this->forCustomer($customerId);

            if (! $plan->isEmpty()) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    public function forCustomer(int $customerId): CreditOrderPlan
    {
        $position = $this->ledger->position($customerId);
        $standing = $this->standing($customerId);
        $name     = $this->nameOf($customerId);

        $afterARun = $this->afterARun($position, $standing, $this->ledger->refundedSources($customerId));
        $fresh     = $this->apply($position, []);

        return new CreditOrderPlan(
            $customerId,
            $name,
            $afterARun === null ? [] : $this->changes($position, $standing, $afterARun),
            $this->changes($position, $standing, $fresh),
            $afterARun === null ? AccountingError::CreditRefunded : null,
        );
    }

    /**
     * @return list<array{id: int, target: int, source: string, amount: numeric-string}> The applications that stand, oldest first.
     */
    private function standing(int $customerId): array
    {
        return array_map(
            static fn (object $row): array => ['id' => (int) $row->application_id, 'target' => CustomerLedger::targetOf($row), 'source' => $row->source_type . ':' . $row->source_id, 'amount' => bcadd((string) $row->amount, '0', SaleAmounts::SCALE)],
            $this->ledger->applications($customerId),
        );
    }

    /**
     * The applications a settlement run would leave, or null when the run would be refused because a credit
     * that was paid back in cash would shrink.
     *
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $applications
     * @param array<string, numeric-string>                                             $refunded
     *
     * @return list<array{id: int, target: int, source: string, amount: numeric-string}>|null
     */
    private function afterARun(CustomerPosition $position, array $applications, array $refunded): ?array
    {
        $invoices = [];
        $sources  = [];

        foreach ($position->invoices as $invoice) {
            $invoices[$invoice->saleId] = $invoice;
        }

        foreach ($position->credits as $source) {
            $sources[$source->type . ':' . $source->sourceId] = $source;
        }

        $nextId = array_reduce($applications, static fn (int $max, array $application): int => max($max, $application['id']), 0) + 1;

        foreach (array_reverse($applications) as $application) {
            if (! isset($invoices[$application['target']]) || ! isset($sources[$application['source']])) {
                $this->release($applications, $nextId, $application['id'], $application['amount']);
            }
        }

        $into = [];

        foreach (array_reverse($applications) as $application) {
            $invoice = $invoices[$application['target']];
            $allowed = bcsub($invoice->total, $invoice->paid, SaleAmounts::SCALE);
            $allowed = bccomp($allowed, '0', SaleAmounts::SCALE) > 0 ? $allowed : '0.0000';

            $into[$application['target']] ??= $this->sumInto($applications, $application['target']);

            $surplus = bcsub($into[$application['target']], $allowed, SaleAmounts::SCALE);

            if (bccomp($surplus, '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            $cut = bccomp($surplus, $application['amount'], SaleAmounts::SCALE) < 0 ? $surplus : $application['amount'];

            $this->release($applications, $nextId, $application['id'], $cut);
            $into[$application['target']] = bcsub($into[$application['target']], $cut, SaleAmounts::SCALE);
        }

        foreach ($this->creditsOf($position, $applications) as $source) {
            $key  = $source->type . ':' . $source->sourceId;
            $over = bcsub(bcadd($source->applied, $source->refunded, SaleAmounts::SCALE), $source->amount, SaleAmounts::SCALE);

            if (bccomp($over, '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            foreach (array_reverse($applications) as $application) {
                if ($application['source'] !== $key) {
                    continue;
                }

                $cut = bccomp($over, $application['amount'], SaleAmounts::SCALE) < 0 ? $over : $application['amount'];

                $this->release($applications, $nextId, $application['id'], $cut);
                $over = bcsub($over, $cut, SaleAmounts::SCALE);

                if (bccomp($over, '0', SaleAmounts::SCALE) <= 0) {
                    break;
                }
            }
        }

        $held = [];

        foreach ($this->creditsOf($position, $applications) as $source) {
            $held[$source->type . ':' . $source->sourceId] = $source;
        }

        foreach ($refunded as $key => $amount) {
            if (! isset($held[$key]) || bccomp($held[$key]->amount, $amount, SaleAmounts::SCALE) < 0) {
                return null;
            }
        }

        return $this->apply($position, $applications, $nextId);
    }

    /**
     * Gives the free credit to the open items in the order, on top of the applications that stand.
     *
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $applications
     *
     * @return list<array{id: int, target: int, source: string, amount: numeric-string}>
     */
    private function apply(CustomerPosition $position, array $applications, ?int $nextId = null): array
    {
        $nextId ??= array_reduce($applications, static fn (int $max, array $application): int => max($max, $application['id']), 0) + 1;
        $open = [];
        $into = $this->sumTo($applications);

        foreach ($position->invoices as $invoice) {
            $figures = $this->figures($invoice, $into);

            if (bccomp($figures->open(), '0', SaleAmounts::SCALE) > 0) {
                $open[$invoice->saleId] = $figures->open();
            }
        }

        foreach ($this->creditsOf($position, $applications) as $source) {
            $available = $source->available();

            if (bccomp($available, '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            foreach (array_keys($open) as $invoiceId) {
                if (bccomp($available, '0', SaleAmounts::SCALE) <= 0) {
                    break;
                }

                if ($source->type === CreditSource::INVOICE_EXCESS && $source->sourceId === $invoiceId) {
                    continue;
                }

                $owed = $open[$invoiceId] ?? '0.0000';
                $take = bccomp($available, $owed, SaleAmounts::SCALE) < 0 ? $available : $owed;

                if (bccomp($take, '0', SaleAmounts::SCALE) <= 0) {
                    continue;
                }

                $applications[] = ['id' => $nextId++, 'target' => $invoiceId, 'source' => $source->type . ':' . $source->sourceId, 'amount' => $take];

                $open[$invoiceId] = bcsub($owed, $take, SaleAmounts::SCALE);

                if (bccomp($open[$invoiceId], '0', SaleAmounts::SCALE) <= 0) {
                    unset($open[$invoiceId]);
                }

                $available = bcsub($available, $take, SaleAmounts::SCALE);
            }
        }

        return $applications;
    }

    /**
     * The credits as the ledger would read them with these applications standing: what an invoice was paid
     * beyond what it came to is credit, so it follows what has been applied to the invoice.
     *
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $applications
     *
     * @return list<CreditSource>
     */
    private function creditsOf(CustomerPosition $position, array $applications): array
    {
        $into     = $this->sumTo($applications);
        $from     = $this->sumFrom($applications);
        $refunded = [];
        $groups   = [CreditSource::CREDIT_NOTE => [], CreditSource::OPENING => [], CreditSource::INVOICE_EXCESS => [], CreditSource::CUSTOMER_ADVANCE => [], CreditSource::CHEQUE => []];

        foreach ($position->credits as $credit) {
            $refunded[$credit->type . ':' . $credit->sourceId] = $credit->refunded;

            if ($credit->type !== CreditSource::INVOICE_EXCESS) {
                $groups[$credit->type][] = $credit;
            }
        }

        foreach ($position->invoices as $invoice) {
            $excess = $this->figures($invoice, $into)->excess();

            if (bccomp($excess, '0', SaleAmounts::SCALE) > 0) {
                $groups[CreditSource::INVOICE_EXCESS][] = new CreditSource(CreditSource::INVOICE_EXCESS, $invoice->saleId, null, $invoice->date, $excess, '0.0000', $refunded[CreditSource::INVOICE_EXCESS . ':' . $invoice->saleId] ?? '0.0000');
            }
        }

        $credits = array_merge(...array_values($groups));

        usort($credits, static fn (CreditSource $a, CreditSource $b): int => [$a->date, $a->sourceId] <=> [$b->date, $b->sourceId]);

        return array_map(
            static fn (CreditSource $credit): CreditSource => new CreditSource($credit->type, $credit->sourceId, $credit->originId, $credit->date, $credit->amount, $from[$credit->type . ':' . $credit->sourceId] ?? '0.0000', $credit->refunded),
            $credits,
        );
    }

    /**
     * @param array<int, numeric-string> $into What stands on each item from credit.
     */
    private function figures(InvoiceFigures $invoice, array $into): InvoiceFigures
    {
        return new InvoiceFigures($invoice->saleId, $invoice->date, $invoice->saleType, $invoice->total, $invoice->paid, $into[$invoice->saleId] ?? '0.0000', $invoice->returned, $invoice->dueDate, $invoice->termsDays, $invoice->opening, $invoice->debitNote);
    }

    /**
     * Takes an application away, or the part of it that is cut and keeps the rest as a new one.
     *
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $applications
     * @param numeric-string                                                            $cut
     */
    private function release(array &$applications, int &$nextId, int $id, string $cut): void
    {
        foreach ($applications as $index => $application) {
            if ($application['id'] !== $id) {
                continue;
            }

            unset($applications[$index]);

            $rest = bcsub($application['amount'], $cut, SaleAmounts::SCALE);

            if (bccomp($rest, '0', SaleAmounts::SCALE) > 0) {
                $applications[] = [...$application, 'id' => $nextId++, 'amount' => $rest];
            }

            $applications = array_values($applications);

            return;
        }
    }

    /**
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $applications
     *
     * @return numeric-string
     */
    private function sumInto(array $applications, int $target): string
    {
        return $this->sumTo($applications)[$target] ?? '0.0000';
    }

    /**
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $applications
     *
     * @return array<int, numeric-string>
     */
    private function sumTo(array $applications): array
    {
        $sums = [];

        foreach ($applications as $application) {
            $sums[$application['target']] = bcadd($sums[$application['target']] ?? '0', $application['amount'], SaleAmounts::SCALE);
        }

        return $sums;
    }

    /**
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $applications
     *
     * @return array<string, numeric-string>
     */
    private function sumFrom(array $applications): array
    {
        $sums = [];

        foreach ($applications as $application) {
            $sums[$application['source']] = bcadd($sums[$application['source']] ?? '0', $application['amount'], SaleAmounts::SCALE);
        }

        return $sums;
    }

    /**
     * The items whose credit by source is not the same in the two sets of applications.
     *
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $before
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $after
     *
     * @return list<CreditOrderMove>
     */
    private function changes(CustomerPosition $position, array $before, array $after): array
    {
        $was  = $this->bySource($before);
        $will = $this->bySource($after);
        $day  = [];

        foreach ($position->invoices as $invoice) {
            $day[$invoice->saleId] = $invoice->date;
        }

        $moves = [];

        foreach (array_unique([...array_keys($was), ...array_keys($will)]) as $target) {
            $had  = $was[$target] ?? [];
            $gets = $will[$target] ?? [];

            ksort($had);
            ksort($gets);

            if ($had === $gets) {
                continue;
            }

            $debitNote = SalesDebitNotes::idOfKey($target);
            $kind      = $debitNote !== null ? CreditOrderMove::DEBIT_NOTE : ($target < 0 ? CreditOrderMove::OPENING : CreditOrderMove::INVOICE);

            $moves[] = new CreditOrderMove($target, $kind, $debitNote ?? abs($target), $day[$target] ?? '', $had, $gets);
        }

        usort($moves, static fn (CreditOrderMove $a, CreditOrderMove $b): int => [$a->date, $a->key] <=> [$b->date, $b->key]);

        return $moves;
    }

    /**
     * @param list<array{id: int, target: int, source: string, amount: numeric-string}> $applications
     *
     * @return array<int, array<string, numeric-string>>
     */
    private function bySource(array $applications): array
    {
        $by = [];

        foreach ($applications as $application) {
            $by[$application['target']][$application['source']] = bcadd($by[$application['target']][$application['source']] ?? '0', $application['amount'], SaleAmounts::SCALE);
        }

        return $by;
    }

    private function nameOf(int $customerId): string
    {
        $row = $this->db->table('people')->select("TRIM(CONCAT(first_name, ' ', last_name)) AS name", false)->where('person_id', $customerId)->get()->getRow();

        return $row === null ? '' : (string) $row->name;
    }
}
