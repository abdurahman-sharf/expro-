<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\OpeningImport\OpeningImport;
use App\Libraries\Accounting\OpeningImport\OpeningImportFiles;
use App\Libraries\Accounting\OpeningImport\OpeningImportKind;
use App\Libraries\Accounting\OpeningImport\OpeningImportParser;
use App\Libraries\Accounting\OpeningImport\OpeningImportPlan;
use App\Libraries\Accounting\OpeningImport\OpeningImportRow;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

class AccountingOpeningImport extends AccountingController
{
    private const PAGE_SIZE       = 100;
    private const APPROVE_SECONDS = 10.0;
    private const APPROVE_CHUNKS  = 40;
    private const STATES          = ['error', 'pending', 'posted'];

    private OpeningImport $import;
    private OpeningImportFiles $files;

    public function __construct()
    {
        parent::__construct('accounting_periods');

        $this->import = new OpeningImport(null, null, $this->branchGuard());
        $this->files  = new OpeningImportFiles();
    }

    public function getIndex(): string
    {
        $kinds = $this->allowedKinds();

        return view('accounting_periods/opening_import', [
            'kinds'         => $kinds,
            'customers_off' => in_array(OpeningImportKind::Customers, $kinds, true) && ! (new AccountingSettings())->customerOpeningEnabled(),
            'start_date'    => $this->formatDate((new AccountingSettings())->startDate()),
            'notice'        => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function postUpload(): ResponseInterface
    {
        $kind = $this->kindOf((string) $this->request->getPost('kind'));
        $file = $this->request->getFile('file');

        return $this->attempt(function () use ($kind, $file): array {
            if ($file === null || $file->getError() !== UPLOAD_ERR_OK || $file->getSize() === 0) {
                return ['success' => false, 'message' => lang('Accounting_opening_import.file_required'), 'id' => NEW_ENTRY];
            }

            if ($file->getSize() > OpeningImportParser::MAX_BYTES) {
                return ['success' => false, 'message' => lang('Accounting.error_opening_import_file_too_big', [(string) OpeningImportParser::MAX_ROWS]), 'id' => NEW_ENTRY];
            }

            $text = (string) file_get_contents($file->getTempName());

            $this->import->plan($kind, $text, $this->employeeId());

            $token = $this->files->store($text);

            return ['success' => true, 'message' => lang('Accounting_opening_import.preview_title'), 'id' => 1, 'redirect' => $this->previewUrl($kind, $token)];
        });
    }

    public function getSample(string $kindName): ResponseInterface
    {
        $kind = $this->kindOf($kindName);

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="opening-balances-' . $kind->value . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->import->sample($kind, (new AccountingSettings())->startDate() ?? date('Y-m-d')));
    }

    public function getPreview(string $kindName, string $token): ResponseInterface|string
    {
        $kind = $this->kindOf($kindName);
        $plan = $this->planOf($kind, $token);

        if (is_string($plan)) {
            session()->setFlashdata('accounting_notice', $plan);

            return redirect()->to(base_url('accounting_periods/opening_import'));
        }

        $state = $this->request->getGet('state');
        $state = is_string($state) && in_array($state, self::STATES, true) ? $state : null;
        $rows  = $state === null ? $plan->rows : array_values(array_filter($plan->rows, static fn ($row): bool => $row->state() === $state));
        $asked = $this->request->getGet('page');
        $pages = max(1, (int) ceil(count($rows) / self::PAGE_SIZE));
        $page  = is_string($asked) && ctype_digit($asked) ? min(max(1, (int) $asked), $pages) : 1;
        $slice = array_slice($rows, ($page - 1) * self::PAGE_SIZE, self::PAGE_SIZE);

        return view('accounting_periods/opening_import_preview', [
            'kind'     => $kind,
            'token'    => $token,
            'plan'     => $plan,
            'totals'   => $plan->totals(),
            'counts'   => ['rows' => count($plan->rows), 'pending' => count($plan->pending()), 'posted' => $plan->postedCount(), 'error' => count($plan->failing())],
            'rows'     => $slice,
            'accounts' => $this->accountLabels($slice),
            'state'    => $state,
            'page'     => $page,
            'pages'    => $pages,
            'urlOf'    => fn (array $query): string => $this->previewUrl($kind, $token, $query),
            'amountOf' => fn (string $amount): string => $this->amountCell($this->formatAmount($amount)),
            'notice'   => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getExport(string $kindName, string $token): ResponseInterface
    {
        $kind = $this->kindOf($kindName);
        $plan = $this->planOf($kind, $token);

        if (is_string($plan)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="opening-balances-' . $kind->value . '-rows.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->import->report($plan));
    }

    public function getApproveForm(string $kindName, string $token): string
    {
        $kind        = $this->kindOf($kindName);
        $plan        = $this->planOf($kind, $token);
        $fingerprint = $this->request->getGet('fingerprint');

        if (is_string($plan) || ! is_string($fingerprint) || preg_match('/^[0-9a-f]{64}$/', $fingerprint) !== 1 || $plan->pending() === []) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_periods/opening_import_approve_form', ['kind' => $kind, 'token' => $token, 'fingerprint' => $fingerprint, 'count' => count($plan->pending())]);
    }

    public function postApprove(): ResponseInterface
    {
        $kind = $this->kindOf((string) $this->request->getPost('kind'));

        return $this->attempt(function () use ($kind): array {
            if ($this->request->getPost('confirm') !== '1') {
                return ['success' => false, 'message' => lang('Accounting_backfill.confirm_required'), 'id' => NEW_ENTRY];
            }

            $token = (string) $this->request->getPost('token');
            $text  = $this->files->read($token);

            if ($text === null) {
                return ['success' => false, 'message' => lang('Accounting_opening_import.file_missing'), 'id' => NEW_ENTRY];
            }

            $asked  = $this->request->getPost('steps');
            $steps  = is_string($asked) && ctype_digit($asked) ? min(max(1, (int) $asked), self::APPROVE_CHUNKS) : self::APPROVE_CHUNKS;
            $result = $this->import->approve($kind, $text, (string) $this->request->getPost('fingerprint'), $this->employeeId(), $steps, self::APPROVE_SECONDS);

            if ($result->failed !== null) {
                return ['success' => false, 'message' => lang('Accounting_opening_import.approve_failed', [(string) $result->failed['line'], $result->failed['party'], $result->failed['message']]), 'id' => NEW_ENTRY];
            }

            if (! $result->isComplete()) {
                $done = $result->total - $result->remaining;

                return ['success' => true, 'continue' => true, 'message' => lang('Accounting_opening_import.approve_progress', [(string) $done, (string) $result->total]), 'id' => 1, 'done' => $done, 'total' => $result->total, 'redirect' => $this->previewUrl($kind, $token)];
            }

            $message = lang('Accounting_opening_import.approved', [(string) $result->posted, (string) $result->alreadyPosted]);

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'continue' => false, 'message' => $message, 'id' => 1, 'redirect' => $this->previewUrl($kind, $token)];
        });
    }

    /**
     * @return list<OpeningImportKind> The kinds the employee may import: the permission of the kind, and every branch.
     */
    private function allowedKinds(): array
    {
        if (! $this->branchGuard()->isUnrestricted($this->employeeId())) {
            return [];
        }

        return array_values(array_filter(OpeningImportKind::cases(), fn (OpeningImportKind $kind): bool => $this->can($kind->permission())));
    }

    private function kindOf(string $name): OpeningImportKind
    {
        $kind = OpeningImportKind::tryFrom($name);

        if ($kind === null || ! in_array($kind, $this->allowedKinds(), true)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $kind;
    }

    /**
     * @return OpeningImportPlan|string The plan of the file kept under the token, or the words for why there is none.
     */
    private function planOf(OpeningImportKind $kind, string $token): OpeningImportPlan|string
    {
        $text = $this->files->read($token);

        if ($text === null) {
            return lang('Accounting_opening_import.file_missing');
        }

        try {
            return $this->import->plan($kind, $text, $this->employeeId());
        } catch (AccountingException|BranchException $failure) {
            return AccountingMessage::of($failure);
        }
    }

    /**
     * @param list<OpeningImportRow> $rows
     *
     * @return array<int, string> The code and name of each account the journals of the rows use.
     */
    private function accountLabels(array $rows): array
    {
        $ids = [];

        foreach ($rows as $row) {
            foreach ([$row->debitAccountId, $row->creditAccountId] as $id) {
                if ($id !== null) {
                    $ids[$id] = $id;
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        $labels = [];

        foreach (Database::connect()->table('accounting_accounts')->select('account_id, code, name')->whereIn('account_id', array_values($ids))->get()->getResult() as $account) {
            $labels[(int) $account->account_id] = $account->code . ' ' . $account->name;
        }

        return $labels;
    }

    /**
     * @param array<string, scalar> $query
     */
    private function previewUrl(OpeningImportKind $kind, string $token, array $query = []): string
    {
        return base_url('accounting_periods/opening_import/preview/' . $kind->value . '/' . $token) . ($query === [] ? '' : '?' . http_build_query($query));
    }
}
