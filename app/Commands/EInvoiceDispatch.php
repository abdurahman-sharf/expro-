<?php

namespace App\Commands;

use App\Libraries\EInvoicing\CurlZatcaHttp;
use App\Libraries\EInvoicing\EInvoiceCredentials;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\KeyVault;
use App\Libraries\EInvoicing\SubmissionService;
use App\Libraries\EInvoicing\ZatcaEnvironment;
use App\Libraries\EInvoicing\ZatcaHttpClient;
use App\Libraries\EInvoicing\ZatcaNetworkGuard;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Config\Zatca;

class EInvoiceDispatch extends BaseCommand
{
    protected $group       = 'EInvoicing';
    protected $name        = 'einvoice:dispatch';
    protected $usage       = 'einvoice:dispatch [--limit 20]';
    protected $description = 'Sends the documents queued for ZATCA (the second phase) to production: a simplified document reported, a tax document cleared, each answer kept. It sends nothing, and says why and how many documents wait, unless the network is turned on for the server, production is approved in .env and the integration is turned on for production in the application. It is not scheduled by Expro: whoever runs the server schedules it once the owner approves production.';
    protected $options     = [
        '--limit' => 'How many documents to send at most (20 when not given).',
    ];

    public function run(array $params): int
    {
        $db      = Database::connect();
        $waiting = $db->tableExists($db->prefixTable('einvoice_outbox'), false)
            ? $db->table('einvoice_outbox')->whereIn('status', SubmissionService::DUE)->countAllResults()
            : 0;
        $guard   = new ZatcaNetworkGuard();
        $reasons = $guard->reasons(ZatcaEnvironment::Production->value);

        if ($reasons !== []) {
            CLI::write('Nothing is sent to ZATCA: ' . implode(', ', $reasons) . ". Documents waiting: {$waiting}.", $waiting === 0 ? 'green' : 'yellow');

            return EXIT_SUCCESS;
        }

        try {
            $vault = KeyVault::fromFile((string) config(Zatca::class)->keyRing);
        } catch (EInvoiceException $failure) {
            CLI::error('The vault of the certificates cannot be opened: ' . $failure->getMessage() . " Nothing was sent. Documents waiting: {$waiting}.");

            return EXIT_ERROR;
        }

        $limit  = max(1, (int) ($params['limit'] ?? CLI::getOption('limit') ?? 20));
        $client = new ZatcaHttpClient(new CurlZatcaHttp($guard), ZatcaEnvironment::Production, new EInvoiceCredentials($vault));
        $counts = (new SubmissionService($client))->dispatch($limit);

        foreach ($counts as $state => $documents) {
            CLI::write("{$state}: {$documents}");
        }

        CLI::write('Sent to ZATCA: ' . array_sum($counts) . ' documents.', 'green');

        return EXIT_SUCCESS;
    }
}
