<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Decides which account a line of a posting goes to. It is the only way posting code may read
 * the routing: a row can be saved with a type that nothing applies yet, and reading the account
 * of such a row directly would write a journal that looks right and is not. Here it stops with
 * a named failure instead.
 */
final class RoutingResolver
{
    private BaseConnection $db;
    private EntityAccountService $entities;
    private ItemRoutingAccounts $items;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->entities = new EntityAccountService($this->db);
        $this->items    = new ItemRoutingAccounts($this->db);
    }

    /**
     * The account for the line, or null when the row is cancelled and the line must not be
     * written. For the rows of customers, suppliers, stock locations and treasuries the entity
     * says which sub-account, and for a row that takes the accounts of products the item says
     * which account, falling back to the account of the row when it has none of its own.
     *
     * @throws PostingException
     */
    public function accountFor(RoutingKey $key, ?int $entityId = null, ?int $itemId = null): ?int
    {
        $route = $this->route($key->value);
        $type  = $this->appliedType($key, $route);

        return match ($type) {
            RoutingType::Cancelled     => null,
            RoutingType::Auto          => $key->isEntity() ? $this->entityAccount($key, $entityId) : $this->mappedAccount($key->value, $route),
            RoutingType::PerItem       => $this->perItemAccount($key, $route, $itemId),
            RoutingType::MainPerEntity => $this->mainPerEntityAccount($key, $route, $entityId),
        };
    }

    /**
     * The type the row is set to, which is the default of the row until somebody saves another.
     */
    public function typeOf(RoutingKey $key): RoutingType
    {
        return $this->effectiveType($key, $this->route($key->value));
    }

    /**
     * The account of every item for a row that takes the accounts of products, and whether the item
     * named it itself or fell back to the account of the row. An account an item names that cannot
     * take entries any more stops the posting: the line would otherwise land somewhere nobody chose.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, ItemRoutedAccount> By item.
     *
     * @throws PostingException
     */
    public function itemAccountsFor(RoutingKey $key, array $itemIds): array
    {
        $route = $this->route($key->value);

        if ($this->appliedType($key, $route) !== RoutingType::PerItem) {
            throw new PostingException(PostingError::RoutingTypeUnsupported, "The routing of {$key->value} is not set to the accounts of products.", ['routing_key' => $key->value, 'routing_type' => $this->effectiveType($key, $route)->value]);
        }

        $own      = $this->items->forItems($key, array_values(array_unique($itemIds)));
        $fallback = null;
        $accounts = [];

        foreach ($itemIds as $itemId) {
            if (isset($accounts[$itemId])) {
                continue;
            }

            if (isset($own[$itemId])) {
                $accounts[$itemId] = new ItemRoutedAccount($this->usableItemAccount($key, $itemId, $own[$itemId]), true);

                continue;
            }

            $fallback ??= $this->mappedAccount($key->value, $route);

            $accounts[$itemId] = new ItemRoutedAccount($fallback, false);
        }

        return $accounts;
    }

    /**
     * The account a way of paying is posted to, or null for a payment that settles nothing: a
     * credit sale stays on the customer. Gift cards follow their liability, and every other
     * way of paying follows the account its row has.
     *
     * @throws PostingException
     */
    public function accountForPayment(PaymentType $type): ?int
    {
        if ($type === PaymentType::Due) {
            return null;
        }

        $locked = $type->lockedRoutingKey();

        if ($locked !== null) {
            return $this->accountFor($locked);
        }

        $route = $this->db->table('accounting_routing r')
            ->select('r.account_id, a.is_group, a.active')
            ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.routing_key', $type->routingKey())
            ->get()
            ->getRow();

        return $this->mappedAccount($type->routingKey(), $route);
    }

    /**
     * A system account that no routing row points to, by the key the chart gave it. It is for the
     * rare posting that needs one fixed account, such as what is owed to parties that are not
     * suppliers, and it stops with a named failure when the account cannot take entries.
     *
     * @throws PostingException
     */
    public function systemAccount(string $systemKey): int
    {
        $account = $this->db->table('accounting_accounts')
            ->select('account_id, is_group, active')
            ->where('company_id', CompanyContext::id())
            ->where('system_key', $systemKey)
            ->get()
            ->getRow();

        if ($account === null || (int) $account->is_group === 1 || (int) $account->active === 0) {
            throw new PostingException(PostingError::RoutingAccountUnusable, "The system account {$systemKey} is missing, a group or inactive.", ['routing_key' => $systemKey]);
        }

        return (int) $account->account_id;
    }

    /**
     * @throws PostingException
     */
    private function mappedAccount(string $routingKey, ?object $route): int
    {
        if ($route === null || $route->account_id === null || (int) $route->is_group === 1 || (int) $route->active === 0) {
            throw new PostingException(PostingError::RoutingAccountUnusable, "The account chosen for {$routingKey} is missing, a group or inactive.", ['routing_key' => $routingKey]);
        }

        return (int) $route->account_id;
    }

    private function route(string $routingKey): ?object
    {
        return $this->db->table('accounting_routing r')
            ->select('r.routing_type, r.account_id, a.is_group, a.active')
            ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.routing_key', $routingKey)
            ->get()
            ->getRow();
    }

    private function effectiveType(RoutingKey $key, ?object $route): RoutingType
    {
        return ($route === null ? null : RoutingType::tryFrom((string) $route->routing_type)) ?? $key->defaultType();
    }

    /**
     * @throws PostingException
     */
    private function appliedType(RoutingKey $key, ?object $route): RoutingType
    {
        $type = $this->effectiveType($key, $route);

        if (! $key->applies($type) || ! in_array($type, $key->allowedTypes(), true)) {
            throw new PostingException(
                PostingError::RoutingTypeUnsupported,
                "The routing of {$key->value} is set to {$type->value}, which posting does not apply.",
                ['routing_key' => $key->value, 'routing_type' => $type->value],
            );
        }

        return $type;
    }

    /**
     * @throws PostingException
     */
    private function perItemAccount(RoutingKey $key, ?object $route, ?int $itemId): int
    {
        if ($itemId === null) {
            return $this->mappedAccount($key->value, $route);
        }

        $own = $this->items->forItems($key, [$itemId])[$itemId] ?? null;

        return $own === null ? $this->mappedAccount($key->value, $route) : $this->usableItemAccount($key, $itemId, $own);
    }

    /**
     * @throws PostingException
     */
    private function usableItemAccount(RoutingKey $key, int $itemId, object $account): int
    {
        $problem = $this->items->refusal($key, $account);

        if ($problem !== null) {
            throw new PostingException(
                PostingError::ItemAccountUnusable,
                "The account the item names for {$key->value} cannot take entries.",
                ['routing_key' => $key->value, 'item_id' => $itemId, 'account_id' => (int) $account->account_id, 'problem' => $problem->value],
            );
        }

        return (int) $account->account_id;
    }

    /**
     * The sub-account of the entity of the document, and for a document that has none the account
     * of the row when it takes entries. A document that has an entity never lands on the account
     * of the row: its balance would leave the entity.
     *
     * @throws PostingException
     */
    private function mainPerEntityAccount(RoutingKey $key, ?object $route, ?int $entityId): int
    {
        if ($entityId === null && $route !== null && $route->account_id !== null && (int) $route->is_group === 0 && (int) $route->active === 1) {
            return (int) $route->account_id;
        }

        return $this->entityAccount($key, $entityId);
    }

    /**
     * @throws PostingException
     */
    private function entityAccount(RoutingKey $key, ?int $entityId): int
    {
        $accountId = $entityId === null ? null : $this->entities->accountFor((string) $key->entityKind(), $entityId);

        if ($accountId === null) {
            throw new PostingException(PostingError::EntityAccountMissing, "The {$key->entityKind()} has no account of its own yet.", ['routing_key' => $key->value, 'entity_id' => $entityId]);
        }

        return $accountId;
    }
}
