<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ItemRoutingAccounts;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingService;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Organization\CompanyContext;

/**
 * What the routing types that look at the item or at the entity of a document need: a row set to a
 * type posting does not apply on it, a row set to per item that has no account to fall back to, an
 * item that names an account which cannot take entries, and a row that names the account per entity
 * while an entity has none. Every one of them stops a posting instead of writing a journal that looks
 * right and is not, so each is listed before accounting is turned on.
 */
final class RoutingChecks extends CheckGroup
{
    private const ROUTING = ['accounting_routing', 'accounting_accounts.account_type', 'accounting_accounts.system_key'];

    public function run(): array
    {
        return [
            $this->check('routing_types', 'WP24-ROUTING-TYPES', 'Routing rows saved with a type posting does not apply on them', self::ROUTING, fn (): CheckResult => $this->unsupportedTypes()),
            $this->check('item_routing', 'WP24-ITEM-ROUTING', 'Per-item routing: the account to fall back to and the accounts items name', [...self::ROUTING, 'item_routing_accounts', 'items'], fn (): CheckResult => $this->itemRouting()),
            $this->check('entity_routing', 'WP24-ENTITY-ROUTING', 'Main account per entity: every entity has its account', [...self::ROUTING, 'accounting_accounts.entity_type', 'customers', 'suppliers', 'stock_locations', 'people'], fn (): CheckResult => $this->entityRouting()),
        ];
    }

    private function unsupportedTypes(): CheckResult
    {
        $findings = [];

        foreach (RoutingKey::cases() as $key) {
            $type = $this->typeOf($key);

            if (! $key->applies($type)) {
                $findings[] = "{$key->value} is set to {$type->value}";
            }
        }

        $data = ['rows' => count($findings)];

        if ($findings === []) {
            return CheckResult::pass('Every routing row is set to a type posting applies on it.', [], $data);
        }

        return CheckResult::fail(count($findings) . ' routing row(s) are set to a type posting does not apply on them: a posting that reaches one stops.', $findings, $data);
    }

    private function itemRouting(): CheckResult
    {
        $items   = new ItemRoutingAccounts($this->db);
        $counts  = $items->counts();
        $fails   = [];
        $reviews = [];
        $perItem = [];

        foreach (RoutingKey::perItemKeys() as $key) {
            if ($this->typeOf($key) !== RoutingType::PerItem) {
                continue;
            }

            $perItem[] = $key->value;
            $account   = $this->routedAccount($key);
            $problem   = RoutingService::accountProblem($account === null ? null : (int) $account->account_id, $account === null ? [] : [(int) $account->account_id => $account], true, $key->allowedAccountTypes(), $key->acceptedSystemKeys(), false);

            if ($problem !== null) {
                $fails[] = "{$key->value} is set to per item and has no account to fall back to: {$problem->value}";
            }
        }

        foreach ($items->problems() as $problem) {
            $line = sprintf('item %d (%s%s) names the account %s for %s: %s', $problem['item_id'], $problem['name'], $problem['deleted'] ? ', deleted' : '', $problem['code'], $problem['routing_key'], $problem['problem']);

            if (in_array($problem['routing_key'], $perItem, true)) {
                $fails[] = $line;
            } else {
                $reviews[] = $line . ' (the row is not set to per item, so nothing posts to it)';
            }
        }

        $data = ['per_item_rows' => $perItem, 'items_by_row' => $counts, 'problems' => count($fails) + count($reviews)];

        if ($fails !== []) {
            return CheckResult::fail(count($fails) . ' problem(s) in the per-item routing: a sale that reaches one stops.', [...$fails, ...$reviews], $data);
        }

        if ($reviews !== []) {
            return CheckResult::review(count($reviews) . ' item(s) name an account that cannot take entries; no row is set to per item for it today.', $reviews, $data);
        }

        return CheckResult::pass($perItem === [] ? 'No row is set to per item.' : count($perItem) . ' row(s) are set to per item, each with an account to fall back to, and no item names an account that cannot take entries.', [], $data);
    }

    private function entityRouting(): CheckResult
    {
        $entities = new EntityAccountService($this->db);
        $missing  = $entities->missingCounts();
        $findings = [];

        foreach (RoutingKey::cases() as $key) {
            $kind = $key->entityKind();

            if ($kind === null || ! in_array($kind, EntityAccountService::kinds(), true) || $this->typeOf($key) !== RoutingType::MainPerEntity || ! isset($missing[$kind])) {
                continue;
            }

            $findings[] = "{$key->value} names the account per entity and {$missing[$kind]} {$kind}(s) have none: a document for one of them stops";
        }

        $data = ['missing' => $missing];

        if ($findings === []) {
            return CheckResult::pass('Every entity of a row that names the account per entity has its account.', [], $data);
        }

        return CheckResult::fail(count($findings) . ' row(s) name the account per entity while entities have none. Run "php spark accounting:fill_accounts".', $findings, $data);
    }

    private function typeOf(RoutingKey $key): RoutingType
    {
        $row = $this->db->table('accounting_routing')->select('routing_type')->where('company_id', CompanyContext::id())->where('routing_key', $key->value)->get()->getRow();

        return ($row === null ? null : RoutingType::tryFrom((string) $row->routing_type)) ?? $key->defaultType();
    }

    private function routedAccount(RoutingKey $key): ?object
    {
        return $this->db->table('accounting_routing r')
            ->select('a.account_id, a.account_type, a.is_group, a.active, a.system_key')
            ->join('accounting_accounts a', 'a.account_id = r.account_id')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.routing_key', $key->value)
            ->get()
            ->getRow();
    }
}
