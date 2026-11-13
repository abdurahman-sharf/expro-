<?php

namespace App\Controllers;

use App\Libraries\Integrations\EntityTranslation;
use App\Libraries\Integrations\EntityTranslations;
use App\Libraries\Integrations\IntegrationAccess;
use App\Libraries\Integrations\IntegrationOptions;
use App\Libraries\Integrations\NotificationRuleKind;
use App\Libraries\Integrations\NotificationRules;
use App\Libraries\Integrations\PaymentMethod;
use App\Libraries\Integrations\PaymentMethods;
use App\Libraries\Integrations\TranslationKind;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The hub of the integration settings that need no outside service, and the screens of the payment methods
 * the register offers, of the second language of the records, and of the rules of in-app notifications. The
 * keys of the API have a screen of their own, which needs a sensitive permission. Outbound sending, gateways
 * and webhooks are not built: nothing here sends anything anywhere.
 */
class Integrations extends IntegrationController
{
    private const PAGE_SIZE = 50;

    public function __construct()
    {
        parent::__construct(IntegrationAccess::MODULE);
    }

    public function getIndex(): string
    {
        $options = new IntegrationOptions();

        return view('integrations/manage', [
            'options'   => $options->all(),
            'locales'   => IntegrationOptions::locales(),
            'canManage' => $this->access->mayManage($this->employeeId()),
            'canKeys'   => $this->access->mayManageKeys($this->employeeId()),
            'canRoute'  => $this->employee->has_module_grant('accounting_routing', $this->employeeId()),
        ]);
    }

    public function postOptions(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $input = [];

            foreach (['payment_methods', 'second_language', 'second_language_print', 'api', 'notifications'] as $flag) {
                if ($this->request->getPost($flag) !== null) {
                    $input[$flag] = $this->request->getPost($flag) === '1';
                }
            }

            if ($this->request->getPost('second_language_code') !== null) {
                $input['second_language_code'] = $this->postedText('second_language_code');
            }

            (new IntegrationOptions(null, $this->access))->update($input, $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.options_saved'), 'id' => 1];
        });
    }

    public function getPaymentMethods(): string
    {
        $this->requireManage();

        $methods = new PaymentMethods(null, $this->access);

        return view('integrations/payment_methods', [
            'methods'  => $methods->all(),
            'routes'   => $this->routes($methods->all(), $methods),
            'custom'   => (new IntegrationOptions())->paymentMethodsCustom(),
            'canRoute' => $this->employee->has_module_grant('accounting_routing', $this->employeeId()),
        ]);
    }

    public function postPaymentMethods(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $enabled = $this->request->getPost('enabled');
            $names   = $this->request->getPost('name');
            $input   = [];

            foreach (array_unique([...array_keys(is_array($enabled) ? $enabled : []), ...array_keys(is_array($names) ? $names : [])]) as $code) {
                $row = [];

                if (is_array($enabled) && array_key_exists($code, $enabled)) {
                    $row['enabled'] = $enabled[$code];
                }

                if (is_array($names) && array_key_exists($code, $names)) {
                    $row['name'] = $names[$code];
                }

                $input[(string) $code] = $row;
            }

            (new PaymentMethods(null, $this->access))->save($input, $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.payment_methods_saved'), 'id' => 1];
        });
    }

    public function getTranslations(): string
    {
        $this->requireManage();

        $kind   = TranslationKind::tryFrom($this->requestText('kind'));
        $page   = max(1, (int) $this->request->getGet('page'));
        $found  = (new EntityTranslations(null, $this->access))->search($kind, $this->requestText('search'), self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE);
        $option = new IntegrationOptions();

        return view('integrations/translations', [
            'rows'     => $found['rows'],
            'total'    => $found['total'],
            'page'     => $page,
            'pages'    => max(1, (int) ceil($found['total'] / self::PAGE_SIZE)),
            'kind'     => $kind,
            'search'   => $this->requestText('search'),
            'language' => $option->secondLanguageCode(),
            'locales'  => IntegrationOptions::locales(),
            'enabled'  => $option->secondLanguageEnabled(),
            'printing' => $option->secondLanguagePrint(),
            'editing'  => $this->editing(),
        ]);
    }

    public function postTranslationSave(): ResponseInterface
    {
        return $this->attempt(function (): array {
            $language = (new IntegrationOptions())->secondLanguageCode();
            $id       = (new EntityTranslations(null, $this->access))->save(
                $this->postedText('kind'),
                $this->postedText('key'),
                $this->postedText('language') !== '' ? $this->postedText('language') : $language,
                $this->postedText('name'),
                $this->postedText('address_note'),
                $this->employeeId(),
            );

            return ['success' => true, 'message' => lang('Integrations.translation_saved'), 'id' => $id];
        });
    }

    public function postTranslationDelete(int $translationId): ResponseInterface
    {
        return $this->attempt(function () use ($translationId): array {
            (new EntityTranslations(null, $this->access))->delete($translationId, $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.translation_deleted'), 'id' => $translationId];
        });
    }

    public function getRules(): string
    {
        $this->requireManage();

        return view('integrations/rules', [
            'rules'   => (new NotificationRules(null, $this->access))->all(),
            'kinds'   => NotificationRuleKind::cases(),
            'enabled' => (new IntegrationOptions())->notificationsEnabled(),
        ]);
    }

    public function postRuleSave(int $ruleId = -1): ResponseInterface
    {
        return $this->attempt(function () use ($ruleId): array {
            $rules = new NotificationRules(null, $this->access);

            if ($ruleId > 0) {
                $rules->update($ruleId, $this->postedText('name'), $this->request->getPost('threshold'), $this->employeeId());

                return ['success' => true, 'message' => lang('Integrations.rule_saved'), 'id' => $ruleId];
            }

            $id = $rules->create($this->postedText('kind'), $this->postedText('name'), $this->request->getPost('threshold'), $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.rule_saved'), 'id' => $id];
        });
    }

    public function postRuleActive(int $ruleId, int $active): ResponseInterface
    {
        return $this->attempt(function () use ($ruleId, $active): array {
            (new NotificationRules(null, $this->access))->setActive($ruleId, $active === 1, $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.rule_saved'), 'id' => $ruleId];
        });
    }

    public function postRuleDelete(int $ruleId): ResponseInterface
    {
        return $this->attempt(function () use ($ruleId): array {
            (new NotificationRules(null, $this->access))->delete($ruleId, $this->employeeId());

            return ['success' => true, 'message' => lang('Integrations.rule_deleted'), 'id' => $ruleId];
        });
    }

    /**
     * @param list<PaymentMethod> $all
     *
     * @return array<string, array{account: string, treasury: string|null}|null>
     */
    private function routes(array $all, PaymentMethods $methods): array
    {
        $routes = [];

        foreach ($all as $method) {
            $routes[$method->type->value] = $methods->routedTo($method->type);
        }

        return $routes;
    }

    private function editing(): ?EntityTranslation
    {
        $id = (int) $this->request->getGet('edit');

        return $id > 0 ? (new EntityTranslations(null, $this->access))->find($id) : null;
    }
}
