<?php

namespace App\Libraries\EInvoicing;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use SensitiveParameter;

/**
 * The certificates of the units (CSID, §4.7): the public certificate as text, and the secret, the token and the
 * private key only sealed by the vault, never written plain to the database or a log. A new certificate of a unit,
 * environment and kind takes over in one step and the one before it is retired, so issuing never sees two or none.
 * The ones that run out soon are found for the warnings (30 and 7 days, T12), and none that ran out is given.
 */
final class EInvoiceCredentials
{
    public const COMPLIANCE = 'compliance';
    public const PRODUCTION = 'production';

    private BaseConnection $db;

    public function __construct(private readonly KeyVault $vault, ?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function store(int $unitId, string $environment, string $kind, string $certificate, #[SensitiveParameter] string $secret, #[SensitiveParameter] string $token, #[SensitiveParameter] string $privateKey, string $validFrom, string $validTo, string $requestId = '', ?int $employeeId = null): int
    {
        $this->db->transStart();

        $this->db->table('einvoice_credentials')->where(['unit_id' => $unitId, 'environment' => $environment, 'kind' => $kind, 'status' => 'active'])->update(['status' => 'retired']);
        $this->db->table('einvoice_credentials')->insert([
            'unit_id'            => $unitId,
            'environment'        => $environment,
            'kind'               => $kind,
            'certificate'        => $certificate,
            'secret_sealed'      => $this->vault->seal($secret),
            'token_sealed'       => $this->vault->seal($token),
            'private_key_sealed' => $this->vault->seal($privateKey),
            'request_id'         => $requestId,
            'valid_from'         => $validFrom,
            'valid_to'           => $validTo,
            'status'             => 'active',
            'created_by'         => $employeeId,
        ]);

        $credentialId = (int) $this->db->insertID();

        $this->db->transComplete();

        return $credentialId;
    }

    /**
     * The active certificate of a unit with its secrets opened, at a moment.
     *
     * @return array{credential_id: int, certificate: string, secret: string, token: string, private_key: string, valid_to: string}
     *
     * @throws EInvoiceException When none is active or the one that is ran out.
     */
    public function active(int $unitId, string $environment, string $kind, string $now): array
    {
        $row = $this->db->table('einvoice_credentials')->where(['unit_id' => $unitId, 'environment' => $environment, 'kind' => $kind, 'status' => 'active'])->get()->getRow();

        if ($row === null) {
            throw new EInvoiceException(EInvoiceError::CertificateUnreadable, 'The unit has no certificate for this environment.');
        }

        if ($row->valid_to !== null && strcmp((string) $row->valid_to, $now) <= 0) {
            throw new EInvoiceException(EInvoiceError::CertificateExpired, 'The certificate of the unit ran out.', ['expected' => (string) $row->valid_to]);
        }

        return [
            'credential_id' => (int) $row->credential_id,
            'certificate'   => (string) $row->certificate,
            'secret'        => $this->vault->open((string) $row->secret_sealed),
            'token'         => $this->vault->open((string) $row->token_sealed),
            'private_key'   => $this->vault->open((string) $row->private_key_sealed),
            'valid_to'      => (string) $row->valid_to,
        ];
    }

    /**
     * The active certificates that run out within some days of a moment, the soonest first, without their secrets.
     *
     * @return list<object> credential_id, unit_id, environment, kind, valid_to.
     */
    public function expiring(string $now, int $days): array
    {
        return $this->db->table('einvoice_credentials')
            ->select('credential_id, unit_id, environment, kind, valid_to')
            ->where('status', 'active')
            ->where('valid_to <=', date('Y-m-d H:i:s', (int) strtotime($now . ' +' . $days . ' days')))
            ->orderBy('valid_to', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * Seals every stored secret again with the newest master key of the vault (`einvoice:rotate_key`).
     */
    public function rewrapAll(): int
    {
        $count = 0;

        foreach ($this->db->table('einvoice_credentials')->select('credential_id, secret_sealed, token_sealed, private_key_sealed')->get()->getResult() as $row) {
            $this->db->table('einvoice_credentials')->where('credential_id', (int) $row->credential_id)->update([
                'secret_sealed'      => $this->vault->rewrap((string) $row->secret_sealed),
                'token_sealed'       => $this->vault->rewrap((string) $row->token_sealed),
                'private_key_sealed' => $this->vault->rewrap((string) $row->private_key_sealed),
            ]);
            $count++;
        }

        return $count;
    }
}
