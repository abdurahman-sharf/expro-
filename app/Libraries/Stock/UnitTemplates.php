<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The unit templates: a base unit and the larger units that convert to it by a factor. The form of the
 * reference system was observed (the main unit name, its short name of up to 25 characters, a template
 * name, an active switch and rows of a larger unit with its name, factor and short name). Its hidden
 * switch to update all costs was not observed to do anything and is not built. A factor is a positive
 * number of at most four decimals. Once a document line has used a unit of a template, its units and
 * factors never change, because the stored line says what it meant by pointing at them: editing a used
 * template is refused by name, and a changed version is saved as a new template that carries the old one
 * as its predecessor. Only the name of a template and its active switch change after use.
 */
final class UnitTemplates
{
    use RunsInTransaction;

    public const ENTITY       = 'unit_template';
    public const NAME_MAX     = 100;
    public const UNIT_MAX     = 50;
    public const SMALL_MAX    = 25;
    public const UNITS_MAX    = 20;
    private const FACTOR      = '/^\d{1,10}(\.\d{1,4})?$/';
    private const LINE_TABLES = ['sales_items', 'receivings_items', 'stock_permit_lines'];

    private BaseConnection $db;
    private AccountingGuard $guard;
    private StockOptions $options;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->guard   = $guard ?? new AccountingGuard();
        $this->options = new StockOptions($this->db, $this->guard);
        $this->audit   = new AuditLog($this->db);
    }

    /**
     * @param array{name: string, main_unit_name: string, main_small_name: string, active?: bool, units: list<array{name: string, factor: string, small_name: string}>} $input
     *
     * @throws AccountingException|PostingException
     */
    public function create(array $input, ?int $employeeId = null): int
    {
        $this->assertMayManage($employeeId);

        $data = $this->checked($input);

        return $this->transactional(function () use ($data, $employeeId): int {
            $this->assertNameFree($data['name'], 1);

            $templateId = $this->insertTemplate($data, 1, null, $employeeId);
            $this->audit->write(self::ENTITY, $templateId, 'unit_template_created', $employeeId, JournalOrigin::User, $this->details($data));

            return $templateId;
        });
    }

    /**
     * Changes a template that no document line has used. The name and the active switch of a used
     * template change too, its units and factors do not.
     *
     * @param array{name: string, main_unit_name: string, main_small_name: string, active?: bool, units: list<array{name: string, factor: string, small_name: string}>} $input
     *
     * @throws AccountingException|PostingException
     */
    public function update(int $templateId, array $input, ?int $employeeId = null): void
    {
        $this->assertMayManage($employeeId);

        $data = $this->checked($input);

        $this->transactional(function () use ($templateId, $data, $employeeId): void {
            $template = $this->lockedTemplate($templateId);
            $before   = $this->find($templateId);

            if ($data['name'] !== $template->name) {
                $this->assertNameFree($data['name'], (int) $template->version, $templateId);
            }

            if ($this->isUsed($templateId) && $this->shapeOf($before) !== $this->shapeOfInput($data)) {
                throw new AccountingException(AccountingError::UnitTemplateUsed, 'A unit of this template has been used by a document line.', ['name' => (string) $template->name]);
            }

            $this->db->table('unit_templates')->where('template_id', $templateId)->update([
                'name'            => $data['name'],
                'main_unit_name'  => $data['main_unit_name'],
                'main_small_name' => $data['main_small_name'],
                'active'          => $data['active'] ? 1 : 0,
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);

            if (! $this->isUsed($templateId)) {
                $this->replaceUnits($templateId, $data['units']);
            }

            $this->audit->write(self::ENTITY, $templateId, 'unit_template_updated', $employeeId, JournalOrigin::User, ['before' => $this->shapeOf($before), 'after' => $this->details($data)]);
        });
    }

    /**
     * Saves the changed units of a template that has been used as a new template, the next version of
     * the same name, and switches the old one off so that no new item is given it.
     *
     * @param array{name?: string, main_unit_name: string, main_small_name: string, units: list<array{name: string, factor: string, small_name: string}>} $input
     *
     * @throws AccountingException|PostingException
     */
    public function newVersion(int $templateId, array $input, ?int $employeeId = null): int
    {
        $this->assertMayManage($employeeId);

        return $this->transactional(function () use ($templateId, $input, $employeeId): int {
            $template = $this->lockedTemplate($templateId);
            $data     = $this->checked([...$input, 'name' => (string) $template->name, 'active' => true]);
            $latest   = (int) $this->db->table('unit_templates')->selectMax('version')->where(['company_id' => CompanyContext::id(), 'name' => (string) $template->name])->get()->getRow()->version;

            $newId = $this->insertTemplate($data, $latest + 1, $templateId, $employeeId);

            $this->db->table('unit_templates')->where('template_id', $templateId)->update(['active' => 0, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->audit->write(self::ENTITY, $newId, 'unit_template_versioned', $employeeId, JournalOrigin::User, ['previous_template_id' => $templateId, ...$this->details($data)]);

            return $newId;
        });
    }

    /**
     * @throws AccountingException
     */
    public function setActive(int $templateId, bool $active, ?int $employeeId = null): void
    {
        $this->assertMayManage($employeeId);

        $this->lockedTemplate($templateId);
        $this->db->table('unit_templates')->where('template_id', $templateId)->update(['active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->audit->write(self::ENTITY, $templateId, $active ? 'unit_template_activated' : 'unit_template_deactivated', $employeeId, JournalOrigin::User);
    }

    public function find(int $templateId): ?UnitTemplate
    {
        $row = $this->db->table('unit_templates')->where(['template_id' => $templateId, 'company_id' => CompanyContext::id()])->get()->getRow();

        if ($row === null) {
            return null;
        }

        $units = array_map(
            static fn (object $unit): TemplateUnit => new TemplateUnit((int) $unit->unit_id, (string) $unit->name, bcadd((string) $unit->factor, '0', UnitLine::FACTOR_SCALE), (string) $unit->small_name, (int) $unit->position),
            $this->db->table('unit_template_units')->where('template_id', $templateId)->orderBy('position', 'ASC')->orderBy('unit_id', 'ASC')->get()->getResult(),
        );

        return new UnitTemplate((int) $row->template_id, (string) $row->name, (string) $row->main_unit_name, (string) $row->main_small_name, (int) $row->version, $row->previous_template_id === null ? null : (int) $row->previous_template_id, (bool) $row->active, $units);
    }

    /**
     * @return list<UnitTemplate>
     */
    public function all(bool $activeOnly = false): array
    {
        $query = $this->db->table('unit_templates')->select('template_id')->where('company_id', CompanyContext::id());

        if ($activeOnly) {
            $query->where('active', 1);
        }

        $templates = [];

        foreach ($query->orderBy('name', 'ASC')->orderBy('version', 'DESC')->get()->getResult() as $row) {
            $templates[] = $this->find((int) $row->template_id);
        }

        return array_values(array_filter($templates));
    }

    public function isUsed(int $templateId): bool
    {
        $ids = array_column($this->db->table('unit_template_units')->select('unit_id')->where('template_id', $templateId)->get()->getResultArray(), 'unit_id');

        if ($ids === []) {
            return false;
        }

        foreach (self::LINE_TABLES as $table) {
            if ($this->db->table($table)->whereIn('unit_id', $ids)->countAllResults() > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The unit of a template that an item may be entered in, or null when the item's template has no
     * such unit.
     */
    public function unitOfItem(int $itemId, int $unitId): ?TemplateUnit
    {
        $profile = (new ItemStockProfile($this->db, $this->guard))->get($itemId);

        return $profile->unitTemplateId === null ? null : $this->find($profile->unitTemplateId)?->unit($unitId);
    }

    /**
     * @throws AccountingException
     */
    private function assertMayManage(?int $employeeId): void
    {
        $this->guard->require(StockUnitPermissions::UNITS_MANAGE, $employeeId);

        if (! $this->options->unitsEnabled()) {
            throw new AccountingException(AccountingError::UnitsOff, 'Multiple units are switched off.');
        }
    }

    /**
     * @throws AccountingException
     */
    private function lockedTemplate(int $templateId): object
    {
        $row = LockedRead::row($this->db, 'SELECT * FROM ' . $this->db->prefixTable('unit_templates') . ' WHERE template_id = ? AND company_id = ? FOR UPDATE', [$templateId, CompanyContext::id()]);

        if ($row === null) {
            throw new AccountingException(AccountingError::UnitTemplateNotFound, 'The unit template does not exist.', ['id' => $templateId]);
        }

        return $row;
    }

    /**
     * @throws AccountingException
     */
    private function assertNameFree(string $name, int $version, ?int $exceptId = null): void
    {
        $query = $this->db->table('unit_templates')->where(['company_id' => CompanyContext::id(), 'name' => $name, 'version' => $version]);

        if ($exceptId !== null) {
            $query->where('template_id !=', $exceptId);
        }

        if ($query->countAllResults() > 0) {
            throw new AccountingException(AccountingError::UnitTemplateNameTaken, 'A unit template of this name exists.', ['name' => $name]);
        }
    }

    /**
     * @param array{name: string, main_unit_name: string, main_small_name: string, active: bool, units: list<array{name: string, factor: string, small_name: string}>} $data
     */
    private function insertTemplate(array $data, int $version, ?int $previousId, ?int $employeeId): int
    {
        $this->db->table('unit_templates')->insert([
            'company_id'           => CompanyContext::id(),
            'name'                 => $data['name'],
            'main_unit_name'       => $data['main_unit_name'],
            'main_small_name'      => $data['main_small_name'],
            'version'              => $version,
            'previous_template_id' => $previousId,
            'active'               => $data['active'] ? 1 : 0,
            'created_by'           => $employeeId,
        ]);

        $templateId = (int) $this->db->insertID();

        $this->replaceUnits($templateId, $data['units']);

        return $templateId;
    }

    /**
     * @param list<array{name: string, factor: string, small_name: string}> $units
     */
    private function replaceUnits(int $templateId, array $units): void
    {
        $this->db->table('unit_template_units')->where('template_id', $templateId)->delete();

        foreach (array_values($units) as $index => $unit) {
            $this->db->table('unit_template_units')->insert(['template_id' => $templateId, 'name' => $unit['name'], 'factor' => $unit['factor'], 'small_name' => $unit['small_name'], 'position' => $index + 1]);
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, main_unit_name: string, main_small_name: string, active: bool, units: list<array{name: string, factor: string, small_name: string}>}
     *
     * @throws AccountingException
     */
    private function checked(array $input): array
    {
        $name  = $this->text($input['name'] ?? '', self::NAME_MAX, 'name');
        $main  = $this->text($input['main_unit_name'] ?? '', self::UNIT_MAX, 'main_unit_name');
        $small = $this->text($input['main_small_name'] ?? '', self::SMALL_MAX, 'main_small_name');
        $rows  = $input['units'] ?? [];

        if (! is_array($rows) || $rows === [] || count($rows) > self::UNITS_MAX) {
            throw new AccountingException(AccountingError::UnitTemplateInvalid, 'A template has from one to twenty larger units.', ['name' => 'units']);
        }

        $units = [];
        $names = [mb_strtolower($main)];

        foreach (array_values($rows) as $row) {
            $unitName  = $this->text(is_array($row) ? ($row['name'] ?? '') : '', self::UNIT_MAX, 'unit name');
            $unitSmall = $this->text(is_array($row) ? ($row['small_name'] ?? '') : '', self::SMALL_MAX, 'unit small name');
            $factor    = is_array($row) ? trim((string) ($row['factor'] ?? '')) : '';

            if (preg_match(self::FACTOR, $factor) !== 1 || bccomp($factor, '0', UnitLine::FACTOR_SCALE) <= 0) {
                throw new AccountingException(AccountingError::UnitFactorInvalid, 'The factor must be above zero with at most four decimals.', ['name' => $unitName]);
            }

            if (in_array(mb_strtolower($unitName), $names, true)) {
                throw new AccountingException(AccountingError::UnitTemplateInvalid, 'A unit name is repeated.', ['name' => $unitName]);
            }

            $names[] = mb_strtolower($unitName);
            $units[] = ['name' => $unitName, 'factor' => bcadd($factor, '0', UnitLine::FACTOR_SCALE), 'small_name' => $unitSmall];
        }

        return ['name' => $name, 'main_unit_name' => $main, 'main_small_name' => $small, 'active' => (bool) ($input['active'] ?? true), 'units' => $units];
    }

    /**
     * @throws AccountingException
     */
    private function text(mixed $value, int $max, string $field): string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        if ($text === '' || mb_strlen($text) > $max) {
            throw new AccountingException(AccountingError::UnitTemplateInvalid, "The {$field} is empty or too long.", ['name' => $field]);
        }

        return $text;
    }

    /**
     * @return array{main: string, units: list<array{string, string, string}>}
     */
    private function shapeOf(?UnitTemplate $template): array
    {
        return [
            'main'  => ($template?->mainUnitName ?? '') . '|' . ($template?->mainSmallName ?? ''),
            'units' => array_map(static fn (TemplateUnit $unit): array => [$unit->name, $unit->factor, $unit->smallName], $template?->units ?? []),
        ];
    }

    /**
     * @param array{main_unit_name: string, main_small_name: string, units: list<array{name: string, factor: string, small_name: string}>} $data
     *
     * @return array{main: string, units: list<array{string, string, string}>}
     */
    private function shapeOfInput(array $data): array
    {
        return [
            'main'  => $data['main_unit_name'] . '|' . $data['main_small_name'],
            'units' => array_map(static fn (array $unit): array => [$unit['name'], $unit['factor'], $unit['small_name']], $data['units']),
        ];
    }

    /**
     * @param array{name: string, main_unit_name: string, main_small_name: string, units: list<array{name: string, factor: string, small_name: string}>} $data
     *
     * @return array<string, mixed>
     */
    private function details(array $data): array
    {
        return ['name' => $data['name'], 'main_unit_name' => $data['main_unit_name'], 'units' => $data['units']];
    }
}
