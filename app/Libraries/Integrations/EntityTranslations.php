<?php

namespace App\Libraries\Integrations;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The second-language name of a customer, a supplier, an item or a category, and the address note of a
 * customer or a supplier, kept in a table of their own beside the records: nothing here touches the record,
 * a journal or a ledger, so the books read the same with and without a translation. A category is the free
 * text an item carries, so its translation is found by that text. A document prints the second-language
 * name beside the first only while the switch to print it is on.
 */
final class EntityTranslations
{
    use RunsInTransaction;

    public const MAX_NAME = 255;
    public const MAX_NOTE = 255;

    private BaseConnection $db;
    private AuditLog $audit;
    private IntegrationAccess $access;
    private IntegrationOptions $options;

    public function __construct(?BaseConnection $db = null, ?IntegrationAccess $access = null, ?IntegrationOptions $options = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->audit   = new AuditLog($this->db);
        $this->access  = $access ?? new IntegrationAccess();
        $this->options = $options ?? new IntegrationOptions($this->db, $this->access);
    }

    /**
     * @throws IntegrationException
     */
    public function save(string $kind, string $key, string $language, string $name, string $addressNote = '', ?int $employeeId = null): int
    {
        $this->access->requireManage($employeeId);

        $kind     = TranslationKind::tryFrom($kind) ?? throw new IntegrationException(IntegrationError::TranslationKind, "{$kind} is not a kind of record with a second-language name.", ['name' => $kind]);
        $key      = $this->entityKey($kind, $key);
        $language = $this->language($language);
        $name     = $this->text($name, self::MAX_NAME, IntegrationError::TranslationName);
        $note     = $this->text($addressNote, self::MAX_NOTE, IntegrationError::TranslationNote, true);

        if ($note !== '' && ! $kind->takesAddressNote()) {
            throw new IntegrationException(IntegrationError::TranslationNote, 'Only a customer and a supplier have an address note.', ['name' => $kind->value]);
        }

        return $this->transactional(function () use ($kind, $key, $language, $name, $note, $employeeId): int {
            $existing = $this->row($kind, $key, $language);

            if ($existing === null) {
                $this->db->table('entity_translations')->insert([
                    'company_id'   => CompanyContext::id(),
                    'entity_kind'  => $kind->value,
                    'entity_key'   => $key,
                    'language'     => $language,
                    'name'         => $name,
                    'address_note' => $note,
                    'created_by'   => $employeeId,
                    'updated_by'   => $employeeId,
                ]);

                $id = (int) $this->db->insertID();
                $this->audit->write('entity_translation', $id, 'entity_translation_saved', $employeeId, JournalOrigin::User, ['kind' => $kind->value, 'key' => $key, 'language' => $language, 'before' => null, 'after' => ['name' => $name, 'address_note' => $note]]);

                return $id;
            }

            if ($existing->name === $name && $existing->addressNote === $note) {
                return $existing->id;
            }

            $this->db->table('entity_translations')->where('translation_id', $existing->id)->update(['name' => $name, 'address_note' => $note, 'updated_by' => $employeeId]);
            $this->audit->write('entity_translation', $existing->id, 'entity_translation_saved', $employeeId, JournalOrigin::User, ['kind' => $kind->value, 'key' => $key, 'language' => $language, 'before' => ['name' => $existing->name, 'address_note' => $existing->addressNote], 'after' => ['name' => $name, 'address_note' => $note]]);

            return $existing->id;
        });
    }

    /**
     * @throws IntegrationException
     */
    public function delete(int $translationId, ?int $employeeId = null): void
    {
        $this->access->requireManage($employeeId);

        $this->transactional(function () use ($translationId, $employeeId): void {
            $row = $this->db->table('entity_translations')->where(['company_id' => CompanyContext::id(), 'translation_id' => $translationId])->get()->getRow();

            if ($row === null) {
                throw new IntegrationException(IntegrationError::TranslationNotFound, "The translation {$translationId} does not exist.", ['id' => $translationId]);
            }

            $this->db->table('entity_translations')->where('translation_id', $translationId)->delete();
            $this->audit->write('entity_translation', $translationId, 'entity_translation_deleted', $employeeId, JournalOrigin::User, ['kind' => $row->entity_kind, 'key' => $row->entity_key, 'language' => $row->language, 'before' => ['name' => $row->name, 'address_note' => $row->address_note]]);
        });
    }

    public function find(int $translationId): ?EntityTranslation
    {
        $row = $this->db->table('entity_translations')->where(['company_id' => CompanyContext::id(), 'translation_id' => $translationId])->get()->getRow();

        return $row === null ? null : $this->made($row);
    }

