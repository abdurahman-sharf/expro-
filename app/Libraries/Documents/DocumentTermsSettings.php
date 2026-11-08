<?php

namespace App\Libraries\Documents;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The texts of the terms and conditions, one for each family of documents. A change is audited with the
 * text it replaced and the one it became, and the same text saved again writes nothing.
 */
final class DocumentTermsSettings
{
    private BaseConnection $db;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->audit = new AuditLog($this->db);
    }

    /**
     * @return array<string, string> The text of each family, empty where none is saved.
     */
    public function all(): array
    {
        $texts = array_fill_keys(DocumentTerms::settingKeys(), '');

        foreach ($this->db->table('app_config')->select('key, value')->whereIn('key', DocumentTerms::settingKeys())->get()->getResult() as $row) {
            $texts[$row->key] = (string) $row->value;
        }

        return $texts;
    }

    /**
     * @param array<string, mixed> $input The texts by setting; a setting that is not in it stays as it is.
     *
     * @return array<string, string> What changed, by setting.
     */
    public function update(array $input, ?int $employeeId = null): array
    {
        $before  = $this->all();
        $changes = [];

        foreach (DocumentTerms::settingKeys() as $key) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            $text = DocumentTerms::clean($input[$key]);

            if ($text !== $before[$key]) {
                $changes[$key] = $text;
            }
        }

        if ($changes === []) {
            return [];
        }

        model(Appconfig::class)->batch_save($changes);
        $this->audit->write('document_terms', null, 'document_terms_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);

        return $changes;
    }
}
