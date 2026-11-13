<?php

namespace App\Libraries\MasterData;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use Throwable;

/**
 * The contact list of a customer or a supplier: the people at the party a user can write to or call,
 * each a row of its own with a first and a last name, an e-mail address, a phone and a mobile number,
 * a position and a note. A contact is never read by a posting, a ledger or a report of the books, so
 * changing one touches no journal. A contact the form no longer lists, and every contact of a party
 * that is deleted, is hidden and kept, and the audit log says how many were hidden and by whom.
 */
final class PartyContacts
{
    public const CUSTOMER       = 'customer';
    public const SUPPLIER       = 'supplier';
    public const MARKER         = 'contacts_present';
    public const FIELD          = 'contacts';
    public const MAX_ROWS       = 50;
    public const LIMITS         = ['first_name' => 100, 'last_name' => 100, 'email' => 254, 'phone' => 30, 'mobile' => 30, 'position' => 100, 'note' => 500];
    public const TEXTS          = ['first_name', 'last_name', 'email', 'phone', 'mobile', 'position', 'note'];
    private const NUMBERS       = ['phone', 'mobile'];
    private const PHONE_PATTERN = '/^[0-9+\-().\s]+$/';
    private const DIGITS        = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    private BaseConnection $db;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
    }

    /**
     * The contacts a party keeps, in the order the list was saved in.
     *
     * @return list<object>
     */
    public function forParty(string $kind, int $partyId): array
    {
        return $this->db->table('party_contacts')
            ->where(['party_kind' => $this->kind($kind), 'party_id' => $partyId, 'deleted' => 0])
            ->orderBy('sort', 'ASC')
            ->orderBy('contact_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * The rows a form posted, or null when the form did not carry the panel of contacts (an older form or a
     * file import), which leaves the list of the party as it is.
     *
     * @param array<string, mixed> $post
     *
     * @return list<array<string, mixed>>|null
     */
    public static function fromRequest(array $post): ?array
    {
        if (($post[self::MARKER] ?? '') !== '1') {
            return null;
        }

        $given = $post[self::FIELD] ?? [];

        if (! is_array($given)) {
            return [];
        }

        $rows = [];

        foreach ($given as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The rows as they will be stored: trimmed, the e-mail address in lower case, the Arabic-Indic and
     * Persian digits of a phone number turned into the digits the rest of the books use, a row with nothing
     * in it dropped, and every limit checked.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{contact_id: int|null, first_name: string, last_name: string, email: string, phone: string, mobile: string, position: string, note: string}>
     *
     * @throws AccountingException
     */
    public function validate(array $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $contact = ['contact_id' => $this->contactId($row['contact_id'] ?? null)];

            foreach (self::TEXTS as $field) {
                $value = $row[$field] ?? '';

                $text            = is_scalar($value) ? trim((string) $value) : '';
                $contact[$field] = in_array($field, self::NUMBERS, true) ? strtr($text, self::DIGITS) : $text;
            }

            if (implode('', array_map(static fn (string $field): string => $contact[$field], self::TEXTS)) !== '') {
                $kept[] = $contact;
            }
        }

        if (count($kept) > self::MAX_ROWS) {
            throw new AccountingException(AccountingError::ContactsTooMany, 'A party keeps at most ' . self::MAX_ROWS . ' contacts.', ['max' => self::MAX_ROWS]);
        }

        foreach ($kept as $index => $contact) {
            $kept[$index] = $this->checked($contact, $index + 1);
        }

        return $kept;
    }

    /**
     * Checks the rows against the party before anything is saved, so a refusal leaves the party as it was.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{contact_id: int|null, first_name: string, last_name: string, email: string, phone: string, mobile: string, position: string, note: string}>
     *
     * @throws AccountingException
     */
    public function prepare(string $kind, ?int $partyId, array $rows): array
    {
        $valid  = $this->validate($rows);
        $kept   = $partyId === null ? [] : array_column($this->forParty($kind, $partyId), 'contact_id');
        $kept   = array_map('intval', $kept);
        $unlike = array_filter($valid, static fn (array $contact): bool => $contact['contact_id'] !== null && ! in_array($contact['contact_id'], $kept, true));

        if ($unlike !== []) {
            throw new AccountingException(AccountingError::ContactUnknown, 'A contact of the list is not a contact of this party.');
        }

        return $valid;
    }

    /**
     * Makes the list of the party the rows given: a row with a number updates that contact, a row without one
     * adds a contact, and a contact of the party that no row names is hidden. All of it happens or none of it.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{added: int, updated: int, removed: list<int>}
     *
     * @throws AccountingException
     */
    public function replace(string $kind, int $partyId, array $rows, ?int $employeeId): array
    {
        $kind    = $this->kind($kind);
        $current = [];

        $this->db->transStart();

        try {
            $contacts = $this->prepare($kind, $partyId, $rows);

            foreach ($this->forParty($kind, $partyId) as $held) {
                $current[(int) $held->contact_id] = $held;
            }

            $added   = 0;
            $updated = 0;
            $named   = [];

            foreach ($contacts as $index => $contact) {
                $values = array_diff_key($contact, ['contact_id' => true]) + ['sort' => $index];

                if ($contact['contact_id'] === null) {
                    $this->db->table('party_contacts')->insert($values + ['party_kind' => $kind, 'party_id' => $partyId, 'created_by' => $employeeId]);
                    $added++;

                    continue;
                }

                $named[] = $contact['contact_id'];

                if ($this->differs($current[$contact['contact_id']], $values)) {
                    $this->db->table('party_contacts')->where(['contact_id' => $contact['contact_id'], 'party_kind' => $kind, 'party_id' => $partyId])->update($values);
                    $updated++;
                }
            }

            $removed = array_values(array_diff(array_keys($current), $named));

            if ($removed !== []) {
                $this->db->table('party_contacts')->where(['party_kind' => $kind, 'party_id' => $partyId])->whereIn('contact_id', $removed)->update(['deleted' => 1]);
            }

            if ($added + $updated + count($removed) > 0) {
                $this->audit->write($kind, $partyId, 'party_contacts_changed', $employeeId, JournalOrigin::User, ['added' => $added, 'updated' => $updated, 'removed' => $removed]);
            }

            $this->db->transComplete();
        } catch (Throwable $failure) {
            $this->db->transRollback();

            throw $failure;
        }

        if (! $this->db->transStatus()) {
            throw new AccountingException(AccountingError::ContactsNotSaved, 'The contacts could not be saved.');
        }

        return ['added' => $added, 'updated' => $updated, 'removed' => $removed];
    }

    /**
     * Hides the contacts of a party that was deleted, keeping the rows. A shop that scrambles the personal
     * data of a deleted customer scrambles the contacts with it.
     */
    public function hideWithParty(string $kind, int $partyId, bool $scramble, ?int $employeeId): int
    {
        $kind = $this->kind($kind);
        $held = $this->db->table('party_contacts')->where(['party_kind' => $kind, 'party_id' => $partyId, 'deleted' => 0])->countAllResults();

        $this->db->table('party_contacts')->where(['party_kind' => $kind, 'party_id' => $partyId])->update(['deleted' => 1]);

        if ($scramble) {
            $this->db->table('party_contacts')->where(['party_kind' => $kind, 'party_id' => $partyId])->set('first_name', 'contact_id', false)->update(['last_name' => '', 'email' => '', 'phone' => '', 'mobile' => '', 'position' => '', 'note' => '']);
        }

        if ($held > 0) {
            $this->audit->write($kind, $partyId, 'party_contacts_hidden', $employeeId, JournalOrigin::User, ['count' => $held, 'scrambled' => $scramble]);
        }

        return $held;
    }

    /**
     * @param array<string, mixed> $contact
     *
     * @return array{contact_id: int|null, first_name: string, last_name: string, email: string, phone: string, mobile: string, position: string, note: string}
     *
     * @throws AccountingException
     */
    private function checked(array $contact, int $line): array
    {
        foreach (self::LIMITS as $field => $max) {
            if (mb_strlen($contact[$field]) > $max) {
                throw new AccountingException(AccountingError::ContactFieldTooLong, "{$field} is at most {$max} characters.", ['line' => $line, 'max' => $max, 'name' => lang('Customers.contact_' . $field)]);
            }
        }

        if ($contact['first_name'] === '') {
            throw new AccountingException(AccountingError::ContactNameRequired, 'A contact needs a first name.', ['line' => $line]);
        }

        $contact['email'] = mb_strtolower($contact['email']);

        if ($contact['email'] !== '' && filter_var($contact['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new AccountingException(AccountingError::ContactEmailInvalid, 'The e-mail address of a contact is not valid.', ['line' => $line]);
        }

        foreach (self::NUMBERS as $field) {
            if ($contact[$field] !== '' && preg_match(self::PHONE_PATTERN, $contact[$field]) !== 1) {
                throw new AccountingException(AccountingError::ContactPhoneInvalid, 'A phone number holds digits, spaces and + - ( ) . only.', ['line' => $line]);
            }
        }

        return $contact;
    }

    private function contactId(mixed $given): ?int
    {
        return is_scalar($given) && ctype_digit((string) $given) && (int) $given > 0 ? (int) $given : null;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function differs(object $held, array $values): bool
    {
        foreach ($values as $field => $value) {
            if ((string) $held->{$field} !== (string) $value) {
                return true;
            }
        }

        return false;
    }

    private function kind(string $kind): string
    {
        return match ($kind) {
            self::CUSTOMER, self::SUPPLIER => $kind,
            default => throw new InvalidArgumentException("A contact belongs to a customer or a supplier, not to '{$kind}'."),
        };
    }
}
