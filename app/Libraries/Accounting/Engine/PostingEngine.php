<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\DocumentCurrency;
use App\Libraries\Accounting\ForeignConversion;
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
        $set     = $this->rules->current($facts->operation, $facts->date);
        $convert = $set->foreignRounding !== null && $facts->currencyCode !== null;
        $lines   = [];
        $made    = [];

        try {
            foreach ($this->groups($set->lines) as [$over, $rules]) {
                foreach ($over === null ? [[]] : ($facts->lists[$over] ?? []) as $entry) {
                    foreach ($rules as $rule) {
                        $line = $this->line($rule, $facts, $entry, $convert);

                        if ($line !== null) {
                            $lines[] = $line;
                            $made[]  = $rule->lineNo;
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

        $draft = new JournalDraft(
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

        return $convert ? $this->inBase($draft, $made, $set->foreignRounding, $facts) : $draft;
    }

    /**
     * A journal written in the currency of its document, brought to the base as the code brings one: each
     * line converted on its own at the rate of the document, and the rounding put on the line the rules
     * name. The largest debit is the first line whose debit is more than every one before it, among the
     * lines that no line of the rules in except made, and the first line of the journal when there is none.
     *
     * @param list<int>                                                                         $made     The line of the rules each line of the journal came from.
     * @param array{balancing: string, except?: list<int>, lines?: list<int>, else?: list<int>} $rounding
     *
     * @throws PostingException
     */
    private function inBase(JournalDraft $draft, array $made, array $rounding, PostingFacts $facts): JournalDraft
    {
        if ($facts->currencyRate === null) {
            throw new PostingException(PostingError::ForeignRateMismatch, 'A document in a foreign currency has no rate to bring its journal to the base.', ['code' => (string) $facts->currencyCode]);
        }

        $balancing = 0;

        if ($rounding['balancing'] === 'largest_debit') {
            $largest = '0';
            $except  = $rounding['except'] ?? [];

            foreach ($draft->lines as $index => $line) {
                if (! in_array($made[$index], $except, true) && bccomp($line->debit, $largest, self::SCALE) > 0) {
                    $largest   = $line->debit;
                    $balancing = $index;
                }
            }
        }

        if ($rounding['balancing'] === 'largest_of') {
            $balancing = $this->largestOf($draft, $made, $rounding['lines'], $rounding['else'] ?? []);
        }

        return ForeignConversion::toBase($draft, new DocumentCurrency((string) $facts->currencyCode, $facts->currencyRate, 2), $facts->baseDecimals, $balancing);
    }

    /**
     * The first of the largest lines, on either side, that the lines of the rules given made; when they
     * made none, the first line the lines in else made.
     *
     * @param list<int> $made
     * @param list<int> $lines
     * @param list<int> $else
     *
     * @throws PostingException
     */
    private function largestOf(JournalDraft $draft, array $made, array $lines, array $else): int
    {
        $largest = null;

        foreach ($draft->lines as $index => $line) {
            if (! in_array($made[$index], $lines, true)) {
                continue;
            }

            $amount = bccomp($line->debit, '0', self::SCALE) > 0 ? $line->debit : $line->credit;

            if ($largest === null || bccomp($amount, $largest[1], self::SCALE) > 0) {
                $largest = [$index, $amount];
            }
        }

        if ($largest !== null) {
            return $largest[0];
        }

        foreach ($draft->lines as $index => $line) {
            if (in_array($made[$index], $else, true)) {
                return $index;
            }
        }

        throw new PostingException(PostingError::ForeignRoundingTooBig, 'The line that balances the journal is not one of its lines.', ['lines' => implode(',', [...$lines, ...$else])]);
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
    private function line(RuleLine $rule, PostingFacts $facts, array $entry, bool $convert): ?JournalLineDraft
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
        $foreign   = $convert || $rule->foreignAmount === null || $facts->currencyCode === null ? null : ltrim(PostingExpression::evaluate($rule->foreignAmount, $figures), '-');
        $center    = $rule->costCenter === null ? null : $fields[$rule->costCenter['field']] ?? null;

        return new JournalLineDraft(
            $account,
            debit: $debit ? $magnitude : '0',
            credit: $debit ? '0' : $magnitude,
            costCenterId: $center === null ? null : (int) $center,
            foreignAmount: $foreign,
            currencyCode: $foreign === null ? null : $facts->currencyCode,
            autoTax: $rule->autoTax,
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

        if (($rule->account['else'] ?? null) === 'supplier_required') {
            throw new PostingException(PostingError::SupplierRequired, 'A purchase that is not paid at once needs a supplier to owe it.', ['line' => $rule->lineNo]);
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
