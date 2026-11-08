<?php

namespace App\Libraries\Documents;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\CompanyContext;
use App\Models\Employee;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The extra fields of products and invoices: the definitions an employee with the permission manages, the
 * value each product or invoice holds, and the values that print on a document. A value is kept apart
 * from everything the books read: nothing here reads or writes a journal, a line of a sale or an amount,
 * so a document with values and the same document without them post and add up alike. A field that is put
 * out of use is kept with its values and is no longer asked for, checked or printed.
 */
final class CustomFields
{
    public const PERMISSION      = 'acc_custom_fields_manage';
    public const ENTITIES        = ['item', 'sale'];
    public const MAX_PER_ENTITY  = 30;
    public const MAX_NAME        = 100;
    public const MAX_OPTIONS     = 50;
    public const MAX_OPTION_SIZE = 100;

    private BaseConnection $db;
    private Employee $employee;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?Employee $employee = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->employee = $employee ?? model(Employee::class);
        $this->audit    = new AuditLog($this->db);
    }

    /**
     * @return list<CustomFieldDefinition> In the order they are asked for.
     */
    public function definitions(string $entity, bool $activeOnly = true): array
    {
        if (! in_array($entity, self::ENTITIES, true)) {
            return [];
        }

        $builder = $this->db->table('custom_fields')->where(['company_id' => CompanyContext::id(), 'entity' => $entity])->orderBy('sort_order', 'ASC')->orderBy('field_id', 'ASC');

        if ($activeOnly) {
            $builder->where('active', 1);
        }

        return array_map($this->definitionOf(...), $builder->get()->getResult());
    }

    public function find(int $fieldId): ?CustomFieldDefinition
    {
        $row = $this->db->table('custom_fields')->where(['company_id' => CompanyContext::id(), 'field_id' => $fieldId])->get()->getRow();

        return $row === null ? null : $this->definitionOf($row);
    }

    /**
     * @param array{name?: mixed, type?: mixed, options?: mixed, required?: mixed, printable?: mixed} $input
     *
     * @throws DocumentException
     */
    public function create(string $entity, array $input, ?int $employeeId): int
    {
        $this->allow($employeeId);

        if (! in_array($entity, self::ENTITIES, true)) {
            throw new DocumentException(DocumentError::CustomFieldEntity, context: ['entity' => $entity]);
        }

        $type = CustomFieldType::tryFrom((string) ($input['type'] ?? '')) ?? throw new DocumentException(DocumentError::CustomFieldType);
        $name = $this->cleanName($entity, $input['name'] ?? null, null);

        if (count($this->definitions($entity, false)) >= self::MAX_PER_ENTITY) {
            throw new DocumentException(DocumentError::CustomFieldLimit, context: ['limit' => self::MAX_PER_ENTITY]);
        }

        $options = $this->cleanOptions($type, $input['options'] ?? null);
        $last    = $this->db->table('custom_fields')->selectMax('sort_order', 'last')->where(['company_id' => CompanyContext::id(), 'entity' => $entity])->get()->getRow();

        $this->db->table('custom_fields')->insert([
            'company_id' => CompanyContext::id(),
            'entity'     => $entity,
            'name'       => $name,
            'field_type' => $type->value,
            'options'    => $options === [] ? null : implode("\n", $options),
            'required'   => $this->flag($input['required'] ?? null),
            'printable'  => $this->flag($input['printable'] ?? null),
            'sort_order' => (int) ($last->last ?? 0) + 1,
            'active'     => 1,
            'created_by' => $employeeId,
        ]);

        $fieldId = (int) $this->db->insertID();

        $this->audit->write('custom_field', $fieldId, 'custom_field_created', $employeeId, JournalOrigin::User, ['entity' => $entity, 'name' => $name, 'type' => $type->value]);

        return $fieldId;
    }

    /**
     * @param array{name?: mixed, type?: mixed, options?: mixed, required?: mixed, printable?: mixed, sort_order?: mixed} $input
     *
     * @throws DocumentException
     */
    public function update(int $fieldId, array $input, ?int $employeeId): void
    {
        $this->allow($employeeId);

        $current = $this->find($fieldId) ?? throw new DocumentException(DocumentError::CustomFieldNotFound, context: ['field' => $fieldId]);
        $type    = CustomFieldType::tryFrom((string) ($input['type'] ?? $current->type->value)) ?? throw new DocumentException(DocumentError::CustomFieldType);

        if ($type !== $current->type && $this->hasValues($fieldId)) {
            throw new DocumentException(DocumentError::CustomFieldTypeLocked, context: ['field' => $fieldId]);
        }

        $name    = $this->cleanName($current->entity, $input['name'] ?? $current->name, $fieldId);
        $options = $this->cleanOptions($type, $input['options'] ?? $current->options);
        $changes = [
            'name'       => $name,
            'field_type' => $type->value,
            'options'    => $options === [] ? null : implode("\n", $options),
            'required'   => $this->flag($input['required'] ?? $current->required),
            'printable'  => $this->flag($input['printable'] ?? $current->printable),
            'sort_order' => isset($input['sort_order']) ? (int) $input['sort_order'] : $current->sortOrder,
        ];

        $before = ['name' => $current->name, 'type' => $current->type->value, 'options' => $current->options, 'required' => $current->required, 'printable' => $current->printable, 'sort_order' => $current->sortOrder];
        $after  = ['name' => $name, 'type' => $type->value, 'options' => $options, 'required' => (bool) $changes['required'], 'printable' => (bool) $changes['printable'], 'sort_order' => $changes['sort_order']];

        if ($before === $after) {
            return;
        }

        $this->db->table('custom_fields')->where(['company_id' => CompanyContext::id(), 'field_id' => $fieldId])->update($changes);
        $this->audit->write('custom_field', $fieldId, 'custom_field_updated', $employeeId, JournalOrigin::User, ['entity' => $current->entity, 'before' => $before, 'after' => $after]);
    }

    /**
     * @throws DocumentException
     */
    public function setActive(int $fieldId, bool $active, ?int $employeeId): void
    {
        $this->allow($employeeId);

        $current = $this->find($fieldId) ?? throw new DocumentException(DocumentError::CustomFieldNotFound, context: ['field' => $fieldId]);

        if ($current->active === $active) {
            return;
        }

        $this->db->table('custom_fields')->where(['company_id' => CompanyContext::id(), 'field_id' => $fieldId])->update(['active' => $active ? 1 : 0]);
        $this->audit->write('custom_field', $fieldId, $active ? 'custom_field_activated' : 'custom_field_deactivated', $employeeId, JournalOrigin::User, ['entity' => $current->entity, 'name' => $current->name]);
    }

    /**
     * @return array<int, string> The stored value of each field of the product or the invoice, by field.
     */
    public function valuesOf(string $entity, int $entityId): array
    {
        return $this->valuesOfMany($entity, [$entityId])[$entityId] ?? [];
    }

    /**
     * What an employee typed for the fields of a product or an invoice, worked into what is stored: a
     * value for every active field that has one. A value that does not fit its field, or no value for a
     * field that is required, is refused naming the field.
     *
     * @param array<int|string, mixed> $posted        The raw values by field.
     * @param bool                     $requireValues Whether a required field must have a value, which a sale that is only set aside need not.
     *
     * @return array<int, string>
     *
     * @throws DocumentException
     */
    public function check(string $entity, array $posted, bool $requireValues = true): array
    {
        $values = [];

        foreach ($this->definitions($entity) as $definition) {
            $raw = $posted[$definition->id] ?? '';
            $raw = is_string($raw) ? trim($raw) : (is_scalar($raw) ? trim((string) $raw) : null);

            if ($raw === null) {
                throw new DocumentException(DocumentError::CustomFieldValue, context: ['field' => $definition->name]);
            }

            if ($raw === '' || ($definition->type === CustomFieldType::Checkbox && in_array($raw, ['0', 'off', 'false'], true))) {
                if ($definition->required && $requireValues) {
                    throw new DocumentException(DocumentError::CustomFieldRequired, context: ['field' => $definition->name]);
                }

                continue;
            }

            $values[$definition->id] = $definition->type->normalize($raw, $definition->options) ?? throw new DocumentException(DocumentError::CustomFieldValue, context: ['field' => $definition->name]);
        }

        return $values;
    }

    /**
     * What is wrong with the values typed, in words, or null when they are right.
     *
     * @param array<int|string, mixed> $posted
     */
    public function problem(string $entity, array $posted, bool $requireValues = true): ?string
    {
        try {
            $this->check($entity, $posted, $requireValues);
        } catch (DocumentException $exception) {
            return lang('Documents.error_' . $exception->error->value) . (isset($exception->context['field']) ? ' (' . $exception->context['field'] . ')' : '');
        }

        return null;
    }

    /**
     * Keeps the values typed for a product or an invoice, and takes away the value of a field that was left
     * empty. Nothing is kept unless every value is right.
     *
     * @param array<int|string, mixed> $posted
     *
     * @throws DocumentException
     */
    public function save(string $entity, int $entityId, array $posted, bool $requireValues = true): void
    {
        $values = $this->check($entity, $posted, $requireValues);

        $this->db->transStart();

        foreach ($this->definitions($entity) as $definition) {
            if (isset($values[$definition->id])) {
                $this->db->query(
                    'INSERT INTO ' . $this->db->prefixTable('custom_field_values') . ' (field_id, entity_id, field_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE field_value = VALUES(field_value)',
                    [$definition->id, $entityId, $values[$definition->id]],
                );
            } else {
                $this->db->table('custom_field_values')->where(['field_id' => $definition->id, 'entity_id' => $entityId])->delete();
            }
        }

        $this->db->transComplete();
    }

    /**
     * The values of a product or an invoice that print on its documents, as a name and a value each: the
     * fields that are on and marked to print, and have a value.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function printable(string $entity, int $entityId): array
    {
        return $this->printableFor($entity, [$entityId])[$entityId] ?? [];
    }

    /**
     * @param list<int> $entityIds
     *
     * @return array<int, list<array{0: string, 1: string}>>
     */
    public function printableFor(string $entity, array $entityIds): array
    {
        $printing = array_filter($this->definitions($entity), static fn (CustomFieldDefinition $definition): bool => $definition->printable);

        if ($printing === []) {
            return [];
        }

        $result = [];

        foreach ($this->valuesOfMany($entity, $entityIds) as $entityId => $values) {
            foreach ($printing as $definition) {
                if (isset($values[$definition->id])) {
                    $result[$entityId][] = [$definition->name, $this->shown($definition, $values[$definition->id])];
                }
            }
        }

        return $result;
    }

    /**
     * @param list<int> $entityIds
     *
     * @return array<int, array<int, string>> The stored values by entity and field.
     */
    private function valuesOfMany(string $entity, array $entityIds): array
    {
        $entityIds = array_values(array_unique(array_filter(array_map('intval', $entityIds), static fn (int $id): bool => $id > 0)));

        if ($entityIds === [] || ! in_array($entity, self::ENTITIES, true)) {
            return [];
        }

        $rows = $this->db->table('custom_field_values v')
            ->select('v.field_id, v.entity_id, v.field_value')
            ->join('custom_fields f', 'f.field_id = v.field_id')
            ->where(['f.company_id' => CompanyContext::id(), 'f.entity' => $entity])
            ->whereIn('v.entity_id', $entityIds)
            ->get()
            ->getResult();

        $values = [];

        foreach ($rows as $row) {
            $values[(int) $row->entity_id][(int) $row->field_id] = (string) $row->field_value;
        }

        return $values;
    }

    private function shown(CustomFieldDefinition $definition, string $value): string
    {
        return match ($definition->type) {
            CustomFieldType::Checkbox => lang('Common.yes'),
            CustomFieldType::Date     => to_date((int) strtotime($value)),
            default                   => $value,
        };
    }

    private function hasValues(int $fieldId): bool
    {
        return $this->db->table('custom_field_values')->where('field_id', $fieldId)->countAllResults() > 0;
    }

    private function definitionOf(object $row): CustomFieldDefinition
    {
        return new CustomFieldDefinition(
            (int) $row->field_id,
            (string) $row->entity,
            (string) $row->name,
            CustomFieldType::from((string) $row->field_type),
            $row->options === null || $row->options === '' ? [] : explode("\n", (string) $row->options),
            (int) $row->required === 1,
            (int) $row->printable === 1,
            (int) $row->sort_order,
            (int) $row->active === 1,
        );
    }

    /**
     * @throws DocumentException
     */
    private function allow(?int $employeeId): void
    {
        if ($employeeId !== null && ! $this->employee->has_grant(self::PERMISSION, $employeeId)) {
            throw new DocumentException(DocumentError::PermissionDenied, context: ['permission' => self::PERMISSION]);
        }
    }

    /**
     * @throws DocumentException
     */
    private function cleanName(string $entity, mixed $name, ?int $exceptFieldId): string
    {
        $name = is_string($name) ? trim(preg_replace('/\s+/u', ' ', $name) ?? '') : '';

        if ($name === '' || mb_strlen($name) > self::MAX_NAME || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            throw new DocumentException(DocumentError::CustomFieldName);
        }

        $builder = $this->db->table('custom_fields')->where(['company_id' => CompanyContext::id(), 'entity' => $entity, 'name' => $name]);

        if ($exceptFieldId !== null) {
            $builder->where('field_id !=', $exceptFieldId);
        }

        if ($builder->countAllResults() > 0) {
            throw new DocumentException(DocumentError::CustomFieldNameTaken, context: ['name' => $name]);
        }

        return $name;
    }

    /**
     * @return list<string>
     *
     * @throws DocumentException
     */
    private function cleanOptions(CustomFieldType $type, mixed $options): array
    {
        if (! $type->hasOptions()) {
            return [];
        }

        $lines = is_array($options) ? $options : explode("\n", is_string($options) ? str_replace("\r\n", "\n", $options) : '');
        $clean = [];

        foreach ($lines as $line) {
            $line = is_string($line) ? trim($line) : '';

            if ($line === '') {
                continue;
            }

            if (mb_strlen($line) > self::MAX_OPTION_SIZE || preg_match('/[\x00-\x1F\x7F]/', $line) === 1 || in_array($line, $clean, true)) {
                throw new DocumentException(DocumentError::CustomFieldOptions);
            }

            $clean[] = $line;
        }

        if ($clean === [] || count($clean) > self::MAX_OPTIONS) {
            throw new DocumentException(DocumentError::CustomFieldOptions);
        }

        return $clean;
    }

    private function flag(mixed $value): int
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'on' ? 1 : 0;
    }
}
