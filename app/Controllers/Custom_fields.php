<?php

namespace App\Controllers;

use App\Libraries\Documents\CustomFieldDefinition;
use App\Libraries\Documents\CustomFields;
use App\Libraries\Documents\CustomFieldType;
use App\Libraries\Documents\DocumentException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class Custom_fields extends Secure_Controller
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE     = 500;

    private CustomFields $fields;

    public function __construct()
    {
        parent::__construct('custom_fields');

        $this->fields = new CustomFields(null, $this->employee);
    }

    public function getIndex(): string
    {
        return view('custom_fields/manage', ['tableHeaders' => transform_headers($this->headers(), false, true), 'canManage' => $this->canManage()]);
    }

    public function getSearch(): ResponseInterface
    {
        $term   = mb_strtolower(trim((string) $this->request->getGet('search')));
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $rows   = [...$this->fields->definitions('item', false), ...$this->fields->definitions('sale', false)];

        if ($term !== '') {
            $rows = array_values(array_filter($rows, static fn (CustomFieldDefinition $field): bool => str_contains(mb_strtolower($field->name), $term)));
        }

        return $this->response->setJSON([
            'total' => count($rows),
            'rows'  => array_map(fn (CustomFieldDefinition $field): array => $this->row($field), array_slice($rows, $offset, $limit)),
        ]);
    }

    public function getRow(int $fieldId): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->field($fieldId)));
    }

    public function getView(int $fieldId = NEW_ENTRY): string
    {
        return view('custom_fields/form', [
            'field'     => $fieldId === NEW_ENTRY ? null : $this->field($fieldId),
            'canManage' => $this->canManage(),
            'types'     => CustomFieldType::cases(),
        ]);
    }

    public function postSave(int $fieldId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($fieldId): array {
            $input = [
                'name'      => $this->request->getPost('name'),
                'type'      => $this->request->getPost('type'),
                'options'   => $this->request->getPost('options'),
                'required'  => $this->request->getPost('required'),
                'printable' => $this->request->getPost('printable'),
            ];

            if ($fieldId === NEW_ENTRY) {
                $id = $this->fields->create((string) $this->request->getPost('entity'), $input, $this->employeeId());
            } else {
                $this->field($fieldId);
                $this->fields->update($fieldId, $input, $this->employeeId());
                $id = $fieldId;
            }

            return ['success' => true, 'message' => lang($fieldId === NEW_ENTRY ? 'Custom_fields.successful_adding' : 'Custom_fields.successful_updating'), 'id' => $id];
        });
    }

    public function postStatus(int $fieldId, int $active): ResponseInterface
    {
        return $this->attempt(function () use ($fieldId, $active): array {
            $this->field($fieldId);
            $this->fields->setActive($fieldId, $active === 1, $this->employeeId());

            return ['success' => true, 'message' => lang($active === 1 ? 'Custom_fields.successful_activated' : 'Custom_fields.successful_deactivated'), 'id' => $fieldId];
        });
    }

    private function employeeId(): int
    {
        return (int) $this->global_view_data['user_info']->person_id;
    }

    private function canManage(): bool
    {
        return $this->employee->has_grant(CustomFields::PERMISSION, $this->employeeId());
    }

    /**
     * @param callable(): array<string, mixed> $action Returns the JSON body of a successful call.
     */
    private function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (DocumentException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => lang('Documents.error_' . $exception->error->value), 'id' => NEW_ENTRY]);
        }
    }

    private function field(int $fieldId): CustomFieldDefinition
    {
        return $this->fields->find($fieldId) ?? throw PageNotFoundException::forPageNotFound();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['field_id' => lang('Custom_fields.id'), 'sortable' => false],
            ['entity'    => lang('Custom_fields.entity'), 'sortable' => false],
            ['name'      => lang('Custom_fields.name'), 'sortable' => false],
            ['type'      => lang('Custom_fields.type'), 'sortable' => false],
            ['required'  => lang('Custom_fields.required'), 'sortable' => false],
            ['printable' => lang('Custom_fields.printable'), 'sortable' => false],
            ['status'    => lang('Custom_fields.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(CustomFieldDefinition $field): array
    {
        $toggle = '<button type="button" class="btn btn-link btn-sm p-0 custom-field-status" data-id="' . $field->id . '" data-active="' . ($field->active ? 0 : 1) . '" title="' . esc(lang($field->active ? 'Custom_fields.deactivate' : 'Custom_fields.activate'), 'attr') . '"><span class="bi ' . ($field->active ? 'bi-toggle-on' : 'bi-toggle-off') . '" aria-hidden="true"></span></button>';

        return [
            'field_id'  => $field->id,
            'entity'    => lang('Custom_fields.entity_' . $field->entity),
            'name'      => $field->name,
            'type'      => lang($field->type->languageKey()),
            'required'  => lang($field->required ? 'Common.yes' : 'Common.no'),
            'printable' => lang($field->printable ? 'Common.yes' : 'Common.no'),
            'status'    => '<span class="badge ' . ($field->active ? 'text-bg-success' : 'text-bg-secondary') . '">' . esc(lang($field->active ? 'Custom_fields.active' : 'Custom_fields.inactive')) . '</span> ' . $toggle,
            'edit'      => anchor("custom_fields/view/{$field->id}", '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Custom_fields.update')]),
        ];
    }
}
