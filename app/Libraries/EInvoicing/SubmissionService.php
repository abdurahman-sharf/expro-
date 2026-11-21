<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * The queue that sends the documents of the second phase (§4.4 and §4.5), kept apart from the documents, which are
 * never written to once issued. A worker claims due documents with a token
 * and a lease, sends each, keeps every answer in a log that is only ever added to, and moves the document on only
 * while it still holds the claim: a worker that lost its lease can write nothing, its answer staying in the log. A
 * document is never built again: the same file goes out every time, so ZATCA tells a request sent again after no
 * answer by its hash. A tax invoice is cleared and a simplified one reported; a refusal is final, a fault of the
 * service is tried again later, and no answer at all is sent again the same way.
 */
final class SubmissionService
{
    public const PENDING       = 'pending';
    public const RETRY         = 'retry';
    public const UNKNOWN       = 'unknown';
    public const REPORTED      = 'reported';
    public const CLEARED       = 'cleared';
    public const REJECTED      = 'rejected';
    public const DUE           = [self::PENDING, self::RETRY, self::UNKNOWN];
    private const LONGEST_WAIT = 3600;

    private BaseConnection $db;

    public function __construct(private readonly ZatcaClient $client, ?BaseConnection $db = null, private readonly string $environment = 'production')
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Puts an issued document in the queue, once.
     */
    public function queue(int $documentId): void
    {
        self::enqueue($this->db, $documentId, $this->environment);
    }

    /**
     * Puts an issued document in the queue of an environment, once; asking again changes nothing.
     */
    public static function enqueue(BaseConnection $db, int $documentId, string $environment): void
    {
        $db->query('INSERT IGNORE INTO ' . $db->prefixTable('einvoice_outbox') . ' (document_id, company_id, environment, status) VALUES (?, ?, ?, ?)', [$documentId, CompanyContext::id(), $environment, self::PENDING]);
    }

    /**
     * Sends what is due, up to a number of documents.
     *
     * @return array<string, int> How many documents ended in each state, and how many answers came too late to count.
     */
    public function dispatch(int $limit = 20, int $leaseSeconds = 300): array
    {
        $token  = bin2hex(random_bytes(16));
        $counts = [];

        foreach ($this->claim($token, $limit, $leaseSeconds) as $document) {
            $state          = $this->send($document, $token);
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @return list<object>
     */
    private function claim(string $token, int $limit, int $leaseSeconds): array
    {
        $table = $this->db->prefixTable('einvoice_outbox');
        $due   = implode(', ', array_fill(0, count(self::DUE), '?'));

        $this->db->query(
            "UPDATE {$table} SET claim_token = ?, lease_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
             WHERE company_id = ? AND environment = ? AND status IN ({$due})
               AND (lease_until IS NULL OR lease_until < NOW()) AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
             ORDER BY document_id LIMIT ?",
            [$token, max(1, $leaseSeconds), CompanyContext::id(), $this->environment, ...self::DUE, max(1, $limit)],
        );

        return $this->db->table('einvoice_outbox o')
            ->select('o.document_id, o.attempts, d.subtype, d.uuid, d.invoice_hash, d.xml')
            ->join('einvoice_documents d', 'd.document_id = o.document_id')
            ->where('o.claim_token', $token)
            ->orderBy('o.document_id', 'ASC')
            ->get()
            ->getResult();
    }

    private function send(object $document, string $token): string
    {
        $request = new ZatcaRequest(
            (string) $document->subtype === EInvoiceSubtype::Standard->value ? ZatcaRequest::CLEARANCE : ZatcaRequest::REPORTING,
            $this->environment,
            (string) $document->uuid,
            (string) $document->invoice_hash,
            (string) $document->xml,
        );
        $started = date('Y-m-d H:i:s');

        try {
            $response = $this->client->submit($request);
        } catch (Throwable) {
            $response = new ZatcaResponse(ZatcaOutcome::Timeout);
        }

        $attempt = (int) $document->attempts + 1;

        $this->db->table('einvoice_submissions')->insert([
            'document_id'  => (int) $document->document_id,
            'environment'  => $this->environment,
            'kind'         => $request->kind,
            'attempt_no'   => $attempt,
            'claim_token'  => $token,
            'http_status'  => $response->httpStatus,
            'outcome'      => $response->outcome->value,
            'warnings'     => $response->warnings === [] ? null : json_encode($response->warnings, JSON_UNESCAPED_UNICODE),
            'errors'       => $response->errors === [] ? null : json_encode($response->errors, JSON_UNESCAPED_UNICODE),
            'request_hash' => $request->fingerprint(),
            'started_at'   => $started,
            'finished_at'  => date('Y-m-d H:i:s'),
        ]);

        $state = match (true) {
            $response->outcome->accepted()                => $request->kind === ZatcaRequest::CLEARANCE ? self::CLEARED : self::REPORTED,
            $response->outcome === ZatcaOutcome::Rejected => self::REJECTED,
            $response->outcome === ZatcaOutcome::Timeout  => self::UNKNOWN,
            default                                       => self::RETRY,
        };

        $table = $this->db->prefixTable('einvoice_outbox');
        $wait  = in_array($state, [self::RETRY, self::UNKNOWN], true) ? min(self::LONGEST_WAIT, 60 * 2 ** min($attempt, 6)) : null;

        $this->db->transStart();
        $this->db->query(
            "UPDATE {$table} SET status = ?, attempts = ?, last_error_code = ?, claim_token = NULL, lease_until = NULL,
                next_attempt_at = " . ($wait === null ? 'NULL' : 'DATE_ADD(NOW(), INTERVAL ' . $wait . ' SECOND)') . '
             WHERE document_id = ? AND claim_token = ?',
            [$state, $attempt, mb_substr($response->errors[0] ?? ($state === self::RETRY ? $response->outcome->value : ''), 0, 255) ?: null, (int) $document->document_id, $token],
        );

        if ($this->db->affectedRows() === 0) {
            $this->db->transComplete();

            return 'late';
        }

        if ($state === self::CLEARED && $response->clearedInvoice !== null) {
            $this->db->table('einvoice_cleared_documents')->replace(['document_id' => (int) $document->document_id, 'xml' => $response->clearedInvoice, 'qr_text' => null, 'cleared_at' => date('Y-m-d H:i:s')]);
        }

        $this->db->transComplete();

        return $state;
    }
}
