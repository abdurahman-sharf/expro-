<?php

namespace App\Models;

use CodeIgniter\Model;

class Tax_definition extends Model
{
    protected $table            = 'tax_definitions';
    protected $primaryKey       = 'tax_definition_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'tax_name',
        'tax_included',
        'liability_account_id',
    ];

    public static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /**
     * What the tax list says about the taxes with these names. A name the list does not hold is
     * not in the answer, and a tax with no option of its own has a null mode.
     *
     * @param list<string> $names
     *
     * @return array<string, array{included: bool|null, account: int|null}> By the name in lower case.
     */
    public function forNames(array $names): array
    {
        $names = array_values(array_unique(array_filter(array_map('trim', $names), static fn (string $name): bool => $name !== '')));

        if ($names === []) {
            return [];
        }

        $found = [];

        foreach ($this->db->table('tax_definitions')->whereIn('tax_name', $names)->get()->getResult() as $row) {
            $found[self::key((string) $row->tax_name)] = [
                'included' => $row->tax_included === null ? null : (int) $row->tax_included === 1,
                'account'  => $row->liability_account_id === null ? null : (int) $row->liability_account_id,
            ];
        }

        return $found;
    }
}
