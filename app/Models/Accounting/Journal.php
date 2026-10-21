<?php

namespace App\Models\Accounting;

use CodeIgniter\Model;

class Journal extends Model
{
    protected $table            = 'accounting_journals';
    protected $primaryKey       = 'journal_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $returnType       = 'object';
    protected $allowedFields    = [
        'series',
        'number',
        'journal_date',
        'description',
        'status',
        'source_type',
        'source_id',
        'revision',
        'origin',
        'currency_code',
        'exchange_rate',
        'total_debit',
        'total_credit',
        'reverses_journal_id',
        'backfill_batch_id',
        'document_uuid',
        'created_by',
        'posted_by',
        'posted_at',
    ];

    public function findBySource(string $sourceType, int $sourceId, int $revision): ?object
    {
        return $this->where([
            'source_type' => $sourceType,
            'source_id'   => $sourceId,
            'revision'    => $revision,
        ])->first();
    }
}
