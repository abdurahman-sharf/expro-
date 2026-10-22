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

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->entities = new EntityAccountService($this->db);
    }

    /**
     * The account for the line, or null when the row is cancelled and the line must not be
     * written. For the rows of customers, suppliers, stock locations and treasuries the entity
     * says which sub-account.
     *
     * @throws PostingException
     */
    public function accountFor(RoutingKey $key, ?int $entityId = null): ?int
    {
        $route = $this->db->table('accounting_routing r')
            ->select('r.routing_type, r.account_id, a.is_group, a.active')
            ->join('accounting_accounts a', 'a.account_id = r.account_id', 'left')
            ->where('r.company_id', CompanyContext::id())
            ->where('r.routing_key', $key->value)
            ->get()
            ->getRow();

        $type = ($route === null ? null : RoutingType::tryFrom((string) $route->routing_type)) ?? $key->defaultType();

        if (! $type->isImplemented() || ! in_array($type, $key->allowedTypes(), true)) {
            throw new PostingException(
                PostingError::RoutingTypeUnsupported,
                "The routing of {$key->value} is set to {$type->value}, which posting does not apply.",
                ['routing_key' => $key->value, 'routing_type' => $type->value],
            );
        }

        return match ($type) {
            RoutingType::Cancelled => null,
            RoutingType::Auto      => $key->isEntity() ? $this->entityAccount($key, $entityId) : $this->mappedAccount($key, $route),
            default                => throw new PostingException(PostingError::RoutingTypeUnsupported, "The routing type {$type->value} has no rule.", ['routing_key' => $key->value, 'routing_type' => $type->value]),
        };
    }

    /**
     * @throws PostingException
     */
    private function mappedAccount(RoutingKey $key, ?object $route): int
    {
        if ($route === null || $route->account_id === null || (int) $route->is_group === 1 || (int) $route->active === 0) {
            throw new PostingException(PostingError::RoutingAccountUnusable, "The account chosen for {$key->value} is missing, a group or inactive.", ['routing_key' => $key->value]);
        }

        return (int) $route->account_id;
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
