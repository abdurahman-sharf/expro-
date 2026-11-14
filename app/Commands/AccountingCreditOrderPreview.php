<?php

namespace App\Commands;

use App\Libraries\Accounting\CreditOrderMove;
use App\Libraries\Accounting\CreditOrderPlan;
use App\Libraries\Accounting\CreditOrderPreview;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class AccountingCreditOrderPreview extends BaseCommand
{
    protected $group       = 'Accounting';
    protected $name        = 'accounting:credit_order_preview';
    protected $usage       = 'accounting:credit_order_preview [--customer ID] [--json]';
    protected $description = 'Reads, and writes nothing, which open items of the customers would have their credit moved by the order of E57: what the next settlement run would change, and the items whose credit is not where the order would put it. Exits 0, and 7 for an option it refuses.';
    protected $options     = [
        '--customer' => 'Only this customer (the number of the person).',
        '--json'     => 'Print the report as JSON and nothing else (add spark\'s own --no-header, or its banner comes first).',
    ];

    /**
     * @param array<int|string, string|null> $params
     */
    public function run(array $params): int
    {
        $customer = $this->customer($params);

        if ($customer === false) {
            CLI::error('The customer must be the number of a person.');

            return EXIT_USER_INPUT;
        }

        $preview = new CreditOrderPreview();
        $plans   = $customer === null ? $preview->all() : array_values(array_filter([$preview->forCustomer($customer)], static fn (CreditOrderPlan $plan): bool => ! $plan->isEmpty()));

        if (array_key_exists('json', $params) || CLI::getOption('json') !== null) {
            CLI::write(json_encode(array_map($this->toArray(...), $plans), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return EXIT_SUCCESS;
        }

        if ($plans === []) {
            CLI::write('No open item would have its credit moved, and none holds credit the order would put elsewhere.');

            return EXIT_SUCCESS;
        }

        foreach ($plans as $plan) {
            $this->print($plan);
        }

        return EXIT_SUCCESS;
    }

    /**
     * @param array<int|string, string|null> $params
     *
     * @return false|int|null The customer, null when none was asked for, false when the option has no usable number.
     */
    private function customer(array $params): false|int|null
    {
        $given = array_key_exists('customer', $params) || CLI::getOption('customer') !== null;
        $value = $params['customer'] ?? CLI::getOption('customer');

        foreach (array_keys($params) as $key) {
            if (is_string($key) && str_starts_with($key, 'customer=')) {
                $given = true;
                $value = substr($key, strlen('customer='));
            }
        }

        if (! $given) {
            return null;
        }

        return is_string($value) && ctype_digit($value) && (int) $value >= 1 ? (int) $value : false;
    }

    private function print(CreditOrderPlan $plan): void
    {
        CLI::write('Customer ' . $plan->customerId . ' ' . $plan->customerName, 'yellow');

        if ($plan->blocked !== null) {
            CLI::write('  The next settlement run would be refused: ' . $plan->blocked->value . ' (a credit that was paid back in cash would shrink).', 'red');
        }

        foreach ($plan->moves as $move) {
            CLI::write('  A run would move ' . $this->line($move));
        }

        foreach ($plan->differences as $move) {
            CLI::write('  Not where the order puts it: ' . $this->line($move));
        }

        CLI::newLine();
    }

    private function line(CreditOrderMove $move): string
    {
        return $move->kind . ' ' . $move->documentId . ($move->date === '' ? '' : ' of ' . $move->date) . ': ' . $move->beforeTotal() . ' -> ' . $move->afterTotal() . ' (' . $this->sources($move->before) . ' -> ' . $this->sources($move->after) . ')';
    }

    /**
     * @param array<string, string> $amounts
     */
    private function sources(array $amounts): string
    {
        return $amounts === [] ? 'none' : implode(', ', array_map(static fn (string $source, string $amount): string => $source . ' ' . bcadd($amount, '0', 2), array_keys($amounts), $amounts));
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(CreditOrderPlan $plan): array
    {
        $move = static fn (CreditOrderMove $each): array => ['kind' => $each->kind, 'id' => $each->documentId, 'date' => $each->date, 'before' => $each->before, 'after' => $each->after];

        return [
            'customer_id' => $plan->customerId,
            'name'        => $plan->customerName,
            'blocked'     => $plan->blocked?->value,
            'moves'       => array_map($move, $plan->moves),
            'differences' => array_map($move, $plan->differences),
        ];
    }
}