    /**
     * @return array{total: int, rows: list<EntityTranslation>}
     */
    public function search(?TranslationKind $kind, string $term, int $limit, int $offset): array
    {
        $builder = $this->db->table('entity_translations')->where('company_id', CompanyContext::id());

        if ($kind !== null) {
            $builder->where('entity_kind', $kind->value);
        }

        $term = trim($term);

        if ($term !== '') {
            $builder->groupStart()->like('name', $term)->orLike('entity_key', $term)->groupEnd();
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('entity_kind', 'ASC')->orderBy('translation_id', 'ASC')->limit(max(1, $limit), max(0, $offset))->get()->getResult();

        return ['total' => $total, 'rows' => array_map($this->made(...), $rows)];
    }

    /**
     * What a document prints beside the names it holds: the translation of the party and of the items,
     * and nothing at all while the switch to print them is off.
     *
     * @param list<int> $itemIds
     *
     * @return array{party: array{name: string, note: string}|null, items: array<int, string>}
     */
    public function forDocument(TranslationKind $partyKind, ?int $partyId, array $itemIds): array
    {
        $none = ['party' => null, 'items' => []];

        if (! $this->options->secondLanguagePrint()) {
            return $none;
        }

        $language = $this->options->secondLanguageCode();
        $party    = null;

        if ($partyId !== null) {
            $row = $this->row($partyKind, (string) $partyId, $language);

            if ($row !== null) {
                $party = ['name' => $row->name, 'note' => $row->addressNote];
            }
        }

        $items = [];
        $ids   = array_values(array_unique(array_map('intval', $itemIds)));

        if ($ids !== []) {
            $rows = $this->db->table('entity_translations')
                ->select('entity_key, name')
                ->where(['company_id' => CompanyContext::id(), 'entity_kind' => TranslationKind::Item->value, 'language' => $language])
                ->whereIn('entity_key', array_map('strval', $ids))
                ->get()
                ->getResult();

            foreach ($rows as $row) {
                $items[(int) $row->entity_key] = (string) $row->name;
            }
        }

        return ['party' => $party, 'items' => $items];
    }

    private function row(TranslationKind $kind, string $key, string $language): ?EntityTranslation
    {
        $row = $this->db->table('entity_translations')->where(['company_id' => CompanyContext::id(), 'entity_kind' => $kind->value, 'entity_key' => $key, 'language' => $language])->get()->getRow();

        return $row === null ? null : $this->made($row);
    }

    private function made(object $row): EntityTranslation
    {
        $kind = TranslationKind::from((string) $row->entity_kind);

        return new EntityTranslation((int) $row->translation_id, $kind, (string) $row->entity_key, (string) $row->language, (string) $row->name, (string) $row->address_note, $this->entityName($kind, (string) $row->entity_key) ?? '');
    }

    /**
     * @throws IntegrationException
     */
    private function entityKey(TranslationKind $kind, string $key): string
    {
        $key = trim($key);

        if ($kind === TranslationKind::Category) {
            $found = $key !== '' && mb_strlen($key) <= 190 && $this->db->table('items')->where(['category' => $key, 'deleted' => 0])->countAllResults() > 0;
        } else {
            $found = ctype_digit($key) && $this->entityName($kind, $key) !== null;
            $key   = $found ? (string) (int) $key : $key;
        }

        if (! $found) {
            throw new IntegrationException(IntegrationError::TranslationEntityMissing, "There is no {$kind->value} {$key}.", ['name' => $key]);
        }

        return $key;
    }

    private function entityName(TranslationKind $kind, string $key): ?string
    {
        if ($kind === TranslationKind::Category) {
            return $key;
        }

        if (! ctype_digit($key)) {
            return null;
        }

        $row = match ($kind) {
            TranslationKind::Customer => $this->db->table('customers c')->select("COALESCE(NULLIF(c.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS name", false)->join('people p', 'p.person_id = c.person_id')->where(['c.person_id' => (int) $key, 'c.deleted' => 0])->get()->getRow(),
            TranslationKind::Supplier => $this->db->table('suppliers s')->select("COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(p.first_name, ' ', p.last_name))) AS name", false)->join('people p', 'p.person_id = s.person_id')->where(['s.person_id' => (int) $key, 's.deleted' => 0])->get()->getRow(),
            default                   => $this->db->table('items')->select('name')->where(['item_id' => (int) $key, 'deleted' => 0])->get()->getRow(),
        };

        return $row === null ? null : trim((string) $row->name);
    }

    /**
     * @throws IntegrationException
     */
    private function language(string $language): string
    {
        $language = trim($language);

        if (! in_array($language, IntegrationOptions::locales(), true)) {
            throw new IntegrationException(IntegrationError::TranslationLanguage, "{$language} is not a language of the application.", ['code' => $language]);
        }

        return $language;
    }

    /**
     * @throws IntegrationException
     */
    private function text(string $value, int $max, IntegrationError $error, bool $mayBeEmpty = false): string
    {
        $text = trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '');

        if ($text === '' && $mayBeEmpty) {
            return '';
        }

        if ($text === '' || mb_strlen($text) > $max || preg_match('/[\p{Cc}\p{Cf}]/u', $text) === 1) {
            throw new IntegrationException($error, 'The text is empty, too long or holds control characters.', ['max' => $max]);
        }

        return $text;
    }
}
