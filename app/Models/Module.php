<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Roles\RoleGrants;
use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;

/**
 * Module class
 */
class Module extends Model
{
    protected $table            = 'modules';
    protected $primaryKey       = 'module_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'name_lang_key',
        'desc_lang_key',
        'sort',
    ];

    public function get_module_name(string $module_id): string
    {
        $builder = $this->db->table('modules');
        $query   = $builder->getWhere(['module_id' => $module_id], 1);

        if ($query->getNumRows() === 1) {    // TODO: ===
            $row = $query->getRow();

            return lang('Module.' . $row->module_id);
        }

        return lang('Error.unknown');
    }

    public function get_module_desc(string $module_id): string    // TODO: This method doesn't seem to be called in the code.  Is it needed?  Also, probably should change the name to get_module_description()
    {
        $builder = $this->db->table('modules');
        $query   = $builder->getWhere(['module_id' => $module_id], 1);

        if ($query->getNumRows() === 1) {    // TODO: ===
            $row = $query->getRow();

            return lang('Module.' . $row->module_id . '_desc');
        }

        return lang('Error.unknown');
    }

    public function get_all_permissions(): ResultInterface
    {
        $builder = $this->db->table('permissions');

        return $builder->get();
    }

    public function get_all_subpermissions(): ResultInterface
    {
        $builder = $this->db->table('permissions');
        $builder->join('modules AS modules', 'modules.module_id = permissions.module_id');    // TODO: can the table parameter just be modules instead of modules AS modules?

        // Can't quote the parameters correctly when using different operators
        $builder->where('modules.module_id != ', 'permission_id', false);

        return $builder->get();
    }

    public function get_all_modules(): ResultInterface
    {
        $builder = $this->db->table('modules');
        $builder->orderBy('sort', 'asc');

        return $builder->get();
    }

    public function getAllowedModules(int $personId): ResultInterface
    {
        $heldThroughRoles = (new RoleGrants($this->db))->idsFor($personId);
        $builder          = $this->db->table('modules');
        $builder->join('permissions', 'permissions.permission_id = modules.module_id');
        $builder->join('grants', 'permissions.permission_id = grants.permission_id AND grants.person_id = ' . (int) $personId, 'left');
        $builder->groupStart();
        $builder->where('grants.person_id', $personId);

        if ($heldThroughRoles !== []) {
            $builder->orWhereIn('modules.module_id', $heldThroughRoles);
        }

        $builder->groupEnd();
        $builder->where('sort !=', 0);
        $builder->where('modules.module_id !=', 'office');

        if (! (new AccountingSettings($this->db))->isEnabled()) {
            $builder->groupStart()
                ->notLike('modules.module_id', 'accounting_', 'after')
                ->orWhereIn('modules.module_id', ['accounting_settings', 'accounting_routing'])
                ->groupEnd();
        }

        $builder->orderBy('sort', 'asc');

        return $builder->get();
    }
}
