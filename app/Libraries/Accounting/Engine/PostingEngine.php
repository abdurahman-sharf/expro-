<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PaymentType;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingResolver;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Builds the journal of a document from the posting rules of its operation and the facts of the
 * document. It writes nothing: the journal it returns goes to the posting service like any other.
 * Which account each line takes is decided here, from the rules and the routing, and nowhere else.
 */
final class PostingEngine
{
    private const SCALE = 4;

    private BaseConnection $db;
    private PostingRules $rules;
    private RoutingResolver $resolver;

    public function __construct(?BaseConnection $db = null, ?PostingRules $rules = null, ?RoutingResolver $resolver = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->rules    = $rules ?? new PostingRules($this->db);
        $this->resolver = $resolver ?? new RoutingResolver($this->db);
    }

    public function rules(): PostingRules
    {
        return $this->rules;
    }

    /**
     * The journal the rules of the operation make of the facts, or null when every line comes to nothing
     * or a line reaches a place that posts no journal, whose condition the facts hold (a payment left on
     * credit). A line that finds no account otherwise is refused.
     *
     * @throws PostingException
     */
    public function build(PostingFacts $facts): ?JournalDraft
    {
        $set   = $this->rules->current($facts->operation, $facts->date);
        $lines = [];

        try {
            foreach ($this->groups($set->lines) as [$over, $rules]) {
                foreach ($over === null ? [[]] : ($facts->lists[$over] ?? []) as $entry) {
                    foreach ($rules as $rule) {
                        $line = $this->line($rule, $facts, $entry);

                        if ($line !== null) {
                            $lines[] = $line;
                        }
                    }
                }
            }
        } catch (NoJournal) {
            return null;
        }

        if ($lines === []) {
            return null;
        }

        return new JournalDraft(
            date: $facts->date,
            lines: $lines,
            sourceType: $set->sourceType,
            sourceId: $facts->sourceId,
            revision: $facts->revision,
            origin: $facts->origin,
            employeeId: $facts->employeeId,
            description: $facts->description ?? str_replace('{source_id}', (string) $facts->sourceId, $set->description),
            branchId: $facts->branchId,
            entryType: $set->entryType,
        );
    }

    /**
     * The lines of the rules in runs: a line that repeats over a list runs with the lines right after
     * it that repeat over the same list, entry by entry, so that the lines of one entry stand together.
     *
     * @param list<RuleLine> $rules
     *
     * @return list<array{?string, list<RuleLine>}>
     */
    private function groups(array $rules): array
    {
        $groups = [];

        foreach ($rules as $rule) {
            $last = array_key_last($groups);

            if ($rule->repeatOver !== null && $last !== null && $groups[$last][0] === $rule->repeatOver) {
                $groups[$last][1][] = $rule;
            } else {
                $groups[] = [$rule->repeatOver, [$rule]];
            }
        }

        return $groups;
    }

    /**
     * @param array{figures?: array<string, string>, fields?: array<string, int|string|null>, flags?: array<string, bool>} $entry
     *
     * @throws PostingException
     */
    private function line(RuleLine $rule, PostingFacts $facts, array $entry): ?JournalLineDraft
    {
        $figures = array_merge($facts->figures, $entry['figures'] ?? []);
        $fields  = array_merge($facts->fields, $entry['fields'] ?? []);
        $flags   = array_merge($facts->flags, $entry['flags'] ?? []);
        $amount  = PostingExpression::evaluate($rule->amount, $figures);
        $sign    = bccomp($amount, '0', self::SCALE);

        if ($sign === 0) {
            return null;
        }

        $magnitude = ltrim($amount, '-');
        $debit     = ($rule->side === 'debit') === ($sign > 0);
        $account   = $this->account($rule, $fields, $flags);
        $foreign   = $rule->foreignAmount === null || $facts->currencyCode === null ? null : ltrim(PostingExpression::evaluate($rule->foreignAmount, $figures), '-');
        $center    = $rule->costCenter === null ? null : $fields[$rule->costCenter['field']] ?? null;

        return new JournalLineDraft(
            $account,
            debit: $debit ? $magnitude : '0',
            credit: $debit ? '0' : $magnitude,
            costCenterId: $center === null ? null : (int) $center,
            foreignAmount: $foreign,
            currencyCode: $foreign === null ? null : $facts->currencyCode,
        );
    }

