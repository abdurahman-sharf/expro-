<?php

namespace App\Models\Accounting;

use CodeIgniter\Model;

class CostCenter extends Model
{
    protected $table            = 'accounting_cost_centers';
    protected $primaryKey       = 'cost_center_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $returnType       = 'object';
    protected $allowedFields    = ['parent_id', 'code', 'name', 'active'];
}
