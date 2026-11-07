<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Recurring\RecurrenceTerms;
use App\Libraries\Recurring\RecurringGenerator;
use App\Libraries\Recurring\RecurringKind;
use App\Libraries\Recurring\RecurringReminders;
use App\Libraries\Recurring\RecurringSchedules;
use App\Libraries\Recurring\RunResult;
use App\Libraries\Recurring\ScheduleStatus;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Database;

abstract class RecurringScreen extends DocumentScreen
{
    private const FIELDS = [
        'name', 'period_count', 'period_unit', 'frequency', 'start_date', 'end_date', 'max_repeats', 'issue_before_days', 'comment', 'active',
        'customer_id', 'location_id', 'payment_terms_days', 'issue_mode', 'lines', 'reminders',
        'expense_category_id', 'amount', 'tax_amount', 'payment_type', 'supplier_id', 'supplier_tax_code', 'treasury_id',
    ];

    protected RecurringSchedules $schedules;

    abstract protected function kind(): RecurringKind;

    public function __construct()
    {
        parent::__construct($this->kind()->moduleId());

        $this->schedules = new RecurringSchedules(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $kind    = $this->kind();
        $filters = ['status' => (string) $this->request->getGet('status'), 'search' => trim((string) $this->request->getGet('search'))];
        $offset  = max(0, (int) $this->request->getGet('offset'));
        $result  = $this->schedules->search($kind, $filters, $this->employeeId(), self::PAGE_SIZE, $offset);

        return view('recurring/manage', [
            'kind'       => $kind,
            'rows'       => $result['rows'],
            'total'      => $result['total'],
            'offset'     => $offset,
            'page_size'  => self::PAGE_SIZE,
            'filters'    => $filters,
            'statuses'   => ScheduleStatus::cases(),
            'reminders'  => $kind === RecurringKind::Invoice ? (new RecurringReminders(null, $this->branchGuard()))->due(date('Y-m-d'), $this->employeeId()) : [],
            'can_manage' => $this->can($kind->managePermission()),
        ]);
    }

    public function getForm(int $scheduleId = NEW_ENTRY): string
    {
        $kind = $this->kind();

        if (! $this->can($kind->managePermission())) {
            throw PageNotFoundException::forPageNotFound();
        }

        $schedule = null;

        if ($scheduleId !== NEW_ENTRY) {
            $schedule = $this->schedules->find($scheduleId, $this->employeeId());

            if ($schedule === null || $schedule->status === ScheduleStatus::Ended->value) {
                throw PageNotFoundException::forPageNotFound();
            }
        }

        $lines = [];

        if ($schedule !== null && $kind === RecurringKind::Invoice) {
            $lines = array_map(static fn (object $line): array => ['item_id' => (int) $line->item_id, 'label' => (string) $line->item_name, 'quantity' => rtrim(rtrim((string) $line->quantity, '0'), '.'), 'unit_price' => (string) $line->unit_price, 'discount' => (string) $line->discount, 'discount_type' => (int) $line->discount_type, 'description' => (string) $line->description], $this->schedules->lines($scheduleId));
        }

        return view('recurring/form', [
            'kind'       => $kind,
            'schedule'   => $schedule,
            'lines'      => $lines,
            'reminders'  => $schedule === null ? [] : $this->schedules->reminders($scheduleId),
            'frequency'  => $schedule === null ? 'monthly' : (RecurrenceTerms::of($schedule)->frequency() ?? 'monthly'),
            'locations'  => $kind === RecurringKind::Invoice ? $this->locationOptions() : [],
            'categories' => $kind === RecurringKind::Expense ? $this->categoryOptions() : [],
            'payments'   => $kind === RecurringKind::Expense ? array_keys(get_payment_options()) : [],
            'suppliers'  => $kind === RecurringKind::Expense ? $this->supplierOptions() : [],
            'treasuries' => $kind === RecurringKind::Expense ? $this->treasuryOptions() : [],
            'today'      => date('Y-m-d'),
        ]);
    }

    public function getShow(int $scheduleId): string
    {
        $kind     = $this->kind();
        $schedule = $this->schedules->find($scheduleId, $this->employeeId());

        if ($schedule === null || $schedule->kind !== $kind->value) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('recurring/view', [
            'kind'       => $kind,
            'schedule'   => $schedule,
            'terms'      => RecurrenceTerms::of($schedule),
            'lines'      => $kind === RecurringKind::Invoice ? $this->schedules->lines($scheduleId) : [],
            'reminders'  => $this->schedules->reminders($scheduleId),
            'runs'       => $this->schedules->runs($scheduleId),
            'upcoming'   => $this->schedules->upcoming($schedule),
            'category'   => $kind === RecurringKind::Expense ? $this->categoryName((int) $schedule->expense_category_id) : '',
            'can_manage' => $this->can($kind->managePermission()),
        ]);
    }

    public function getCustomers(): ResponseInterface
    {
        return $this->can($this->kind()->managePermission()) ? $this->customerLookup() : $this->forbidden();
    }

    public function getItems(): ResponseInterface
    {
        return $this->can($this->kind()->managePermission()) ? $this->itemLookup() : $this->forbidden();
    }

    public function postSave(int $scheduleId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($scheduleId): array {
            $post  = $this->request->getPost();
            $input = array_intersect_key(is_array($post) ? $post : [], array_flip(self::FIELDS));
            $kind  = $this->kind();

            if ($scheduleId === NEW_ENTRY) {
                $scheduleId = $this->schedules->create($kind, $input, $this->employeeId());
                $message    = lang('Recurring.successful_adding');
            } else {
                $this->assertKind($scheduleId);
                $this->schedules->update($scheduleId, $input, $this->employeeId());
                $message = lang('Recurring.successful_updating');
            }

            return ['success' => true, 'message' => $message, 'id' => $scheduleId, 'redirect' => site_url($kind->moduleId() . '/view/' . $scheduleId)];
        });
    }

