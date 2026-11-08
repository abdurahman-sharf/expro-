<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Ai\AiSettings;
use App\Libraries\Accounting\Ai\AnomalyScanner;
use App\Libraries\Accounting\Ai\EvidenceRows;
use App\Libraries\Accounting\Ai\FindingService;
use App\Libraries\Accounting\Ai\ProposalRunner;
use App\Libraries\Accounting\Ai\ProposalService;
use App\Libraries\Accounting\Ai\ProposalStatus;
use App\Libraries\Accounting\ExportsCsv;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The screens of the agent layer: the proposals the local rules made, to read, approve and reject, the findings
 * of the scan, to filter, decide and take as a file, and the master switch with its thresholds. The agent only
 * proposes; a person who holds the permission to approve is the one whose approval posts. A person limited to some
 * branches sees and decides only what belongs to them. Every change is a POST.
 */
class AccountingAi extends AccountingController
{
    use ExportsCsv;

    private const PAGE_SIZE = 25;

    private ProposalService $proposals;
    private FindingService $findings;
    private AiSettings $settings;

    public function __construct()
    {
        parent::__construct('accounting_ai');

        $this->settings  = new AiSettings();
        $this->proposals = new ProposalService(null, null, null, $this->branchGuard(), $this->settings);
        $this->findings  = new FindingService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $filters = $this->proposalFilters();
        $offset  = max(0, (int) $this->request->getGet('offset'));
        $result  = $this->proposals->search($filters, $this->employeeId(), self::PAGE_SIZE, $offset);

        return view('accounting_ai/proposals', [
            'rows'        => $result['rows'],
            'total'       => $result['total'],
            'offset'      => $offset,
            'page_size'   => self::PAGE_SIZE,
            'filters'     => $filters,
            'kinds'       => $this->proposals->kinds(),
            'counts'      => $this->proposals->counts($this->employeeId()),
            'enabled'     => $this->settings->isEnabled(),
            'scopeNotice' => $this->scopeNoticeNeeded(),
            'notice'      => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getProposal(int $proposalId): string
    {
        $proposal = $this->visibleProposal($proposalId);
        $enabled  = $this->settings->isEnabled();
        $standing = $proposal->status === ProposalStatus::Proposed->value && (int) $proposal->is_expired === 0;

        return view('accounting_ai/proposal', [
            'proposal'        => $proposal,
            'enabled'         => $enabled,
            'canApprove'      => $standing && $this->can(AccountingGuard::AI_APPROVE) && $this->can(AccountingGuard::JOURNALS_POST) && $enabled,
            'canReject'       => $standing && $this->can(AccountingGuard::AI_APPROVE),
            'canViewJournals' => $this->employee->has_module_grant('accounting_journals', $this->employeeId()),
            'notice'          => session()->getFlashdata('accounting_notice'),
            'date'            => fn (?string $value): string => $this->formatDate($value),
            'dateTime'        => fn (?string $value): string => $this->formatDateTime($value),
            'amount'          => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
        ]);
    }

    public function getApproveForm(int $proposalId): string
    {
        $proposal = $this->decidableProposal($proposalId);

        return view('accounting_ai/approve_form', ['proposal' => $proposal]);
    }

    public function postApprove(int $proposalId): ResponseInterface
    {
        return $this->attempt(function () use ($proposalId): array {
            if ($this->request->getPost('confirm') !== '1') {
                return ['success' => false, 'message' => lang('Accounting_ai.confirm_required'), 'id' => NEW_ENTRY];
            }

            $journalId = $this->proposals->approve($proposalId, $this->employeeId());
            $message   = lang('Accounting_ai.approved');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $proposalId, 'journal_id' => $journalId, 'redirect' => base_url('accounting_ai/proposal/' . $proposalId)];
        });
    }

    public function getRejectForm(int $proposalId): string
    {
        $proposal = $this->decidableProposal($proposalId);

        return view('accounting_ai/reject_form', ['proposal' => $proposal]);
    }

    public function postReject(int $proposalId): ResponseInterface
    {
        return $this->attempt(function () use ($proposalId): array {
            $this->proposals->reject($proposalId, $this->postedText('reason'), $this->employeeId());

            $message = lang('Accounting_ai.rejected');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $proposalId, 'redirect' => base_url('accounting_ai/proposal/' . $proposalId)];
        });
    }

    public function postPropose(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $report  = (new ProposalRunner(null, null, $this->branchGuard()))->run($this->employeeId());
            $message = lang('Accounting_ai.run_done', [$report->createdCount(), $report->duplicateCount(), count($report->refused), $report->expired]);

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => NEW_ENTRY, 'redirect' => base_url('accounting_ai')];
        });
    }

    public function postScan(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $report  = (new AnomalyScanner(null, null, $this->branchGuard()))->scan($this->employeeId());
            $message = lang('Accounting_ai.scan_done', [$report->createdCount(), $report->existingCount()]);

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => NEW_ENTRY, 'redirect' => base_url('accounting_ai/findings')];
        });
    }

    public function getFindings(): string
    {
        $filters = $this->findingFilters();
        $offset  = max(0, (int) $this->request->getGet('offset'));
        $result  = $this->findings->search($filters, $this->employeeId(), self::PAGE_SIZE, $offset);

        return view('accounting_ai/findings', [
            'rows'            => $result['rows'],
            'total'           => $result['total'],
            'offset'          => $offset,
            'page_size'       => self::PAGE_SIZE,
            'filters'         => $filters,
            'rules'           => $this->findings->rules(),
            'counts'          => $this->findings->counts($this->employeeId()),
            'enabled'         => $this->settings->isEnabled(),
            'canViewJournals' => $this->employee->has_module_grant('accounting_journals', $this->employeeId()),
            'csvUrl'          => base_url('accounting_ai/findings/csv') . $this->findingQuery($filters),
            'scopeNotice'     => $this->scopeNoticeNeeded(),
            'notice'          => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function getFindingsCsv(): ResponseInterface
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [
            lang('Accounting_ai.finding_id'),
            lang('Accounting_ai.rule'),
            lang('Accounting_ai.severity'),
            lang('Accounting_ai.status'),
            lang('Accounting_ai.branch'),
            lang('Accounting_ai.occurred_on'),
            lang('Accounting_ai.message'),
            lang('Accounting_ai.evidence'),
            lang('Accounting_ai.decided_by'),
            lang('Accounting_ai.decided_at'),
            lang('Accounting_ai.decision_reason'),
            lang('Accounting_ai.detected_at'),
        ]);

        foreach ($this->findings->stream($this->findingFilters(), $this->employeeId()) as $finding) {
            $this->csvRow($handle, [
                (string) $finding->finding_id,
                (string) $finding->rule_code,
                lang('Accounting_ai.severity_' . $finding->severity),
                lang('Accounting_ai.finding_status_' . $finding->status),
                (string) $finding->branch_code,
                (string) $finding->occurred_on,
                (string) $finding->message,
                EvidenceRows::text($finding->evidence_rows),
                (string) $finding->decider_name,
                (string) $finding->decided_at,
                (string) $finding->decision_reason,
                (string) $finding->detected_at,
            ]);
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="ai_findings_' . date('Y-m-d') . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->csvText($handle));
    }

    public function getDecideForm(int $findingId): string
    {
        try {
            $finding = $this->findings->find($findingId, $this->employeeId());
        } catch (AccountingException) {
            throw PageNotFoundException::forPageNotFound();
        }

        if ($finding->status !== FindingService::OPEN) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_ai/decide_form', ['finding' => $finding]);
    }

    public function postDecide(int $findingId): ResponseInterface
    {
        return $this->attempt(function () use ($findingId): array {
            $this->findings->decide($findingId, $this->postedText('decision'), $this->postedText('reason'), $this->employeeId());

            $message = lang('Accounting_ai.decided');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => $findingId, 'redirect' => base_url('accounting_ai/findings')];
        });
    }

    public function getSettings(): string
    {
        $canManage = $this->can(AccountingGuard::SETTINGS_MANAGE);

        return view('accounting_ai/settings', [
            'enabled'    => $this->settings->isSwitchedOn(),
            'canManage'  => $canManage,
            'thresholds' => $this->settings->thresholds(),
            'defaults'   => AiSettings::THRESHOLDS,
            'notice'     => session()->getFlashdata('accounting_notice'),
        ]);
    }

    public function postSettings(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $posted = $this->request->getPost('threshold');
            $input  = [];

            foreach (is_array($posted) ? $posted : [] as $name => $value) {
                if (is_string($name) && is_string($value)) {
                    $input[$name] = $value;
                }
            }

            $this->settings->update($input, $this->employeeId());
            $this->settings->setEnabled($this->request->getPost('enabled') === '1', $this->employeeId());

            $message = lang('Accounting_ai.settings_saved');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => NEW_ENTRY, 'redirect' => base_url('accounting_ai/settings')];
        });
    }

    public function postDisable(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $this->settings->setEnabled(false, $this->employeeId());

            $message = lang('Accounting_ai.switched_off');

            session()->setFlashdata('accounting_notice', $message);

            return ['success' => true, 'message' => $message, 'id' => NEW_ENTRY, 'redirect' => base_url('accounting_ai/settings')];
        });
    }

    private function visibleProposal(int $proposalId): object
    {
        try {
            return $this->proposals->find($proposalId, $this->employeeId());
        } catch (AccountingException) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    private function decidableProposal(int $proposalId): object
    {
        $proposal = $this->visibleProposal($proposalId);

        if ($proposal->status !== ProposalStatus::Proposed->value || ! $this->can(AccountingGuard::AI_APPROVE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $proposal;
    }

    /**
     * @return array{status: string, kind: string, from: string, to: string}
     */
    private function proposalFilters(): array
    {
        $status = $this->requestText('status');

        return [
            'status' => ProposalStatus::tryFrom($status) !== null ? $status : '',
            'kind'   => preg_match('/^[a-z][a-z0-9_]{0,31}$/', $this->requestText('kind')) === 1 ? $this->requestText('kind') : '',
            'from'   => $this->dateOf('from'),
            'to'     => $this->dateOf('to'),
        ];
    }

    /**
     * @return array{status: string, rule: string, severity: string, from: string, to: string}
     */
    private function findingFilters(): array
    {
        $status   = $this->requestText('status');
        $severity = $this->requestText('severity');
        $rule     = $this->requestText('rule');

        return [
            'status'   => in_array($status, FindingService::STATUSES, true) ? $status : '',
            'rule'     => preg_match('/^[A-Z][A-Z0-9-]{0,31}$/', $rule) === 1 ? $rule : '',
            'severity' => in_array($severity, FindingService::SEVERITIES, true) ? $severity : '',
            'from'     => $this->dateOf('from'),
            'to'       => $this->dateOf('to'),
        ];
    }

    /**
     * @param array<string, string> $filters
     */
    private function findingQuery(array $filters): string
    {
        $query = http_build_query(array_filter($filters, static fn (string $value): bool => $value !== ''));

        return $query === '' ? '' : '?' . $query;
    }

    private function dateOf(string $name): string
    {
        $value = $this->requestText($name);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
    }

    private function postedText(string $name): string
    {
        $value = $this->request->getPost($name);

        return is_string($value) ? $value : '';
    }
}
