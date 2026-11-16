<?php

namespace App\Libraries\Accounting;

/**
 * What the chart of accounts offers on a row. It offers an action only when the service would carry it out, by the
 * same conditions the service checks, so a button never answers with a refusal the screen could have foreseen. The
 * service keeps checking all of it.
 */
final class ChartRowActions
{
    public const ADD_CHILD  = 'add_child';
    public const EDIT       = 'edit';
    public const DEACTIVATE = 'deactivate';
    public const ACTIVATE   = 'activate';
    public const DELETE     = 'delete';
    public const LEDGER     = 'ledger';

    /**
     * @param object $account      A row of AccountService with its flags.
     * @param bool   $canManage    Whether the person may change the chart, which also takes the whole company.
     * @param bool   $canLedger    Whether the person may open the account statement.
     * @param bool   $parentActive Whether the parent is active, which an inactive account needs to be activated.
     *
     * @return list<string>
     */
    public static function for(object $account, bool $canManage, bool $canLedger, bool $parentActive = true): array
    {
        $actions = [];
        $active  = (int) $account->active === 1;
        $system  = (int) $account->is_system === 1;
        $flag    = static fn (string $name): bool => (int) ($account->{$name} ?? 0) === 1;

        if ($canManage) {
            if ($active && (int) $account->is_group === 1) {
                $actions[] = self::ADD_CHILD;
            }

            $actions[] = self::EDIT;

            if ($active && ! $system && ! $flag('routed') && ! $flag('stamped') && ! $flag('has_active_children')) {
                $actions[] = self::DEACTIVATE;
            }

            if (! $active && $parentActive) {
                $actions[] = self::ACTIVATE;
            }

            if (! $system && ! $flag('has_children') && ! $flag('used') && ! $flag('routed') && ! $flag('stamped')) {
                $actions[] = self::DELETE;
            }
        }

        if ($canLedger) {
            $actions[] = self::LEDGER;
        }

        return $actions;
    }
}
