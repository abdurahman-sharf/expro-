<?php

namespace App\Libraries\Integrations;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Which of the manual ways of payment the register offers, and under what name. A way of payment keeps
 * the name it is stored under, so a payment already taken, its journal, its routing to a treasury or an
 * account and every report stay as they are: the screen changes only the list a cashier chooses from, and
 * the name shown in it. Cash cannot be switched off, and credit sale, gift cards and reward points are not
 * ways to manage here, because each belongs to a feature of its own. While the switch of custom payment
 * methods is off the table is not read at all, so the register offers what it always offered.
 */
final class PaymentMethods
{
    use RunsInTransaction;

    public const MAX_NAME = 60;
    private const MANAGED = [PaymentType::Cash, PaymentType::Debit, PaymentType::Credit, PaymentType::Check, PaymentType::BankTransfer, PaymentType::Upi, PaymentType::Wallet];

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
     * @return list<PaymentMethod> Every way of payment this screen manages, as it stands in the table whether or not the switch is on.
     */
    public function all(): array
    {
        $rows = [];

        foreach ($this->db->table('payment_methods')->where('company_id', CompanyContext::id())->get()->getResult() as $row) {
            $rows[(string) $row->method_code] = $row;
        }

        return array_map(static fn (PaymentType $type): PaymentMethod => new PaymentMethod(
            $type,
            ! isset($rows[$type->value]) || (int) $rows[$type->value]->enabled === 1,
            isset($rows[$type->value]) && trim((string) $rows[$type->value]->display_name) !== '' ? (string) $rows[$type->value]->display_name : null,
            lang($type->languageKey()),
            $type !== PaymentType::Cash,
        ), self::MANAGED);
    }

    /**
     * Where the money of a way of payment goes now, as the routing of the books says: the account and, when
     * a treasury owns that account, the treasury. Changing it is the work of the routing screen, which
     * keeps its own permission and its own audit, so this screen only shows it.
     *
     * @return array{account: string, treasury: string|null}|null Null when the way of payment is routed nowhere yet.
     */
    public function routedTo(PaymentType $type): ?array
    {
        try {
            $accountId = (new RoutingResolver($this->db))->accountForPayment($type);
        } catch (PostingException) {
            return null;
        }

        $account = $accountId === null ? null : $this->db->table('accounting_accounts')->select('code, name')->where('account_id', $accountId)->get()->getRow();

        if ($account === null) {
            return null;
        }

        $treasuryId = (new TreasuryScopes($this->db))->ownerOfRoutedType($type);
        $treasury   = $treasuryId === null ? null : $this->db->table('treasuries')->select('name')->where('treasury_id', $treasuryId)->get()->getRow();

        return ['account' => $account->code . ' - ' . $account->name, 'treasury' => $treasury === null ? null : (string) $treasury->name];
    }

    /**
     * What the register offers: the options given, less the ways switched off and with the name chosen for
     * the others. The key of an option stays the name the way is stored under. The form of a record that
     * already carries a way of payment names it in $keep, so that a way switched off since stays in that
     * form and is saved again as it was.
     *
     * @param array<string, string> $options
     * @param list<string>          $keep    The names the ways are stored under.
     *
     * @return array<string, string>
     */
    public function offered(array $options, array $keep = []): array
    {
        if (! $this->options->paymentMethodsCustom()) {
            return $options;
        }

        $methods = [];

        foreach ($this->all() as $method) {
            $methods[$method->type->value] = $method;
        }

        $kept = array_values(array_filter(array_map(static fn (string $name): ?PaymentType => PaymentType::fromLabel($name), $keep)));

        $offered = [];

        foreach ($options as $key => $label) {
            $type   = PaymentType::fromLabel((string) $key);
            $method = $type === null ? null : ($methods[$type->value] ?? null);

            if ($method === null) {
                $offered[$key] = $label;

                continue;
            }

            if ($method->enabled || in_array($method->type, $kept, true)) {
                $offered[$key] = $method->customName ?? $label;
            }
        }

        return $offered;
    }

    public function isOffered(string $paymentType): bool
    {
        if (! $this->options->paymentMethodsCustom()) {
            return true;
        }

        $type = PaymentType::fromLabel($paymentType);

        if ($type === null || ! in_array($type, self::MANAGED, true)) {
            return true;
        }

        foreach ($this->all() as $method) {
            if ($method->type === $type) {
                return $method->enabled;
            }
        }

        return true;
    }

    /**
     * The words that refuse a way of payment a new payment or a changed one may not use, or null when it may.
     * A record that already carries a way switched off since keeps it, so the way it is saved with again
     * is not a change.
     */
    public function refusal(string $paymentType, ?string $stored = null): ?string
    {
        if ($stored !== null && ($paymentType === $stored || $this->sameWay($paymentType, $stored))) {
            return null;
        }

        return $this->isOffered($paymentType) ? null : lang('Integrations.error_payment_method_disabled', [$paymentType]);
    }

