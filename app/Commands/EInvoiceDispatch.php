<?php

namespace App\Commands;

use App\Libraries\EInvoicing\SubmissionService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;

class EInvoiceDispatch extends BaseCommand
{
    protected $group       = 'EInvoicing';
    protected $name        = 'einvoice:dispatch';
    protected $usage       = 'einvoice:dispatch';
    protected $description = 'Sends the documents queued for ZATCA (the second phase). No client to ZATCA is part of Expro until a unit is onboarded with a separate approval for its environment, so it sends nothing and says how many documents wait.';

    public function run(array $params): int
    {
        $db      = Database::connect();
        $waiting = $db->tableExists($db->prefixTable('einvoice_outbox'), false)
            ? $db->table('einvoice_outbox')->whereIn('status', SubmissionService::DUE)->countAllResults()
            : 0;

        CLI::write("No client to ZATCA is configured: nothing is sent. Documents waiting: {$waiting}.", $waiting === 0 ? 'green' : 'yellow');

        return EXIT_SUCCESS;
    }
}
