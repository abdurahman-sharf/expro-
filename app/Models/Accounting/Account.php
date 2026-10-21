<?php

namespace App\Models\Accounting;

use CodeIgniter\Model;

class Account extends Model
{
    protected $table            = 'accounting_accounts';
    protected $primaryKey       = 'account_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $returnType       = 'object';
    protected $allowedFields    = [
        'parent_id',
        'code',
        'name',
        'account_type',
        'normal_balance',
        'is_group',
        'is_system',
        'system_key',
        'active',
    ];

    public function findBySystemKey(string $systemKey): ?object
    {
        return $this->where('system_key', $systemKey)->first();
    }
}
