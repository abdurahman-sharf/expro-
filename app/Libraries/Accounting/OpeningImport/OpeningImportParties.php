<?php

namespace App\Libraries\Accounting\OpeningImport;

use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;

/**
 * Finds the customers or suppliers a file names, a thousand at a time, and the opening balances they already
 * have. A party is named by its number, by its exact name (the name of the person or the name of the company) or
 * by its account number, and a cell that fits more than one party is ambiguous. "id:12", "#12" and "code:ABC" say
 * which of the three a cell is, for a name that could be taken for another.
 */
final class OpeningImportParties
{
    private const CHUNK = 400;

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public static function keyOf(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * @param list<string> $texts The cells of the party column.
     *
     * @return array<string, array<int, string>> The parties each cell fits, by the key of the cell: their numbers with their names.
     */
    public function resolve(OpeningImportKind $kind, array $texts): array
    {
        $ids   = [];
        $codes = [];
        $names = [];
        $found = [];

        foreach ($texts as $text) {
            $key = self::keyOf($text);

            if ($key === '') {
                continue;
            }

            $found[$key] = [];

            if (preg_match('/^(?:id:|#)\s*(\d{1,10})$/i', $key, $match) === 1) {
                $ids[(int) $match[1]][$key] = true;
            } elseif (preg_match('/^code:\s*(.+)$/i', $key, $match) === 1) {
                $codes[trim($match[1])][$key] = true;
            } else {
                if (ctype_digit($key) && strlen($key) <= 10) {
                    $ids[(int) $key][$key] = true;
                }

                $codes[$key][$key] = true;
                $names[$key][$key] = true;
            }
        }

        $table = $this->db->prefixTable($kind->table());

        foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
            foreach ($this->db->query("SELECT t.person_id AS id FROM {$table} t WHERE t.deleted = 0 AND t.person_id IN (" . implode(',', array_map('intval', $chunk)) . ')')->getResult() as $row) {
                foreach (array_keys($ids[(int) $row->id]) as $key) {
                    $found[$key][(int) $row->id] = '';
                }
            }
        }

        foreach (array_chunk(array_keys($codes), self::CHUNK) as $chunk) {
            foreach ($this->db->query("SELECT t.person_id AS id, t.account_number AS code FROM {$table} t WHERE t.deleted = 0 AND t.account_number IN (" . $this->quoted($chunk) . ')')->getResult() as $row) {
                foreach (array_keys($codes[self::keyOf((string) $row->code)] ?? []) as $key) {
                    $found[$key][(int) $row->id] = '';
                }
            }
        }

        $people = $this->db->prefixTable('people');
        $person = "TRIM(CONCAT_WS(' ', p.first_name, p.last_name))";

        foreach (array_chunk(array_keys($names), self::CHUNK) as $chunk) {
            $list = $this->quoted($chunk);

            foreach ($this->db->query("SELECT t.person_id AS id, {$person} AS person_name, t.company_name AS company FROM {$table} t JOIN {$people} p ON p.person_id = t.person_id WHERE t.deleted = 0 AND ({$person} IN ({$list}) OR t.company_name IN ({$list}))")->getResult() as $row) {
                foreach ([self::keyOf((string) $row->person_name), self::keyOf((string) $row->company)] as $candidate) {
                    foreach (array_keys($names[$candidate] ?? []) as $key) {
                        $found[$key][(int) $row->id] = '';
                    }
                }
            }
        }

        $matched = [];

        foreach ($found as $parties) {
            foreach (array_keys($parties) as $id) {
                $matched[$id] = true;
            }
        }

        $labels = $this->labels($kind, array_keys($matched));

        foreach ($found as $key => $parties) {
            foreach (array_keys($parties) as $id) {
                $found[$key][$id] = $labels[$id] ?? (string) $id;
            }
        }

        return $found;
    }

    /**
     * The opening balances parties have that stand, which a file cannot give them again.
     *
     * @param list<int> $partyIds
     *
     * @return array<int, list<array{side: string, amount: string, date: string}>>
     */
    public function standing(OpeningImportKind $kind, array $partyIds): array
    {
        $standing = [];

        foreach (array_chunk(array_values(array_unique($partyIds)), self::CHUNK) as $chunk) {
            $list = implode(',', array_map('intval', $chunk));

            if ($kind === OpeningImportKind::Customers) {
                $rows = $this->db->query('SELECT customer_id AS party, side, amount, opening_date AS day FROM ' . $this->db->prefixTable('customer_openings') . " WHERE status = 'posted' AND company_id = ? AND customer_id IN ({$list})", [CompanyContext::id()])->getResult();

                foreach ($rows as $row) {
                    $standing[(int) $row->party][] = ['side' => (string) $row->side, 'amount' => bcadd((string) $row->amount, '0', 4), 'date' => (string) $row->day];
                }

                continue;
            }

            $advances = $this->db->query('SELECT supplier_id AS party, amount, advance_date AS day FROM ' . $this->db->prefixTable('supplier_advances') . " WHERE kind = ? AND status <> 'cancelled' AND company_id = ? AND supplier_id IN ({$list})", [SupplierAdvances::OPENING, CompanyContext::id()])->getResult();

            foreach ($advances as $row) {
                $standing[(int) $row->party][] = ['side' => SupplierAdvances::THEY_OWE, 'amount' => bcadd((string) $row->amount, '0', 4), 'date' => (string) $row->day];
            }

            $purchases = $this->db->query(
                'SELECT r.supplier_id AS party, j.total_debit AS amount, DATE(r.receiving_time) AS day FROM ' . $this->db->prefixTable('receivings') . ' r JOIN ' . $this->db->prefixTable('accounting_journals') . " j ON j.source_type = ? AND j.source_id = r.receiving_id AND j.entry_type = ? AND j.status = ? WHERE r.receiving_type = ? AND r.supplier_id IN ({$list})",
                [ReceivingPostingBuilder::SOURCE_PURCHASE, JournalEntryType::General->value, JournalStatus::Posted->value, SupplierAdvances::OPENING_TYPE],
            )->getResult();

            foreach ($purchases as $row) {
                $standing[(int) $row->party][] = ['side' => SupplierAdvances::WE_OWE, 'amount' => bcadd((string) $row->amount, '0', 4), 'date' => (string) $row->day];
            }
        }

        return $standing;
    }

    /**
     * @param list<int> $partyIds
     *
     * @return array<int, string> The name of each party as a screen shows it: the person, and the company after them.
     */
    private function labels(OpeningImportKind $kind, array $partyIds): array
    {
        $labels = [];
        $table  = $this->db->prefixTable($kind->table());
        $people = $this->db->prefixTable('people');

        foreach (array_chunk($partyIds, self::CHUNK) as $chunk) {
            $rows = $this->db->query("SELECT t.person_id AS id, TRIM(CONCAT_WS(' ', p.first_name, p.last_name)) AS person_name, t.company_name AS company FROM {$table} t JOIN {$people} p ON p.person_id = t.person_id WHERE t.person_id IN (" . implode(',', array_map('intval', $chunk)) . ')')->getResult();

            foreach ($rows as $row) {
                $person  = (string) $row->person_name;
                $company = trim((string) $row->company);

                $labels[(int) $row->id] = $person === '' ? $company : ($company === '' || $company === $person ? $person : "{$person} ({$company})");
            }
        }

        return $labels;
    }

    /**
     * @param list<string> $values
     */
    private function quoted(array $values): string
    {
        return implode(',', array_map(fn (string $value): string => $this->db->escape($value), $values));
    }
}
