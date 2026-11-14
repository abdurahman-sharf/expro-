<?php

namespace App\Libraries;

use App\Libraries\Accounting\LegacyPurchaseDiscount;
use App\Libraries\Accounting\Readiness\HistoricalDataChecks;
use App\Libraries\Accounting\Readiness\MigrationChecks;
use App\Libraries\Accounting\Readiness\SchemaProbe;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What a web request checks before it starts the migrations. One migration cannot go on without a
 * decision of the operator (the purchase lines of E38), and it comes after dozens that have changed
 * the database by then: a request that started them would leave it half migrated and the site
 * unusable. The request stops first, with the command that decides, and shows nothing about the
 * documents to a visitor who has not signed in.
 */
class MigrationPreflight
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return string|null Why the migrations must not be started from the web, or null when they may.
     */
    public function refusal(): ?string
    {
        $schema = new SchemaProbe($this->db);

        if ($schema->missing(['receivings', 'receivings_items.discount_type', 'receivings_items.receiving_quantity', 'app_config']) !== []) {
            return null;
        }

        if ((new MigrationChecks($this->db))->appliedAt(HistoricalDataChecks::PURCHASE_DISCOUNT_MIGRATION) !== null) {
            return null;
        }

        $blocked = (new LegacyPurchaseDiscount($this->db))->plan()['blocked'];

        if ($blocked === []) {
            return null;
        }

        return count($blocked) . ' purchase line(s) with a fixed discount cannot be told apart as saved before or after the discount became an amount for every unit, so the migrations cannot start. Nothing was migrated. Run "php spark accounting:purchase_discount" on the server to see them, record the decision with "php spark accounting:purchase_discount resolve", then run "php spark migrate -n App" (docs/accounting/readiness-runbook.md, step 3).';
    }
}
