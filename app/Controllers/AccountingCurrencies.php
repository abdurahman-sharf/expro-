<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\Currency;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;

class AccountingCurrencies extends AccountingController
{
    private Currencies $currencies;

    public function __construct()
    {
        parent::__construct('accounting_currencies');

        $this->currencies = new Currencies();
    }

    public function getIndex(): string
    {
        return view('accounting_currencies/manage', [
            'table_headers' => transform_headers($this->headers(), true, true),
            'can_manage'    => $this->canManage(AccountingGuard::CURRENCIES_MANAGE),
        ]);
    }

    public function getSearch(): ResponseInterface
    {
        $term   = mb_strtolower(trim((string) $this->request->getGet('search')));
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $rows   = $this->currencies->all();

        if ($term !== '') {
            $rows = array_values(array_filter($rows, static fn (Currency $currency): bool => str_contains(mb_strtolower($currency->code . ' ' . $currency->nameAr . ' ' . $currency->nameEn), $term)));
        }

        return $this->response->setJSON(['total' => count($rows), 'rows' => array_map(fn (Currency $currency): array => $this->row($currency), array_slice($rows, $offset, $limit))]);
    }

    public function getRow(string $code): ResponseInterface
    {
        $currency = $this->currencies->find($code);

        if ($currency === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $this->response->setJSON($this->row($currency));
    }

    public function getForm(string $code = ''): string
    {
        if (! $this->canManage(AccountingGuard::CURRENCIES_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $currency = $code === '' || $code === (string) NEW_ENTRY ? null : $this->currencies->find($code);

        if ($code !== '' && $code !== (string) NEW_ENTRY && $currency === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_currencies/form', [
            'currency' => $currency,
            'in_use'   => $currency !== null && $this->currencies->inUse($currency->code),
        ]);
    }

    public function postStore(string $code = ''): ResponseInterface
    {
        return $this->attempt(function () use ($code): array {
            $input = [
                'name_ar' => (string) $this->request->getPost('name_ar'),
                'name_en' => (string) $this->request->getPost('name_en'),
            ];
            $decimals = $this->request->getPost('decimals');

            if ($decimals !== null) {
                $input['decimals'] = (string) $decimals;
            }

            if ($code === '' || $code === (string) NEW_ENTRY) {
                $created = $this->currencies->create($input + ['code' => (string) $this->request->getPost('code')], $this->employeeId());

                return ['success' => true, 'message' => lang('Accounting_currencies.successful_adding'), 'id' => $created];
            }

            $this->currencies->update($code, $input, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_currencies.successful_updating'), 'id' => $code];
        });
    }

    public function postActivate(string $code): ResponseInterface
    {
        return $this->attempt(function () use ($code): array {
            $this->currencies->setActive($code, true, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_currencies.successful_activating'), 'id' => $code];
        });
    }

    public function postDeactivate(string $code): ResponseInterface
    {
        return $this->attempt(function () use ($code): array {
            $this->currencies->setActive($code, false, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_currencies.successful_deactivating'), 'id' => $code];
        });
    }

    public function getRates(string $code): string
    {
        $currency = $this->currencies->find($code);

        if ($currency === null || $this->currencies->isBase($code)) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_currencies/rates', [
            'currency'      => $currency,
            'table_headers' => transform_headers($this->rateHeaders(), true, true),
            'can_manage'    => $this->canManage(AccountingGuard::CURRENCIES_MANAGE),
        ]);
    }

    public function getRatesSearch(string $code): ResponseInterface
    {
        $limit  = max(1, min((int) $this->request->getGet('limit') ?: self::DEFAULT_PAGE_SIZE, 500));
        $offset = max(0, (int) $this->request->getGet('offset'));
        $rows   = $this->currencies->rates()->history($code);
        $manage = $this->canManage(AccountingGuard::CURRENCIES_MANAGE);

        $data = array_map(fn (object $rate): array => [
            'rate_id'        => (int) $rate->rate_id,
            'effective_date' => $this->formatDate($rate->effective_date),
            'rate'           => $this->amountCell(rtrim(rtrim((string) $rate->rate, '0'), '.')),
            'note'           => esc((string) $rate->note),
            'entered_name'   => esc((string) $rate->entered_name),
            'edit'           => $manage ? $this->rateActions($code, (int) $rate->rate_id) : '',
        ], array_slice($rows, $offset, $limit));

        return $this->response->setJSON(['total' => count($rows), 'rows' => $data]);
    }

    public function getRateForm(string $code, int $rateId = NEW_ENTRY): string
    {
        if (! $this->canManage(AccountingGuard::CURRENCIES_MANAGE)) {
            throw PageNotFoundException::forPageNotFound();
        }

        $currency = $this->currencies->find($code);
        $rate     = $rateId === NEW_ENTRY ? null : $this->currencies->rates()->find($rateId);

        if ($currency === null || $this->currencies->isBase($code) || ($rateId !== NEW_ENTRY && ($rate === null || $rate->currency_code !== $code))) {
            throw PageNotFoundException::forPageNotFound();
        }

        return view('accounting_currencies/rate_form', ['currency' => $currency, 'rate' => $rate, 'today' => date('Y-m-d')]);
    }

    public function postRateStore(string $code, int $rateId = NEW_ENTRY): ResponseInterface
    {
        return $this->attempt(function () use ($code, $rateId): array {
            $input = [
                'rate'           => (string) $this->request->getPost('rate'),
                'effective_date' => (string) $this->request->getPost('effective_date'),
                'note'           => (string) $this->request->getPost('note'),
            ];

            if ($rateId === NEW_ENTRY) {
                $id = $this->currencies->rates()->add($input + ['currency_code' => $code], $this->employeeId());

                return ['success' => true, 'message' => lang('Accounting_currencies.rate_successful_adding'), 'id' => $id];
            }

            $stored = $this->currencies->rates()->find($rateId);

            if ($stored === null || $stored->currency_code !== $code) {
                throw PageNotFoundException::forPageNotFound();
            }

            $this->currencies->rates()->update($rateId, $input, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_currencies.rate_successful_updating'), 'id' => $rateId];
        });
    }

    public function postRateDelete(int $rateId): ResponseInterface
    {
        return $this->attempt(function () use ($rateId): array {
            $this->currencies->rates()->delete($rateId, $this->employeeId());

            return ['success' => true, 'message' => lang('Accounting_currencies.rate_successful_deleting'), 'id' => $rateId];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function headers(): array
    {
        return [
            ['code' => lang('Accounting_currencies.code'), 'sortable' => false],
            ['name'        => lang('Accounting_currencies.name'), 'sortable' => false, 'escape' => false],
            ['decimals'    => lang('Accounting_currencies.decimals'), 'sortable' => false],
            ['latest_rate' => lang('Accounting_currencies.latest_rate'), 'sortable' => false, 'escape' => false],
            ['status'      => lang('Accounting.status'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rateHeaders(): array
    {
        return [
            ['effective_date' => lang('Accounting_currencies.effective_date'), 'sortable' => false],
            ['rate'         => lang('Accounting_currencies.rate'), 'sortable' => false, 'escape' => false],
            ['note'         => lang('Accounting_currencies.note'), 'sortable' => false, 'escape' => false],
            ['entered_name' => lang('Accounting_currencies.entered_by'), 'sortable' => false, 'escape' => false],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Currency $currency): array
    {
        $base = $this->currencies->isBase($currency->code);
        $rate = $base ? '1' : $this->currencies->rates()->rateOn($currency->code, date('Y-m-d'));

        return [
            'code'        => $currency->code,
            'name'        => esc(str_starts_with($this->request->getLocale(), 'ar') ? $currency->nameAr : $currency->nameEn) . ($base ? ' <span class="badge text-bg-primary">' . esc(lang('Accounting_currencies.base')) . '</span>' : ''),
            'decimals'    => $currency->decimals,
            'latest_rate' => $rate === null ? esc(lang('Accounting_currencies.no_rate')) : $this->amountCell(rtrim(rtrim($rate, '0'), '.')),
            'status'      => $this->badge($currency->active ? 'active' : 'inactive'),
            'edit'        => $this->actions($currency, $base),
        ];
    }

    private function actions(Currency $currency, bool $base): string
    {
        $html = '';

        if (! $base) {
            $html .= anchor("accounting_currencies/rates/{$currency->code}", '<span class="bi bi-graph-up" aria-hidden="true"></span>', ['title' => lang('Accounting_currencies.rates')]);
        }

        if (! $this->canManage(AccountingGuard::CURRENCIES_MANAGE)) {
            return $html;
        }

        $html .= anchor(
            "accounting_currencies/form/{$currency->code}",
            '<span class="bi bi-pencil-square" aria-hidden="true"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_currencies.update')],
        );

        if (! $base) {
            $toggle = $currency->active ? 'deactivate' : 'activate';
            $icon   = $currency->active ? 'bi-slash-circle' : 'bi-check-circle';
            $label  = esc(lang('Accounting_currencies.' . $toggle), 'attr');
            $html .= '<button type="button" class="btn btn-link p-0 acc-action" data-acc-action="' . $toggle . '" data-acc-id="' . esc($currency->code, 'attr') . '" title="' . $label . '" aria-label="' . $label . '"><span class="bi ' . $icon . '" aria-hidden="true"></span></button>';
        }

        return $html;
    }

    private function rateActions(string $code, int $rateId): string
    {
        $html = anchor(
            "accounting_currencies/rate_form/{$code}/{$rateId}",
            '<span class="bi bi-pencil-square" aria-hidden="true"></span>',
            ['class' => 'modal-dlg', 'data-btn-submit' => lang('Common.submit'), 'title' => lang('Accounting_currencies.update_rate')],
        );
        $delete = esc(lang('Common.delete'), 'attr');

        return $html . '<button type="button" class="btn btn-link p-0 acc-action text-danger" data-acc-action="delete" data-acc-id="' . $rateId . '" title="' . $delete . '" aria-label="' . $delete . '"><span class="bi bi-trash" aria-hidden="true"></span></button>';
    }
}
