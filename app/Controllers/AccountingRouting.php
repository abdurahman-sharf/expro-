<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\RoutingException;
use App\Libraries\Accounting\RoutingItem;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingService;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Organization\BranchException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingRouting extends AccountingController
{
    private RoutingService $routing;

    public function __construct()
    {
        parent::__construct('accounting_routing', false);

        $this->routing = new RoutingService(null, null, $this->branchGuard());
    }

    public function getIndex(): string
    {
        $items   = $this->routing->items();
        $pending = array_filter($items, static fn (RoutingItem $item): bool => in_array($item->status, [RoutingItem::MISSING, RoutingItem::INVALID], true));

        $sections = [];

        foreach ([...RoutingKey::GROUPS, 'payments'] as $group) {
            $sections[$group] = $this->rows($items, $group);
        }

        return view('accounting_routing/manage', [
            'sections'   => $sections,
            'pending'    => array_values(array_map(fn (RoutingItem $item): array => ['key' => $item->key, 'label' => $this->itemLabel($item)], $pending)),
            'can_manage' => $this->canManage(AccountingGuard::ROUTING_MANAGE),
            'can_review' => $this->can(AccountingGuard::ROUTING_MANAGE),
        ]);
    }

    public function postSaveRouting(): ResponseInterface
    {
        $input = $this->request->getPost('route');

        try {
            $this->routing->save(is_array($input) ? $input : [], $this->employeeId());

            return $this->response->setJSON(['success' => true, 'message' => lang('Accounting_routing.saved'), 'id' => 1]);
        } catch (RoutingException $exception) {
            return $this->response->setJSON([
                'success' => false,
                'message' => lang('Accounting.error_routing_invalid', [(string) count($exception->errors)]),
                'errors'  => array_map(static fn (AccountingError $error): string => lang('Accounting.error_' . $error->value), $exception->errors),
                'id'      => NEW_ENTRY,
            ]);
        } catch (AccountingException|BranchException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => $this->messageFor($exception), 'id' => NEW_ENTRY]);
        }
    }

    /**
     * @param list<RoutingItem> $items
     *
     * @return list<array<string, mixed>>
     */
    private function rows(array $items, string $group): array
    {
        $rows = [];

        foreach ($items as $item) {
            if ($item->group !== $group) {
                continue;
            }

            $options = [];

            if (! $item->locked) {
                foreach ($this->routing->candidates($item->allowedTypes, $item->isEntity) as $account) {
                    $options[] = ['id' => (int) $account->account_id, 'text' => $account->code . ' — ' . $account->name];
                }
            }

            $types = array_map(
                static fn (string $type): array => ['value' => $type, 'text' => lang('Accounting_routing.type_' . ($type === RoutingType::Cancelled->value && $item->cancelMeansNone ? 'none' : $type))],
                $item->routingTypes,
            );

            $rows[] = [
                'key'          => $item->key,
                'label'        => $this->itemLabel($item),
                'locked'       => $item->locked,
                'locked_to'    => $item->lockedTo === null ? null : lang('Accounting_routing.item_' . $this->languageSuffix($item->lockedTo)),
                'status'       => $item->status,
                'account_id'   => $item->accountId,
                'account_text' => $item->accountCode === null ? '' : $item->accountCode . ' — ' . $item->accountName,
                'default_id'   => $item->defaultAccountId,
                'default_text' => $item->defaultAccountCode === null ? '' : $item->defaultAccountCode . ' — ' . $item->defaultAccountName,
                'options'      => $options,
                'required'     => $item->required,
                'type'         => $item->type,
                'types'        => $types,
                'entity'       => $item->isEntity,
            ];
        }

        return $rows;
    }

    private function itemLabel(RoutingItem $item): string
    {
        return lang('Accounting_routing.' . ($item->group === 'payments' ? 'payment_' : 'item_') . $this->languageSuffix($item->label));
    }

    private function languageSuffix(string $key): string
    {
        return str_replace('.', '_', $key);
    }
}
