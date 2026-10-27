<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalAttachments;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PeriodLocks;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\TrialBalance;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\JournalAttachmentFixtureTrait;

class JournalAttachmentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use JournalAttachmentFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private string $directory;
    private JournalAttachments $attachments;
    private JournalService $journals;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->directory   = sys_get_temp_dir() . '/acc_files_' . bin2hex(random_bytes(4));
        $this->attachments = new JournalAttachments(directory: $this->directory);
        $this->journals    = new JournalService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->removeAttachmentFiles($this->directory);

        foreach (glob($this->directory . '/{,.}*', GLOB_BRACE) ?: [] as $path) {
            is_file($path) && unlink($path);
        }

        is_dir($this->directory) && rmdir($this->directory);

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    private function assertPostingFailsWith(PostingError $expected, callable $action): void
    {
        try {
            $action();
        } catch (PostingException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the posting failure ' . $expected->value . '.');
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function editor(?array $branchIds = null): int
    {
        return $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_EDIT], $branchIds);
    }

    private function drafter(): int
    {
        return $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_DRAFT]);
    }

    private function automaticJournal(?int $branchId = null, string $date = '2026-10-21', int $sourceId = 1): int
    {
        return (new PostingService())->post(new JournalDraft(
            date: $date,
            lines: [
                new JournalLineDraft($this->accountId('cash'), debit: '100.00', description: 'Cash received'),
                new JournalLineDraft($this->accountId('sales_revenue'), credit: '100.00', description: 'Sales'),
            ],
            sourceType: 'sale',
            sourceId: $sourceId,
            description: 'Invoice 7',
            currencyCode: 'SAR',
            branchId: $branchId,
        ))->journalId;
    }

    private function file(string $name = 'receipt.pdf', ?string $content = null): array
    {
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return [$this->upload($content ?? $this->contentOf($extension) ?? 'x'), $name];
    }

    private function addFile(int $journalId, ?int $employeeId = null, string $name = 'receipt.pdf', ?string $content = null): int
    {
        [$path, $clientName] = $this->file($name, $content);

        return $this->attachments->add($journalId, $path, $clientName, $employeeId);
    }

    private function rowOf(int $attachmentId): ?object
    {
        return $this->accountingDb()->table('accounting_journal_attachments')->where('attachment_id', $attachmentId)->get()->getRow();
    }

    private function pathOf(int $attachmentId): string
    {
        return $this->directory . '/' . $this->rowOf($attachmentId)->stored_name;
    }

    private function auditOf(int $journalId, string $action): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'journal')->where('entity_id', $journalId)->where('action', $action)->orderBy('audit_id')->get()->getResult();
    }

    /**
     * What the books hold of a journal apart from the time it was last touched.
     *
     * @return array{journal: array<string, mixed>, lines: list<array<string, mixed>>}
     */
    private function bookedState(int $journalId): array
    {
        $journal = (array) $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow();
        unset($journal['updated_at']);

        return [
            'journal' => $journal,
            'lines'   => $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_no')->get()->getResultArray(),
        ];
    }

    private function trialBalance(): array
    {
        $report = (new TrialBalance())->build(ReportScope::resolve(null, null), ReportPeriod::of(null, '2026-12-31'));

        return ['rows' => $report->rows, 'totals' => $report->totals];
    }

    /**
     * Runs the callback once, right after the first query that contains the text, and not again
     * for the queries it runs itself.
     */
    private function afterFirstQuery(string $text, callable $callback, callable $action): void
    {
        $done   = false;
        $listen = static function (Query $query) use (&$done, $text, $callback): void {
            if (! $done && str_contains($query->getQuery(), $text)) {
                $done = true;
                $callback();
            }
        };

        Events::on('DBQuery', $listen);

        try {
            $action();
        } finally {
            Events::removeListener('DBQuery', $listen);
        }

        $this->assertTrue($done, 'The query that the test waits for never ran.');
    }

    public function testConstruct_KeepsTheDefaultFolderOutsideThePublicWebRoot(): void
    {
        $directory = (string) (new ReflectionProperty(JournalAttachments::class, 'directory'))->getValue(new JournalAttachments());

        $this->assertStringStartsWith(rtrim(WRITEPATH, '/'), $directory);
        $this->assertStringStartsNotWith(rtrim(FCPATH, '/') . '/', $directory . '/');
        $this->assertStringStartsNotWith(rtrim(FCPATH, '/') . '/', rtrim(WRITEPATH, '/') . '/');
    }

    public function testAdd_StoresTheFileUnderARandomNameOutsideTheNameThePersonGave(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->editor();

        $attachmentId = $this->addFile($journalId, $employee, 'Supplier invoice 7.pdf');
        $row          = $this->rowOf($attachmentId);

        $this->assertSame((string) $journalId, (string) $row->journal_id);
        $this->assertSame('Supplier invoice 7.pdf', $row->original_name);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $row->stored_name);
        $this->assertSame('application/pdf', $row->mime_type);
        $this->assertSame((string) strlen($this->pdfContent()), (string) $row->size_bytes);
        $this->assertSame(hash('sha256', $this->pdfContent()), $row->sha256);
        $this->assertSame((string) $employee, (string) $row->uploaded_by);
        $this->assertFileExists($this->pathOf($attachmentId));
        $this->assertSame($this->pdfContent(), file_get_contents($this->pathOf($attachmentId)));
        $this->assertSame([$row->stored_name], $this->filesIn($this->directory));
    }

    public function testAdd_ProtectsTheFolderFromBeingServedAndKeepsTheFileUnreadableToOthers(): void
    {
        $attachmentId = $this->addFile($this->automaticJournal(), $this->editor());

        $this->assertStringContainsString('Require all denied', (string) file_get_contents($this->directory . '/.htaccess'));
        $this->assertFileExists($this->directory . '/index.html');
        $this->assertSame(0640, fileperms($this->pathOf($attachmentId)) & 0777);
    }

    public function testAdd_GivesTwoFilesOfOneNameTwoStoredNames(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->editor();

        $first  = $this->addFile($journalId, $employee, 'receipt.pdf');
        $second = $this->addFile($journalId, $employee, 'receipt.pdf');

        $this->assertNotSame($this->rowOf($first)->stored_name, $this->rowOf($second)->stored_name);
        $this->assertCount(2, $this->filesIn($this->directory));
    }

    #[DataProvider('allowedKinds')]
    public function testAdd_AcceptsEachAllowedKindOfFile(string $extension, string $mime): void
    {
        $content = $this->contentOf($extension);

        if ($content === null) {
            $this->markTestSkipped("This machine cannot make a .{$extension} file.");
        }

        $attachmentId = $this->addFile($this->automaticJournal(), $this->editor(), 'document.' . $extension, $content);

        $this->assertSame($mime, $this->rowOf($attachmentId)->mime_type);
        $this->assertSame('document.' . $extension, $this->rowOf($attachmentId)->original_name);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function allowedKinds(): array
    {
        return [
            'pdf'  => ['pdf', 'application/pdf'],
            'png'  => ['png', 'image/png'],
            'jpg'  => ['jpg', 'image/jpeg'],
            'gif'  => ['gif', 'image/gif'],
            'webp' => ['webp', 'image/webp'],
            'txt'  => ['txt', 'text/plain'],
            'csv'  => ['csv', 'text/csv'],
            'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            'docx' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        ];
    }

    public function testAdd_ChangesNoAmountNoAccountAndNothingTheBooksReport(): void
    {
        $journalId = $this->automaticJournal();
        $manualId  = $this->journals->postNew($this->journalInput('40.00'));
        $state     = $this->bookedState($journalId);
        $manual    = $this->bookedState($manualId);
        $balance   = $this->trialBalance();

        $employee = $this->editor();
        $this->addFile($journalId, $employee);
        $this->addFile($manualId, $employee);

        $this->assertSame($state, $this->bookedState($journalId));
        $this->assertSame($manual, $this->bookedState($manualId));
        $this->assertEquals($balance, $this->trialBalance());
    }

    public function testAdd_WritesAnAuditEntryWithTheNameTheSizeAndTheHash(): void
    {
        $journalId    = $this->automaticJournal();
        $employee     = $this->editor();
        $attachmentId = $this->addFile($journalId, $employee, 'receipt.pdf');

        $entries = $this->auditOf($journalId, 'attachment_added');

        $this->assertCount(1, $entries);
        $this->assertSame((string) $employee, (string) $entries[0]->employee_id);
        $this->assertSame('user', $entries[0]->origin);
        $this->assertSame('1', (string) $entries[0]->branch_id);
        $this->assertSame(
            ['attachment_id' => $attachmentId, 'name' => 'receipt.pdf', 'size' => strlen($this->pdfContent()), 'sha256' => hash('sha256', $this->pdfContent())],
            json_decode($entries[0]->details, true),
        );
    }

    #[DataProvider('refusedNames')]
    public function testAdd_RefusesAKindOfFileThatIsNotAllowed(string $name): void
    {
        $journalId = $this->automaticJournal();
        [$path]    = $this->file($name, 'anything');

        $this->assertFailsWith(AccountingError::AttachmentTypeNotAllowed, fn () => $this->attachments->add($journalId, $path, $name, $this->editor()));

        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertFalse(is_dir($this->directory) && $this->filesIn($this->directory) !== []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedNames(): array
    {
        return [
            'php'            => ['shell.php'],
            'phtml'          => ['shell.phtml'],
            'html'           => ['page.html'],
            'svg'            => ['image.svg'],
            'javascript'     => ['script.js'],
            'executable'     => ['setup.exe'],
            'no extension'   => ['receipt'],
            'hidden file'    => ['.htaccess'],
            'only a dot'     => ['receipt.'],
            'spreadsheet xm' => ['sheet.xlsm'],
        ];
    }

    public function testAdd_RefusesAFileWhoseContentIsNotWhatItsNameSays(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->editor();

        foreach ([
            'image.png'   => '<?php echo 1;',
            'receipt.pdf' => '<html><script>alert(1)</script></html>',
            'note.txt'    => "<!DOCTYPE html><html><body>x</body></html>\n",
            'photo.jpg'   => $this->pngContent(),
            'scan.png'    => $this->pdfContent(),
        ] as $name => $content) {
            [$path] = $this->file($name, $content);

            $this->assertFailsWith(AccountingError::AttachmentTypeNotAllowed, fn () => $this->attachments->add($journalId, $path, $name, $employee));
        }

        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertSame([], $this->filesIn($this->directory));
    }

    public function testAdd_RefusesAnEmptyFile(): void
    {
        $journalId = $this->automaticJournal();
        [$path]    = $this->file('empty.txt', '');

        $this->assertFailsWith(AccountingError::AttachmentEmpty, fn () => $this->attachments->add($journalId, $path, 'empty.txt', $this->editor()));
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
    }

    public function testAdd_RefusesAFileThatCannotBeRead(): void
    {
        $journalId = $this->automaticJournal();

        $this->assertFailsWith(AccountingError::AttachmentUploadFailed, fn () => $this->attachments->add($journalId, sys_get_temp_dir() . '/acc_missing_' . bin2hex(random_bytes(4)), 'receipt.pdf', $this->editor()));
    }

    public function testAdd_AcceptsAFileOfExactlyTheLimitAndRefusesOneByteMore(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->editor();

        $this->assertSame(5242880, JournalAttachments::MAX_BYTES);

        [$over] = $this->file('large.txt', str_repeat('a', JournalAttachments::MAX_BYTES + 1));
        $this->assertFailsWith(AccountingError::AttachmentTooLarge, fn () => $this->attachments->add($journalId, $over, 'large.txt', $employee));
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertSame([], $this->filesIn($this->directory));

        [$exact] = $this->file('large.txt', str_repeat('a', JournalAttachments::MAX_BYTES));
        $attachmentId = $this->attachments->add($journalId, $exact, 'large.txt', $employee);

        $this->assertSame((string) JournalAttachments::MAX_BYTES, (string) $this->rowOf($attachmentId)->size_bytes);
    }

    public function testAdd_RefusesTheFileAfterTheLastOneAJournalMayHold(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->editor();

        $this->assertSame(10, JournalAttachments::MAX_PER_JOURNAL);

        for ($count = 1; $count <= JournalAttachments::MAX_PER_JOURNAL; $count++) {
            $this->addFile($journalId, $employee, "note {$count}.txt");
        }

        $this->assertFailsWith(AccountingError::AttachmentLimitReached, fn () => $this->addFile($journalId, $employee, 'one too many.txt'));

        $this->assertSame(10, $this->countRows('accounting_journal_attachments'));
        $this->assertCount(10, $this->filesIn($this->directory));
        $this->assertSame(10, count($this->auditOf($journalId, 'attachment_added')));

        $other = $this->automaticJournal(sourceId: 2);

        $this->addFile($other, $employee, 'first.txt');
        $this->assertSame(11, $this->countRows('accounting_journal_attachments'));
    }

    #[DataProvider('names')]
    public function testSanitizeName_KeepsOnlyASafeNameAndTheExtension(string $given, string $extension, string $expected): void
    {
        $this->assertSame($expected, JournalAttachments::sanitizeName($given, $extension));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function names(): array
    {
        return [
            'plain'                 => ['Invoice 7.pdf', 'pdf', 'Invoice 7.pdf'],
            'arabic'                => ['فاتورة المورد.pdf', 'pdf', 'فاتورة المورد.pdf'],
            'folders up'            => ['../../etc/passwd.txt', 'txt', 'passwd.txt'],
            'windows folders'       => ['C:\\Users\\me\\scan.png', 'png', 'scan.png'],
            'double extension'      => ['shell.php.pdf', 'pdf', 'shell_php.pdf'],
            'control characters'    => ["bad\x00name\r\n.pdf", 'pdf', 'bad_name.pdf'],
            'direction override'    => ["fdp.\u{202E}exe.pdf", 'pdf', 'fdp_exe.pdf'],
            'quotes and semicolons' => ['a";b\'c.pdf', 'pdf', 'a_b_c.pdf'],
            'spaces are squeezed'   => ["a    b   c.pdf", 'pdf', 'a b c.pdf'],
            'only dots'             => ['....pdf', 'pdf', 'file.pdf'],
            'nothing left'          => ['***.pdf', 'pdf', 'file.pdf'],
            'empty'                 => ['', 'pdf', 'file.pdf'],
            'very long'             => [str_repeat('a', 300) . '.pdf', 'pdf', str_repeat('a', 100) . '.pdf'],
            'very long arabic'      => [str_repeat('ع', 300) . '.pdf', 'pdf', str_repeat('ع', 100) . '.pdf'],
        ];
    }

    public function testAdd_StoresTheSanitisedNameAndNotTheOneThePersonTyped(): void
    {
        $attachmentId = $this->addFile($this->automaticJournal(), $this->editor(), '../../evil name.PDF', $this->pdfContent());

        $this->assertSame('evil name.pdf', $this->rowOf($attachmentId)->original_name);
    }

    public function testAdd_RequiresThePermissionToEditAPostedJournal(): void
    {
        $journalId = $this->automaticJournal();

        foreach ([$this->drafter(), $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE])] as $employee) {
            $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->addFile($journalId, $employee));
        }

        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertSame([], $this->filesIn($this->directory));
        $this->assertSame([], $this->auditOf($journalId, 'attachment_added'));
    }

    public function testAdd_RequiresThePermissionToDraftForADraft(): void
    {
        $draftId = $this->journals->saveDraft($this->journalInput());

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->addFile($draftId, $this->editor()));
        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));

        $attachmentId = $this->addFile($draftId, $this->drafter());

        $this->assertSame((string) $draftId, (string) $this->rowOf($attachmentId)->journal_id);
    }

    public function testAdd_AcceptsTheSystemWithoutAnEmployee(): void
    {
        $attachmentId = $this->addFile($this->automaticJournal(), null);

        $this->assertNull($this->rowOf($attachmentId)->uploaded_by);
    }

    public function testAdd_AcceptsAFileOnAPostedManualJournalAndLeavesItsAmountsAlone(): void
    {
        $manualId = $this->journals->postNew($this->journalInput('250.00'));
        $state    = $this->bookedState($manualId);

        $this->addFile($manualId, $this->editor());

        $this->assertSame($state, $this->bookedState($manualId));
        $this->assertSame('posted', $this->bookedState($manualId)['journal']['status']);
    }

    public function testAdd_RefusesAReversedJournalAndTheReversalOfOne(): void
    {
        $manualId   = $this->journals->postNew($this->journalInput());
        $reversalId = $this->journals->reverse($manualId, '2026-10-22', 'Mistake');
        $employee   = $this->editor();

        $this->assertFailsWith(AccountingError::JournalNotPosted, fn () => $this->addFile($manualId, $employee));
        $this->assertSame((string) $reversalId, (string) (new JournalService())->find($manualId)->reversed_by_id);

        $this->addFile($reversalId, $employee);
        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
    }

    public function testAdd_RefusesAPostedJournalOfAClosedPeriodAndKeepsNoFile(): void
    {
        $journalId = $this->automaticJournal(date: '2026-03-15');
        $this->closeThrough('2026-03-31');

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->addFile($journalId, $this->editor()));

        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertSame([], $this->filesIn($this->directory));
        $this->assertSame([], $this->auditOf($journalId, 'attachment_added'));
    }

    public function testAdd_RefusesAJournalWhenOnlyItsOwnBranchIsClosedAndAcceptsAnotherBranchsClosing(): void
    {
        $second = $this->createBranch('B2');
        $first  = $this->automaticJournal(date: '2026-03-15');
        $other  = $this->automaticJournal($second, '2026-03-15', 2);
        $this->closeThrough('2026-03-31', $second);
        $employee = $this->editor();

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->addFile($other, $employee));

        $attachmentId = $this->addFile($first, $employee);

        $this->assertSame((string) $first, (string) $this->rowOf($attachmentId)->journal_id);
    }

    public function testAdd_AcceptsAFileOnADraftWhoseDateIsInAClosedPeriod(): void
    {
        $draftId = $this->journals->saveDraft(['date' => '2026-03-15'] + $this->journalInput());
        $this->closeThrough('2026-03-31');

        $attachmentId = $this->addFile($draftId, $this->drafter());

        $this->assertSame((string) $draftId, (string) $this->rowOf($attachmentId)->journal_id);
    }

    public function testAdd_AcceptsTheFileOnceTheClosingIsReopened(): void
    {
        $journalId = $this->automaticJournal(date: '2026-03-15');
        $this->closeThrough('2026-03-31');
        $employee = $this->editor();

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->addFile($journalId, $employee));

        $this->accountingDb()->table('accounting_closed_periods')->update(['active' => 0]);
        $this->addFile($journalId, $employee);

        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
    }

    public function testAdd_HidesAJournalOfAnotherBranchAsNotFound(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->automaticJournal();
        $limited   = $this->editor([$second]);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->addFile($journalId, $limited));

        $own = $this->automaticJournal($second, sourceId: 2);

        $this->addFile($own, $limited);
        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
        $this->assertSame((string) $second, (string) $this->auditOf($own, 'attachment_added')[0]->branch_id);
    }

    public function testAdd_HidesSomeoneElsesDraftFromAnEmployeeWithoutTheAllPermission(): void
    {
        $owner   = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $other   = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT]);
        $draftId = $this->journals->saveDraft($this->journalInput(), $owner);

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->addFile($draftId, $other));

        $this->addFile($draftId, $owner);
        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
    }

    public function testAdd_WaitsForAClosingInProgressAndStoresNothingWhenItCannotHaveIt(): void
    {
        $journalId = $this->automaticJournal(date: '2026-03-15');
        $employee  = $this->editor();
        $other     = $this->openSecondConnection(false);
        $other->resetTransStatus();
        $other->transStart();
        (new PeriodLocks($other))->holdCompany();
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock(fn () => $this->addFile($journalId, $employee));
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertSame([], $this->filesIn($this->directory));

        $this->addFile($journalId, $employee);
        $this->assertSame(1, $this->countRows('accounting_journal_attachments'));
    }

    public function testAdd_RefusesAJournalThatWasPostedWhileTheFileWasBeingSaved(): void
    {
        $draftId  = $this->journals->saveDraft(['date' => '2026-03-15'] + $this->journalInput());
        $employee = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL, AccountingGuard::JOURNALS_DRAFT, AccountingGuard::JOURNALS_EDIT]);
        $other    = $this->openSecondConnection(false);

        $this->afterFirstQuery(
            'SELECT j.*',
            fn () => $other->query('UPDATE ' . $other->prefixTable('accounting_journals') . " SET status = 'posted', number = '000001' WHERE journal_id = ?", [$draftId]),
            fn () => $this->assertFailsWith(AccountingError::WriteFailed, fn () => $this->addFile($draftId, $employee)),
        );
        $other->close();

        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
        $this->assertSame([], $this->filesIn($this->directory));
    }

    public function testAdd_LeavesAJournalThatTheDatabaseWillNotLetGoOf(): void
    {
        $draftId = $this->journals->saveDraft($this->journalInput());
        $this->addFile($draftId, $this->drafter());

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_journals')->where('journal_id', $draftId)->delete();
    }

    public function testDelete_RemovesTheRowAndTheFileAndWritesAnAuditEntryWithTheName(): void
    {
        $journalId    = $this->automaticJournal();
        $employee     = $this->editor();
        $attachmentId = $this->addFile($journalId, $employee, 'receipt.pdf');
        $path         = $this->pathOf($attachmentId);
        $size         = (int) $this->rowOf($attachmentId)->size_bytes;
        $hash         = $this->rowOf($attachmentId)->sha256;

        $this->attachments->delete($attachmentId, $employee);

        $this->assertNull($this->rowOf($attachmentId));
        $this->assertFileDoesNotExist($path);

        $entries = $this->auditOf($journalId, 'attachment_deleted');

        $this->assertCount(1, $entries);
        $this->assertSame((string) $employee, (string) $entries[0]->employee_id);
        $this->assertSame('1', (string) $entries[0]->branch_id);
        $this->assertSame(['attachment_id' => $attachmentId, 'name' => 'receipt.pdf', 'size' => $size, 'sha256' => $hash], json_decode($entries[0]->details, true));
    }

    public function testDelete_ChangesNoAmountAndKeepsTheOtherFiles(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->editor();
        $keep      = $this->addFile($journalId, $employee, 'keep.txt');
        $drop      = $this->addFile($journalId, $employee, 'drop.txt');
        $state     = $this->bookedState($journalId);
        $balance   = $this->trialBalance();

        $this->attachments->delete($drop, $employee);

        $this->assertSame($state, $this->bookedState($journalId));
        $this->assertEquals($balance, $this->trialBalance());
        $this->assertNotNull($this->rowOf($keep));
        $this->assertFileExists($this->pathOf($keep));
    }

    public function testDelete_RequiresThePermissionThatMatchesTheJournalState(): void
    {
        $posted       = $this->automaticJournal();
        $draftId      = $this->journals->saveDraft($this->journalInput());
        $postedFile   = $this->addFile($posted, $this->editor());
        $draftFile    = $this->addFile($draftId, $this->drafter());
        $postedPath   = $this->pathOf($postedFile);
        $draftPath    = $this->pathOf($draftFile);

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->attachments->delete($postedFile, $this->drafter()));
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->attachments->delete($draftFile, $this->editor()));

        $this->assertNotNull($this->rowOf($postedFile));
        $this->assertNotNull($this->rowOf($draftFile));
        $this->assertFileExists($postedPath);
        $this->assertFileExists($draftPath);
        $this->assertSame([], $this->auditOf($posted, 'attachment_deleted'));

        $this->attachments->delete($draftFile, $this->drafter());
        $this->attachments->delete($postedFile, $this->editor());

        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));
    }

    public function testDelete_RefusesTheFileOfAJournalInAClosedPeriodAndKeepsIt(): void
    {
        $journalId    = $this->automaticJournal(date: '2026-03-15');
        $employee     = $this->editor();
        $attachmentId = $this->addFile($journalId, $employee);
        $path         = $this->pathOf($attachmentId);
        $this->closeThrough('2026-03-31');

        $this->assertPostingFailsWith(PostingError::PeriodClosed, fn () => $this->attachments->delete($attachmentId, $employee));

        $this->assertNotNull($this->rowOf($attachmentId));
        $this->assertFileExists($path);
        $this->assertSame([], $this->auditOf($journalId, 'attachment_deleted'));
    }

    public function testDelete_RefusesAReversedJournalsFileAndKeepsIt(): void
    {
        $manualId     = $this->journals->postNew($this->journalInput());
        $employee     = $this->editor();
        $attachmentId = $this->addFile($manualId, $employee);
        $this->journals->reverse($manualId, '2026-10-22', 'Mistake');

        $this->assertFailsWith(AccountingError::JournalNotPosted, fn () => $this->attachments->delete($attachmentId, $employee));

        $this->assertNotNull($this->rowOf($attachmentId));
    }

    public function testDelete_RejectsAFileThatDoesNotExist(): void
    {
        $this->assertFailsWith(AccountingError::AttachmentNotFound, fn () => $this->attachments->delete(999999, $this->editor()));
    }

    public function testDelete_HidesTheFileOfAJournalOfAnotherBranch(): void
    {
        $second       = $this->createBranch('B2');
        $attachmentId = $this->addFile($this->automaticJournal(), $this->editor());

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->attachments->delete($attachmentId, $this->editor([$second])));

        $this->assertNotNull($this->rowOf($attachmentId));
        $this->assertFileExists($this->pathOf($attachmentId));
    }

    public function testDelete_RefusesAFileThatWasRemovedWhileTheRequestWasWaiting(): void
    {
        $employee     = $this->editor();
        $attachmentId = $this->addFile($this->automaticJournal(), $employee);
        $other        = $this->openSecondConnection(false);

        $this->afterFirstQuery(
            'WHERE `attachment_id` = ' . $attachmentId,
            fn () => $other->query('DELETE FROM ' . $other->prefixTable('accounting_journal_attachments') . ' WHERE attachment_id = ?', [$attachmentId]),
            fn () => $this->assertFailsWith(AccountingError::AttachmentNotFound, fn () => $this->attachments->delete($attachmentId, $employee)),
        );
        $other->close();

        $this->assertSame([], $this->auditOf(1, 'attachment_deleted'));
    }

    public function testDelete_RemovesTheRowEvenWhenTheFileIsAlreadyGoneFromTheDisk(): void
    {
        $employee     = $this->editor();
        $attachmentId = $this->addFile($this->automaticJournal(), $employee);
        unlink($this->pathOf($attachmentId));

        $this->attachments->delete($attachmentId, $employee);

        $this->assertNull($this->rowOf($attachmentId));
    }

    public function testOpen_ReturnsTheStoredFileToSomeoneWhoMayReadTheJournal(): void
    {
        $journalId    = $this->automaticJournal();
        $attachmentId = $this->addFile($journalId, $this->editor(), 'receipt.pdf');
        $reader       = $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_ALL]);

        $file = $this->attachments->open($attachmentId, $reader);

        $this->assertSame($this->pathOf($attachmentId), $file['path']);
        $this->assertSame('receipt.pdf', $file['name']);
        $this->assertSame('application/pdf', $file['mime']);
        $this->assertSame(strlen($this->pdfContent()), $file['size']);
        $this->assertSame($this->pdfContent(), file_get_contents($file['path']));
    }

    public function testOpen_HidesTheFileFromSomeoneWhoMayNotReadTheJournal(): void
    {
        $second       = $this->createBranch('B2');
        $attachmentId = $this->addFile($this->automaticJournal(), $this->editor());

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->attachments->open($attachmentId, $this->editor([$second])));
        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->attachments->open($attachmentId, $this->employeeWithGrants(['accounting_journals', AccountingGuard::JOURNALS_DRAFT])));
    }

    public function testOpen_ServesTheFilesOfAReversedJournalSoTheHistoryStaysReadable(): void
    {
        $manualId     = $this->journals->postNew($this->journalInput());
        $attachmentId = $this->addFile($manualId, $this->editor());
        $this->journals->reverse($manualId, '2026-10-22', 'Mistake');

        $this->assertSame('receipt.pdf', $this->attachments->open($attachmentId, $this->editor())['name']);
    }

    public function testOpen_RefusesAFileWhoseBytesChangedOnTheDisk(): void
    {
        $attachmentId = $this->addFile($this->automaticJournal(), $this->editor());
        file_put_contents($this->pathOf($attachmentId), $this->pdfContent() . 'tampered');

        $this->assertFailsWith(AccountingError::AttachmentUnavailable, fn () => $this->attachments->open($attachmentId, $this->editor()));
    }

    public function testOpen_RefusesAFileThatIsMissingFromTheDisk(): void
    {
        $attachmentId = $this->addFile($this->automaticJournal(), $this->editor());
        unlink($this->pathOf($attachmentId));

        $this->assertFailsWith(AccountingError::AttachmentUnavailable, fn () => $this->attachments->open($attachmentId, $this->editor()));
    }

    public function testOpen_RejectsAFileThatDoesNotExist(): void
    {
        $this->assertFailsWith(AccountingError::AttachmentNotFound, fn () => $this->attachments->open(999999, $this->editor()));
    }

    public function testOpen_HidesTheFilesOfAPreviewJournal(): void
    {
        $journalId    = $this->automaticJournal();
        $attachmentId = $this->addFile($journalId, $this->editor());
        $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->update(['status' => 'preview']);

        $this->assertFailsWith(AccountingError::AttachmentNotFound, fn () => $this->attachments->open($attachmentId, $this->editor()));
    }

    public function testForJournal_ListsTheFilesOldestFirstWithWhoSavedThem(): void
    {
        $journalId = $this->automaticJournal();
        $employee  = $this->editor();
        $first     = $this->addFile($journalId, $employee, 'first.txt');
        $second    = $this->addFile($journalId, null, 'second.txt');
        $this->addFile($this->automaticJournal(sourceId: 2), $employee, 'elsewhere.txt');

        $rows = $this->attachments->forJournal($journalId, $employee);

        $this->assertSame([$first, $second], array_map(static fn (object $row): int => (int) $row->attachment_id, $rows));
        $this->assertSame(['first.txt', 'second.txt'], array_map(static fn (object $row): string => $row->original_name, $rows));
        $this->assertSame('', (string) $rows[1]->uploaded_by_name);
        $this->assertNotSame('', $rows[0]->uploaded_by_name);
    }

    public function testForJournal_HidesTheJournalFromSomeoneWhoMayNotReadIt(): void
    {
        $second    = $this->createBranch('B2');
        $journalId = $this->automaticJournal();

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => $this->attachments->forJournal($journalId, $this->editor([$second])));
    }

    public function testChangeRefusal_SaysWhyTheFilesOfAJournalCannotBeChanged(): void
    {
        $drafter = $this->drafter();
        $editor  = $this->editor();
        $nobody  = $this->employeeWithGrants(['accounting_journals']);

        $this->assertNull($this->attachments->changeRefusal((object) ['status' => 'draft'], $drafter));
        $this->assertNull($this->attachments->changeRefusal((object) ['status' => 'posted'], $editor));
        $this->assertNull($this->attachments->changeRefusal((object) ['status' => 'posted'], null));
        $this->assertSame(AccountingError::PermissionDenied, $this->attachments->changeRefusal((object) ['status' => 'draft'], $editor));
        $this->assertSame(AccountingError::PermissionDenied, $this->attachments->changeRefusal((object) ['status' => 'posted'], $drafter));
        $this->assertSame(AccountingError::PermissionDenied, $this->attachments->changeRefusal((object) ['status' => 'posted'], $nobody));
        $this->assertSame(AccountingError::JournalNotPosted, $this->attachments->changeRefusal((object) ['status' => 'reversed'], $editor));
        $this->assertSame(AccountingError::JournalNotPosted, $this->attachments->changeRefusal((object) ['status' => 'preview'], $editor));
        $this->assertTrue($this->attachments->canChange((object) ['status' => 'posted'], $editor));
        $this->assertFalse($this->attachments->canChange((object) ['status' => 'posted'], $nobody));
    }

    public function testPurgeDraft_TakesTheRowsOutWritesOneEntryForEachAndReturnsWhereTheFilesAre(): void
    {
        $draftId  = $this->journals->saveDraft($this->journalInput());
        $employee = $this->drafter();
        $first    = $this->addFile($draftId, $employee, 'first.txt');
        $second   = $this->addFile($draftId, $employee, 'second.txt');
        $paths    = [$this->pathOf($first), $this->pathOf($second)];

        $this->assertSame($paths, $this->attachments->purgeDraft($draftId, $employee));

        $this->assertSame(0, $this->countRows('accounting_journal_attachments'));

        $entries = $this->auditOf($draftId, 'attachment_deleted');

        $this->assertCount(2, $entries);
        $this->assertTrue(json_decode($entries[0]->details, true)['with_draft']);
        $this->assertSame('first.txt', json_decode($entries[0]->details, true)['name']);
        $this->assertSame('second.txt', json_decode($entries[1]->details, true)['name']);
        $this->assertFileExists($paths[0]);

        $this->attachments->removeFiles($paths);

        $this->assertFileDoesNotExist($paths[0]);
        $this->assertFileDoesNotExist($paths[1]);
    }
}