    /**
     * The words that refuse the payments an edit form posts when one of them takes a way that is switched
     * off, a payment that keeps the way it already carries excepted.
     *
     * @param list<array<string, mixed>> $payments Each with its number and its way of payment.
     * @param array<int, string>         $stored   The way each payment of the record carries now, by its number.
     */
    public function refusalOfPayments(array $payments, array $stored): ?string
    {
        foreach ($payments as $payment) {
            $refusal = $this->refusal((string) ($payment['payment_type'] ?? ''), $stored[(int) ($payment['payment_id'] ?? 0)] ?? null);

            if ($refusal !== null) {
                return $refusal;
            }
        }

        return null;
    }

    /**
     * @param array<string, array{enabled?: mixed, name?: mixed}> $input By the code of the way of payment; a way that is left out stays as it is.
     *
     * @throws IntegrationException
     */
    public function save(array $input, ?int $employeeId = null): void
    {
        $this->access->requireManage($employeeId);

        $current = [];

        foreach ($this->all() as $method) {
            $current[$method->type->value] = $method;
        }

        $changes = [];

        foreach ($input as $code => $values) {
            $type = PaymentType::tryFrom((string) $code);

            if ($type === null || ! isset($current[$type->value])) {
                throw new IntegrationException(IntegrationError::PaymentMethodUnknown, "{$code} is not a way of payment this screen manages.", ['code' => (string) $code]);
            }

            if (array_filter($values, static fn (mixed $value): bool => ! is_scalar($value) && $value !== null) !== []) {
                throw new IntegrationException(IntegrationError::InputInvalid, "The values of {$code} are not plain values.");
            }

            $method  = $current[$type->value];
            $enabled = array_key_exists('enabled', $values) ? $this->truthy($values['enabled']) : $method->enabled;
            $name    = array_key_exists('name', $values) ? $this->cleanName($values['name']) : $method->customName;

            if (! $enabled && ! $method->mayBeSwitchedOff) {
                throw new IntegrationException(IntegrationError::PaymentMethodProtected, 'Cash cannot be switched off.', ['code' => $type->value]);
            }

            if ($enabled !== $method->enabled || $name !== $method->customName) {
                $changes[$type->value] = ['enabled' => $enabled, 'name' => $name];
            }
        }

        if ($changes === []) {
            return;
        }

        $this->assertNamesDistinct($current, $changes);

        $this->transactional(function () use ($current, $changes, $employeeId): void {
            $before = [];

            foreach ($changes as $code => $values) {
                $this->db->table('payment_methods')->upsert([
                    'company_id'   => CompanyContext::id(),
                    'method_code'  => $code,
                    'enabled'      => $values['enabled'] ? 1 : 0,
                    'display_name' => $values['name'],
                    'updated_by'   => $employeeId,
                ]);

                $before[$code] = ['enabled' => $current[$code]->enabled, 'name' => $current[$code]->customName];
            }

            $this->audit->write('payment_methods', null, 'payment_methods_updated', $employeeId, JournalOrigin::User, ['before' => $before, 'after' => $changes]);
        });
    }

    private function sameWay(string $first, string $second): bool
    {
        $type = PaymentType::fromLabel($first);

        return $type !== null && $type === PaymentType::fromLabel($second);
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'on', 'true'], true);
    }

    /**
     * @throws IntegrationException
     */
    private function cleanName(mixed $value): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        if ($name === '') {
            return null;
        }

        if (mb_strlen($name) > self::MAX_NAME || preg_match('/[\p{C}<>]/u', $name) === 1 || str_contains($name, ':')) {
            throw new IntegrationException(IntegrationError::PaymentMethodName, 'The name of a way of payment is short plain text without a colon.', ['max' => self::MAX_NAME]);
        }

        return $name;
    }

    /**
     * No two ways of payment may be shown under one name, the ways this screen does not manage included,
     * or a cashier could not tell them apart.
     *
     * @param array<string, PaymentMethod>                           $current
     * @param array<string, array{enabled: bool, name: string|null}> $changes
     *
     * @throws IntegrationException
     */
    private function assertNamesDistinct(array $current, array $changes): void
    {
        $shown = [];

        foreach ([PaymentType::Due, PaymentType::Giftcard, PaymentType::Rewards] as $type) {
            $shown[mb_strtolower(lang($type->languageKey()))] = true;
        }

        foreach ($current as $code => $method) {
            $name = array_key_exists($code, $changes) ? ($changes[$code]['name'] ?? $method->defaultName) : $method->shownAs();
            $key  = mb_strtolower($name);

            if (isset($shown[$key])) {
                throw new IntegrationException(IntegrationError::PaymentMethodNameTaken, "Two ways of payment would be shown as {$name}.", ['name' => $name]);
            }

            $shown[$key] = true;
        }
    }
}
