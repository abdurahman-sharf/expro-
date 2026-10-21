<?php

namespace App\Models\Accounting;

use CodeIgniter\Model;

class JournalLine extends Model
{
    protected $table            = 'accounting_journal_lines';
    protected $primaryKey       = 'line_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $returnType       = 'object';
    protected $allowedFields    = [
        'journal_id',
        'line_no',
        'account_id',
        'description',
        'debit',
        'credit',
        'foreign_amount',
        'cost_center_id',
        'party_type',
        'party_id',
        'tax_code',
        'tax_rate',
        'tax_base',
        'tax_exemption_reason',
    ];
}
