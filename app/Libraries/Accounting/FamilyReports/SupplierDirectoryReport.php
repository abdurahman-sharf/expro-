<?php

namespace App\Libraries\Accounting\FamilyReports;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The suppliers with their code, tax number, registry number, address and phone, which can be cut
 * down to a city or a country as it is written on the supplier. It holds no figures, so it is not
 * limited by branch.
 */
final class SupplierDirectoryReport implements FamilyReport
{
    use BuildsReports;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function build(ReportInput $input): ReportTable
    {
        $builder = $this->db->table('suppliers s')
            ->select("s.person_id, COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS name, s.tax_id, s.commercial_registry, p.address_1, p.address_2, p.city, p.country, p.phone_number, p.email", false)
            ->join('people p', 'p.person_id = s.person_id')
            ->where('s.deleted', 0)
            ->orderBy('name', 'ASC')
            ->orderBy('s.person_id', 'ASC');

        foreach (['city' => 'p.city', 'country' => 'p.country'] as $name => $column) {
            if ($input->text($name) !== '') {
                $builder->where($column, $input->text($name));
            }
        }

        $rows = [];

        foreach ($builder->get()->getResult() as $supplier) {
            $address = trim(implode(', ', array_filter([(string) $supplier->address_1, (string) $supplier->address_2, (string) $supplier->city, (string) $supplier->country], static fn (string $part): bool => trim($part) !== '')));

            $rows[] = new ReportRow([
                'code'         => (string) $supplier->person_id,
                'name'         => (string) $supplier->name,
                'tax_number'   => (string) ($supplier->tax_id ?? ''),
                'registry'     => (string) ($supplier->commercial_registry ?? ''),
                'address'      => $address,
                'phone'        => (string) ($supplier->phone_number ?? ''),
                'email'        => (string) ($supplier->email ?? ''),
            ]);
        }

        return new ReportTable($this->text('supplier_directory_title'), [
            $this->column('code', ColumnKind::Plain), $this->column('name'), $this->column('tax_number'), $this->column('registry'), $this->column('address'), $this->column('phone'), $this->column('email'),
        ], [new ReportBlock(null, $rows)], null, [], [$this->text('note_directory_no_figures'), $this->text('note_directory_text_filter')]);
    }
}
