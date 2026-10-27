<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use finfo;
use Throwable;

/**
 * The supporting files of a journal. A file is kept outside the public web root under a random
 * name, and the row that describes it ties it to the journal. It is read back only through
 * open(), which asks for the same access as reading the journal. A file never touches a line or
 * an amount: adding or removing one writes an audit entry and changes nothing else. A draft's
 * files belong to whoever may change the draft, and the files of a posted journal to the holders of
 * the permission that edits its text, who cannot change them once the period is closed.
 */
final class JournalAttachments
{
    use RunsInTransaction;

    public const MAX_BYTES       = 5242880;
    public const MAX_PER_JOURNAL = 10;
    private const NAME_MAX       = 100;
    private const TYPES          = [
        'pdf'  => ['application/pdf'],
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    ];

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private JournalService $journals;
    private PeriodGuard $periods;
    private string $directory;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?JournalService $journals = null, ?string $directory = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->audit     = new AuditLog($this->db);
        $this->guard     = $guard ?? new AccountingGuard();
        $this->journals  = $journals ?? new JournalService($this->db, $this->guard);
        $this->periods   = new PeriodGuard($this->db);
        $this->directory = rtrim($directory ?? WRITEPATH . 'accounting_attachments', '/');
    }

    /**
     * The extensions a file may have, for the screen to say so.
     *
     * @return list<string>
     */
    public static function extensions(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * The name a person gave a file, made safe to show and to offer for download: no folder, no
     * dot except the one before the extension, no control or direction character, at most 100
     * letters before the extension.
     */
    public static function sanitizeName(string $name, string $extension): string
    {
        $base = pathinfo(str_replace('\\', '/', $name), PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^\p{L}\p{N} ()-]+/u', '_', $base);
        $base = (string) preg_replace('/\s+/u', ' ', $base);
        $base = mb_substr(trim($base, ' _-'), 0, self::NAME_MAX);

        return ($base === '' ? 'file' : $base) . '.' . $extension;
    }

    /**
     * What may be done to the files of a journal in its state: null when the employee may add and
     * remove them, else why not.
     */
    public function changeRefusal(object $journal, ?int $employeeId): ?AccountingError
    {
        return match ($journal->status) {
            JournalStatus::Draft->value  => $this->guard->has(AccountingGuard::JOURNALS_DRAFT, $employeeId) ? null : AccountingError::PermissionDenied,
            JournalStatus::Posted->value => $this->guard->has(AccountingGuard::JOURNALS_EDIT, $employeeId) ? null : AccountingError::PermissionDenied,
            default                      => AccountingError::JournalNotPosted,
        };
    }

    public function canChange(object $journal, ?int $employeeId): bool
    {
        return $this->changeRefusal($journal, $employeeId) === null;
    }

    /**
     * @return list<object>
     *
     * @throws AccountingException
     */
    public function forJournal(int $journalId, ?int $employeeId = null): array
    {
        $this->journals->find($journalId, $employeeId);

        return $this->db->table('accounting_journal_attachments a')
            ->select("a.attachment_id, a.original_name, a.mime_type, a.size_bytes, a.uploaded_at, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS uploaded_by_name", false)
            ->join('people p', 'p.person_id = a.uploaded_by', 'left')
            ->where('a.journal_id', $journalId)
            ->orderBy('a.attachment_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Stores a file for a journal and returns the id of its row. The file is read from where the
     * upload put it and copied; it is never moved and never run.
     *
     * @throws AccountingException|PostingException
     */
    public function add(int $journalId, string $tempPath, string $clientName, ?int $employeeId = null): int
    {
        $stored = $this->journals->find($journalId, $employeeId);

        $this->requireChange($stored, $employeeId);

        $extension = strtolower(pathinfo($clientName, PATHINFO_EXTENSION));
        $size      = is_file($tempPath) ? (int) filesize($tempPath) : -1;

        if (! isset(self::TYPES[$extension])) {
            throw new AccountingException(AccountingError::AttachmentTypeNotAllowed, 'This kind of file is not allowed.');
        }

        if ($size < 0) {
            throw new AccountingException(AccountingError::AttachmentUploadFailed, 'The uploaded file cannot be read.');
        }

        if ($size === 0) {
            throw new AccountingException(AccountingError::AttachmentEmpty, 'The file is empty.');
        }

        if ($size > self::MAX_BYTES) {
            throw new AccountingException(AccountingError::AttachmentTooLarge, 'The file is too large.', ['max' => self::MAX_BYTES / 1048576]);
        }

        $storedName = bin2hex(random_bytes(16));
        $path       = $this->pathOf($storedName);

        $this->prepareDirectory();

        if (! copy($tempPath, $path)) {
            throw new AccountingException(AccountingError::AttachmentUploadFailed, 'The file could not be stored.');
        }

        try {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);

            if ($mime === false || ! in_array($mime, self::TYPES[$extension], true)) {
                throw new AccountingException(AccountingError::AttachmentTypeNotAllowed, 'The content of the file does not match its kind.');
            }

            chmod($path, 0640);

            $row = [
                'journal_id'    => $journalId,
                'original_name' => self::sanitizeName($clientName, $extension),
                'stored_name'   => $storedName,
                'mime_type'     => self::TYPES[$extension][0],
                'size_bytes'    => (int) filesize($path),
                'sha256'        => (string) hash_file('sha256', $path),
                'uploaded_by'   => $employeeId,
            ];

            return $this->transactional(function () use ($journalId, $stored, $employeeId, $row): int {
                $locked = $this->lockJournal($journalId, $stored);

                $count = $this->db->table('accounting_journal_attachments')->where('journal_id', $journalId)->countAllResults();

                if ($count >= self::MAX_PER_JOURNAL) {
                    throw new AccountingException(AccountingError::AttachmentLimitReached, 'The journal holds as many files as it may.', ['max' => self::MAX_PER_JOURNAL]);
                }

                $this->db->table('accounting_journal_attachments')->insert($row);

                $attachmentId = (int) $this->db->insertID();

                $this->audit->write('journal', $journalId, 'attachment_added', $employeeId, JournalOrigin::User, $this->summary($attachmentId, $row), (int) $locked->branch_id);

                return $attachmentId;
            });
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }
    }

    /**
     * Removes a file and writes down which one it was.
     *
     * @throws AccountingException|PostingException
     */
    public function delete(int $attachmentId, ?int $employeeId = null): void
    {
        $attachment = $this->row($attachmentId);
        $stored     = $this->journals->find((int) $attachment->journal_id, $employeeId);

        $this->requireChange($stored, $employeeId);

        $this->transactional(function () use ($attachmentId, $stored, $employeeId): void {
            $locked = $this->lockJournal((int) $stored->journal_id, $stored);
            $table  = $this->db->prefixTable('accounting_journal_attachments');
            $row    = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE attachment_id = ? FOR UPDATE", [$attachmentId]);

            if ($row === null) {
                throw new AccountingException(AccountingError::AttachmentNotFound, "File {$attachmentId} does not exist.");
            }

            $this->db->table('accounting_journal_attachments')->where('attachment_id', $attachmentId)->delete();

            $this->audit->write('journal', (int) $stored->journal_id, 'attachment_deleted', $employeeId, JournalOrigin::User, $this->summary($attachmentId, (array) $row), (int) $locked->branch_id);
        });

        $this->removeFiles([$this->pathOf($attachment->stored_name)]);
    }

    /**
     * The file to send to someone who may read the journal it belongs to.
     *
     * @return array{path: string, name: string, mime: string, size: int}
     *
     * @throws AccountingException
     */
    public function open(int $attachmentId, ?int $employeeId = null): array
    {
        $attachment = $this->row($attachmentId);
        $journal    = $this->journals->find((int) $attachment->journal_id, $employeeId);

        if ($journal->status === JournalStatus::Preview->value) {
            throw new AccountingException(AccountingError::AttachmentNotFound, "File {$attachmentId} does not exist.");
        }

        $path = $this->pathOf($attachment->stored_name);

        if (! is_file($path) || ! hash_equals($attachment->sha256, (string) hash_file('sha256', $path))) {
            throw new AccountingException(AccountingError::AttachmentUnavailable, "File {$attachmentId} is not available.");
        }

        return ['path' => $path, 'name' => $attachment->original_name, 'mime' => $attachment->mime_type, 'size' => (int) $attachment->size_bytes];
    }

    /**
     * Takes the files out of the books with a draft that is being deleted, inside the deletion's
     * transaction, and returns where they are so they are removed once it has committed.
     *
     * @return list<string>
     *
     * @throws AccountingException
     */
    public function purgeDraft(int $journalId, ?int $employeeId): array
    {
        $rows  = $this->db->table('accounting_journal_attachments')->where('journal_id', $journalId)->orderBy('attachment_id', 'ASC')->get()->getResult();
        $paths = [];

        foreach ($rows as $row) {
            $this->db->table('accounting_journal_attachments')->where('attachment_id', $row->attachment_id)->delete();

            $this->audit->write('journal', $journalId, 'attachment_deleted', $employeeId, JournalOrigin::User, $this->summary((int) $row->attachment_id, (array) $row) + ['with_draft' => true]);

            $paths[] = $this->pathOf($row->stored_name);
        }

        return $paths;
    }

    /**
     * @param list<string> $paths
     */
    public function removeFiles(array $paths): void
    {
        foreach ($paths as $path) {
            @unlink($path);
        }
    }

    /**
     * @throws AccountingException
     */
    private function requireChange(object $journal, ?int $employeeId): void
    {
        $refusal = $this->changeRefusal($journal, $employeeId);

        if ($refusal !== null) {
            throw new AccountingException($refusal, "The files of journal {$journal->journal_id} cannot be changed.");
        }
    }

    /**
     * Takes the period locks of a posted journal, then the journal itself, and checks that it is
     * still in the state it was read in, so a draft posted a moment ago is not treated as a draft.
     *
     * @throws AccountingException|PostingException
     */
    private function lockJournal(int $journalId, object $read): object
    {
        if ($read->status === JournalStatus::Posted->value) {
            $this->periods->enforce($read->journal_date, (int) $read->branch_id);
        }

        $table  = $this->db->prefixTable('accounting_journals');
        $locked = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE journal_id = ? FOR UPDATE", [$journalId]);

        if ($locked === null) {
            throw new AccountingException(AccountingError::JournalNotFound, "Journal {$journalId} does not exist.");
        }

        if ($locked->status !== $read->status) {
            throw new AccountingException(AccountingError::WriteFailed, 'The journal changed while the file was being saved.');
        }

        return $locked;
    }

    /**
     * @throws AccountingException
     */
    private function row(int $attachmentId): object
    {
        $row = $this->db->table('accounting_journal_attachments')->where('attachment_id', $attachmentId)->get()->getRow();

        if ($row === null) {
            throw new AccountingException(AccountingError::AttachmentNotFound, "File {$attachmentId} does not exist.");
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function summary(int $attachmentId, array $row): array
    {
        return [
            'attachment_id' => $attachmentId,
            'name'          => $row['original_name'],
            'size'          => (int) $row['size_bytes'],
            'sha256'        => $row['sha256'],
        ];
    }

    private function pathOf(string $storedName): string
    {
        return $this->directory . '/' . $storedName;
    }

    private function prepareDirectory(): void
    {
        if (! is_dir($this->directory) && ! mkdir($this->directory, 0750, true) && ! is_dir($this->directory)) {
            throw new AccountingException(AccountingError::AttachmentUploadFailed, 'The folder for the files cannot be created.');
        }

        foreach (['.htaccess' => "Require all denied\n", 'index.html' => "<html><body>Directory access is forbidden.</body></html>\n"] as $name => $content) {
            if (! is_file($this->directory . '/' . $name)) {
                file_put_contents($this->directory . '/' . $name, $content);
            }
        }
    }
}
