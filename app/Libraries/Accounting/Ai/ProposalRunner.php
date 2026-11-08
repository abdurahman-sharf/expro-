<?php

namespace App\Libraries\Accounting\Ai;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Runs the local proposers over the books and turns what they suggest into proposals, one by one. A
 * suggestion that was made already is counted and left alone, so the run can be repeated; one that the checks
 * of a manual journal refuse is listed with its reason and the others go on. The proposals that are past
 * their time are expired first. Nothing is posted.
 */
final class ProposalRunner
{
    private BaseConnection $db;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private AiSettings $settings;
    private ProposalService $proposals;
    private AuditLog $audit;

    /**
     * @var list<Proposer>
     */
    private array $proposers;

    /**
     * @param list<Proposer>|null $proposers
     */
    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null, ?array $proposers = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->guard     = $guard ?? new AccountingGuard();
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->settings  = new AiSettings($this->db, $this->guard);
        $this->proposals = new ProposalService($this->db, $this->guard, null, $this->branches, $this->settings);
        $this->audit     = new AuditLog($this->db);
        $this->proposers = $proposers ?? self::defaults($this->db, $this->settings);
    }

    /**
     * @return list<Proposer>
     */
    public static function defaults(?BaseConnection $db = null, ?AiSettings $settings = null): array
    {
        return [new ReclassificationProposer($db, $settings), new RecurringEntryProposer($db, $settings)];
    }

    /**
     * @throws AccountingException
     */
    public function run(?int $employeeId = null, ?DateTimeImmutable $today = null): ProposalRunReport
    {
        $this->settings->assertEnabled();
        $this->guard->require(AccountingGuard::AI_USE, $employeeId);

        $today ??= new DateTimeImmutable('today');
        $expired   = $this->proposals->expireDue();
        $branchIds = $this->branches->allowedBranchIds($employeeId);
        $created   = [];
        $duplicate = [];
        $refused   = [];

        foreach ($this->proposers as $proposer) {
            $created[$proposer->name()]   = 0;
            $duplicate[$proposer->name()] = 0;

            foreach ($proposer->propose($today, $branchIds) as $draft) {
                try {
                    $this->proposals->create($draft, $employeeId);
                    $created[$proposer->name()]++;
                } catch (AccountingException $failure) {
                    if ($failure->error === AccountingError::AiProposalDuplicate) {
                        $duplicate[$proposer->name()]++;

                        continue;
                    }

                    if ($failure->error === AccountingError::AiDisabled || $failure->error === AccountingError::PermissionDenied) {
                        throw $failure;
                    }

                    $refused[] = ['proposer' => $proposer->name(), 'key' => $draft->dedupeKey, 'reason' => AccountingMessage::of($failure)];
                } catch (BranchException|PostingException $failure) {
                    $refused[] = ['proposer' => $proposer->name(), 'key' => $draft->dedupeKey, 'reason' => AccountingMessage::of($failure)];
                }
            }
        }

        $this->audit->write('ai_run', null, 'proposers_run', $employeeId, JournalOrigin::Ai, [
            'expired'    => $expired,
            'created'    => array_sum($created),
            'duplicates' => array_sum($duplicate),
            'refused'    => count($refused),
        ]);

        return new ProposalRunReport($expired, $created, $duplicate, $refused);
    }
}