    /**
     * The first place of the line that gives an account. A line of an exchange difference fails as one
     * whatever kept its account from being found.
     *
     * @param array<string, int|string|null> $fields
     * @param array<string, bool>            $flags
     *
     * @throws PostingException
     */
    private function account(RuleLine $rule, array $fields, array $flags): int
    {
        if (($rule->account['else'] ?? null) !== 'fx_accounts_missing') {
            return $this->firstAccount($rule, $fields, $flags);
        }

        try {
            return $this->firstAccount($rule, $fields, $flags);
        } catch (PostingException $failure) {
            if ($failure->error === PostingError::FxAccountsMissing) {
                throw $failure;
            }

            throw new PostingException(PostingError::FxAccountsMissing, "Line {$rule->lineNo} of the rules has no usable account, so the exchange difference cannot be posted.", ['line' => $rule->lineNo], $failure);
        }
    }

    /**
     * @param array<string, int|string|null> $fields
     * @param array<string, bool>            $flags
     *
     * @throws PostingException
     */
    private function firstAccount(RuleLine $rule, array $fields, array $flags): int
    {
        $lastRole = null;

        foreach ($rule->account['first'] as $source) {
            if (isset($source['when']) && ! ($flags[$source['when']] ?? false)) {
                continue;
            }

            if (isset($source['no_journal'])) {
                throw new NoJournal();
            }

            $account = match (true) {
                isset($source['field'])   => $this->field($fields, $source['field']),
                isset($source['system'])  => $this->resolver->systemAccount($source['system']),
                isset($source['payment']) => $this->payment($fields, $source['payment']),
                default                   => $this->role($source, $fields, $lastRole),
            };

            if ($account !== null) {
                return $account;
            }
        }

        if (($rule->account['else'] ?? null) === 'entity_account_missing') {
            throw new PostingException(PostingError::EntityAccountMissing, 'The party of line ' . $rule->lineNo . ' has no account of its own yet.', ['routing_key' => $lastRole ?? '']);
        }

        if (($rule->account['else'] ?? null) === 'fx_accounts_missing') {
            throw new PostingException(PostingError::FxAccountsMissing, 'The routing of ' . ($lastRole ?? 'a line') . ' has no account, so the exchange difference cannot be posted.', ['routing_key' => $lastRole ?? '']);
        }

        if (($rule->account['else'] ?? null) === 'payment_type_unknown') {
            $type = (string) ($fields['payment_type'] ?? '');

            throw new PostingException(PostingError::PaymentTypeUnknown, "The payment type {$type} has no account to be paid from.", ['payment_type' => $type]);
        }

        throw new PostingException(PostingError::RoutingRowCancelled, 'The routing of ' . ($lastRole ?? 'a line') . " is cancelled, but line {$rule->lineNo} of the rules cannot be posted without it.", ['routing_key' => $lastRole ?? '', 'line' => $rule->lineNo]);
    }

    /**
     * @param array<string, int|string|null> $fields
     */
    private function field(array $fields, string $name): ?int
    {
        $value = $fields[$name] ?? null;

        return $value === null || $value === '' ? null : (int) $value;
    }

    /**
     * @param array<string, string>          $source
     * @param array<string, int|string|null> $fields
     *
     * @throws PostingException
     */
    private function role(array $source, array $fields, ?string &$lastRole): ?int
    {
        $lastRole = $source['role'];
        $entity   = isset($source['entity']) ? $this->field($fields, $source['entity']) : null;

        if (isset($source['entity']) && $entity === null) {
            return null;
        }

        return $this->resolver->accountFor(RoutingKey::from($source['role']), $entity);
    }

    /**
     * @param array<string, int|string|null> $fields
     *
     * @throws PostingException
     */
    private function payment(array $fields, string $name): ?int
    {
        $type = PaymentType::fromLabel((string) ($fields[$name] ?? ''));

        return $type === null ? null : $this->resolver->accountForPayment($type);
    }
}