    public function postStatus(int $scheduleId): ResponseInterface
    {
        return $this->attempt(function () use ($scheduleId): array {
            $this->assertKind($scheduleId);

            $to = ScheduleStatus::tryFrom($this->postText('status'));

            if ($to === null) {
                throw new AccountingException(AccountingError::RecurringStatusNotAllowed, 'The status is not one of the three.', ['from' => '', 'to' => $this->postText('status')]);
            }

            $this->schedules->changeStatus($scheduleId, $to, $this->employeeId());

            return ['success' => true, 'message' => lang('Recurring.status_changed'), 'id' => $scheduleId];
        });
    }

    public function postRetry(int $scheduleId, int $runId): ResponseInterface
    {
        return $this->attempt(function () use ($scheduleId, $runId): array {
            $this->assertKind($scheduleId);

            $result = (new RecurringGenerator(null, null))->retry($scheduleId, $runId, $this->employeeId());

            if ($result->outcome !== RunResult::GENERATED) {
                return ['success' => false, 'message' => $result->refusal?->words() ?? lang('Recurring.retry_failed'), 'id' => $scheduleId];
            }

            return ['success' => true, 'message' => lang('Recurring.retry_made'), 'id' => $scheduleId];
        });
    }

    /**
     * A schedule is reached through the module of its own kind and no other.
     *
     * @throws AccountingException
     */
    private function assertKind(int $scheduleId): void
    {
        if ($this->schedules->kindOf($scheduleId) !== $this->kind()) {
            throw new AccountingException(AccountingError::RecurringNotFound, 'There is no such schedule.', ['name' => (string) $scheduleId]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function categoryOptions(): array
    {
        $options = [];

        foreach (Database::connect()->table('expense_categories')->select('expense_category_id, category_name')->where('deleted', 0)->orderBy('category_name', 'ASC')->get()->getResult() as $row) {
            $options[(int) $row->expense_category_id] = (string) $row->category_name;
        }

        return $options;
    }

    private function categoryName(int $categoryId): string
    {
        $row = Database::connect()->table('expense_categories')->select('category_name')->where('expense_category_id', $categoryId)->get()->getRow();

        return $row === null ? '' : (string) $row->category_name;
    }

    /**
     * @return array<int, string>
     */
    private function supplierOptions(): array
    {
        $options = [];

        foreach (Database::connect()->table('suppliers s')->select("s.person_id, s.company_name, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)->join('people p', 'p.person_id = s.person_id')->where('s.deleted', 0)->orderBy('s.company_name', 'ASC')->limit(200)->get()->getResult() as $row) {
            $options[(int) $row->person_id] = (string) ($row->company_name !== '' ? $row->company_name : $row->name);
        }

        return $options;
    }

    /**
     * @return array<int, string>
     */
    private function treasuryOptions(): array
    {
        if (! (new AccountingSettings())->isEnabled()) {
            return [];
        }

        $options = [];

        foreach ((new TreasuryService())->all($this->branchGuard()->allowedBranchIds($this->employeeId()), false) as $treasury) {
            $options[(int) $treasury->treasury_id] = $treasury->name . ' (' . $treasury->branch_code . ')';
        }

        return $options;
    }
}
