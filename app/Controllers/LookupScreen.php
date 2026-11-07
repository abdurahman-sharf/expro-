<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\MasterData\LookupKind;
use App\Libraries\MasterData\NamedLookup;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The screen of a short list of names (the classifications of customers, the brands of products): a table
 * with search, a form to add or change a name and its status, and the removal of a name nothing carries.
 * The screen belongs to the module of the records that carry the names, so whoever may edit those records
 * may keep the list.
 */
abstract class LookupScreen extends Secure_Controller
{
    private const DEFAULT_PAGE_SIZE = 25;
    private const MAX_PAGE_SIZE     = 500;

    protected NamedLookup $lookup;

    public function __construct(string $module, private readonly string $resource, private readonly LookupKind $kind)
    {
        parent::__construct($module);

        $this->lookup = new NamedLookup($kind);
    }

    public function getIndex(): string
    {
        return view('lookups/manage', [
            'resource'     => $this->resource,
            'kind'         => $this->kind->value,
            'tableHeaders' => transform_headers($this->headers(), false, true),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, self::MAX_PAGE_SIZE));
        $result = $this->lookup->search(trim((string) $this->request->getGet('search')), (string) $this->request->getGet('sort'), (string) $this->request->getGet('order'), $limit, max(0, (int) $this->request->getGet('offset')));

        return $this->response->setJSON(['total' => $result['total'], 'rows' => array_map(fn (object $entry): array => $this->row($entry), $result['rows'])]);
    }

    public function getRow(int $id): ResponseInterface
    {
        return $this->response->setJSON($this->row($this->entry($id)));
    }

    public function getView(int $id = NEW_ENTRY): string
    {
        return view('lookups/form', ['resource' => $this->resource, 'kind' => $this->kind->value, 'entry' => $id === NEW_ENTRY ? null : $this->entry($id)]);
    }

    public function postSave(int $id = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($id): array {
            if ($id !== NEW_ENTRY) {
                $this->entry($id);
            }

            $saved = $this->lookup->save((string) $this->request->getPost('name'), (string) $this->request->getPost('status', FILTER_SANITIZE_NUMBER_INT) !== '0', $id === NEW_ENTRY ? null : $id, $this->employeeId());

            return ['success' => true, 'message' => lang($id === NEW_ENTRY ? 'Lookups.successful_adding' : 'Lookups.successful_updating'), 'id' => $saved];
        });
    }

    public function postStatus(int $id, int $active): ResponseInterface
    {
        return $this->attempt(function () use ($id, $active): array {
            $this->entry($id);
            $this->lookup->setStatus($id, $active === 1, $this->employeeId());

            return ['success' => true, 'message' => lang($active === 1 ? 'Lookups.successful_activated' : 'Lookups.successful_deactivated'), 'id' => $id];
        });
    }

    public function postDelete(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $ids = array_map('intval', (array) $this->request->getPost('ids'));

            $this->lookup->delete($ids, $this->employeeId());

            return ['success' => true, 'message' => lang('Lookups.successful_deleted', [count($ids)]), 'ids' => $ids];
        });
    }

    protected function employeeId(): int
    {
        return (int) $this->global_view_data['user_info']->person_id;
    }

    /**
     * @param callable(): array<string, mixed> $action Returns the JSON body of a successful call.
     */
    private function attempt(callable $action): ResponseInterface
    {
        try {
            return $this->response->setJSON($action());
        } catch (AccountingException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => AccountingMessage::of($exception), 'id' => NEW_ENTRY]);
        }
    }

    private function entry(int $id): object
    {
        $entry = $this->lookup->get($id);

        if ($entry === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $entry;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            [$this->kind->idColumn() => lang('Lookups.id'), 'sortable' => true],
            ['name'   => lang('Lookups.name'), 'sortable' => true],
            ['status' => lang('Lookups.status'), 'sortable' => true, 'escape' => false],
            ['uses'   => lang('Lookups.' . $this->kind->value . '_uses'), 'sortable' => true],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(object $entry): array
    {
        $id     = (int) $entry->{$this->kind->idColumn()};
        $active = (int) $entry->status === 1;
        $toggle = '<button type="button" class="btn btn-link btn-sm p-0 lookup-status" data-id="' . $id . '" data-active="' . ($active ? 0 : 1) . '" title="' . esc(lang($active ? 'Lookups.deactivate' : 'Lookups.activate'), 'attr') . '"><span class="bi ' . ($active ? 'bi-toggle-on' : 'bi-toggle-off') . '" aria-hidden="true"></span></button>';

        return [
            $this->kind->idColumn() => $id,
            'name'                  => $entry->name,
            'status'                => '<span class="badge ' . ($active ? 'text-bg-success' : 'text-bg-secondary') . '">' . esc(lang($active ? 'Lookups.active' : 'Lookups.inactive')) . '</span> ' . $toggle,
            'uses'                  => (int) $entry->uses,
            'edit'                  => anchor($this->resource . "/view/{$id}", '<span class="bi bi-pencil-square" aria-hidden="true"></span>', ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Lookups.update')]),
        ];
    }
}
