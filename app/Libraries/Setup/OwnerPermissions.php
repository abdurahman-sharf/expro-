<?php

namespace App\Libraries\Setup;

use CodeIgniter\Database\BaseConnection;

/**
 * Gives the owner of a new installation the grant of every module and every permission the installation has, read
 * from the tables at that moment and not from a list written in the code. It only grants. Every check a screen
 * makes of the permission, the branches and the confirmation stays in place, and the owner passes them the way
 * anybody holding the permission does.
 */
final class OwnerPermissions
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return int How many grants were added.
     */
    public function grantEverything(int $personId): int
    {
        $group = (string) ($this->db->table('grants')->select('menu_group')->where('person_id', $personId)->where('permission_id', 'config')->get()->getRow()->menu_group ?? 'office');

        $held = array_flip(array_column($this->db->table('grants')->select('permission_id')->where('person_id', $personId)->get()->getResultArray(), 'permission_id'));
        $rows = $this->db->table('permissions p')->select('p.permission_id, m.module_id')->join('modules m', 'm.module_id = p.permission_id', 'left')->get()->getResult();

        $grants = [];

        foreach ($rows as $row) {
            if (! isset($held[$row->permission_id])) {
                $grants[] = ['permission_id' => $row->permission_id, 'person_id' => $personId, 'menu_group' => $row->module_id === null ? '--' : $group];
            }
        }

        if ($grants !== []) {
            $this->db->table('grants')->ignore(true)->insertBatch($grants);
        }

        return count($grants);
    }
}
