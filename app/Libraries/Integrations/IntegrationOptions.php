<?php

namespace App\Libraries\Integrations;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\BranchException;
use App\Models\Appconfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The master switches of the integration settings that need no outside service. Every one is off until
 * somebody turns it on, so a shop that never opens them keeps the behaviour it has. The API switch is
 * changed only by whoever may manage the keys, the others by whoever may manage the integrations, and
 * every change is written to the audit log with what it was before.
 */
final class IntegrationOptions
{
    public const PAYMENT_METHODS       = 'payment_methods_custom';
    public const SECOND_LANGUAGE       = 'second_language_enabled';
    public const SECOND_LANGUAGE_PRINT = 'second_language_print';
    public const SECOND_LANGUAGE_CODE  = 'second_language_code';
    public const API                   = 'api_enabled';
    public const NOTIFICATIONS         = 'notification_rules_enabled';
    private const FLAGS                = [
        'payment_methods'       => self::PAYMENT_METHODS,
        'second_language'       => self::SECOND_LANGUAGE,
        'second_language_print' => self::SECOND_LANGUAGE_PRINT,
        'api'                   => self::API,
        'notifications'         => self::NOTIFICATIONS,
    ];

    private BaseConnection $db;
    private Appconfig $config;
    private AuditLog $audit;
    private IntegrationAccess $access;

    public function __construct(?BaseConnection $db = null, ?IntegrationAccess $access = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->config = model(Appconfig::class);
        $this->audit  = new AuditLog($this->db);
        $this->access = $access ?? new IntegrationAccess();
    }

    public function paymentMethodsCustom(): bool
    {
        return $this->flag(self::PAYMENT_METHODS);
    }

    public function secondLanguageEnabled(): bool
    {
        return $this->flag(self::SECOND_LANGUAGE) && $this->secondLanguageCode() !== '';
    }

    public function secondLanguagePrint(): bool
    {
        return $this->secondLanguageEnabled() && $this->flag(self::SECOND_LANGUAGE_PRINT);
    }

    public function secondLanguageCode(): string
    {
        $code = $this->config->get_value(self::SECOND_LANGUAGE_CODE);

        return in_array($code, self::locales(), true) ? $code : '';
    }

    public function apiEnabled(): bool
    {
        return $this->flag(self::API);
    }

    public function notificationsEnabled(): bool
    {
        return $this->flag(self::NOTIFICATIONS);
    }

    /**
     * @return list<string> The languages the application has, which are the ones a second language can be.
     */
    public static function locales(): array
    {
        $codes = array_map('basename', glob(APPPATH . 'Language/*', GLOB_ONLYDIR) ?: []);
        sort($codes);

        return $codes;
    }

    /**
     * @return array{payment_methods: bool, second_language: bool, second_language_print: bool, second_language_code: string, api: bool, notifications: bool}
     */
    public function all(): array
    {
        return [
            'payment_methods'       => $this->flag(self::PAYMENT_METHODS),
            'second_language'       => $this->flag(self::SECOND_LANGUAGE),
            'second_language_print' => $this->flag(self::SECOND_LANGUAGE_PRINT),
            'second_language_code'  => $this->secondLanguageCode(),
            'api'                   => $this->flag(self::API),
            'notifications'         => $this->flag(self::NOTIFICATIONS),
        ];
    }

    /**
     * @param array{payment_methods?: bool, second_language?: bool, second_language_print?: bool, second_language_code?: string, api?: bool, notifications?: bool} $input What is left out stays as it is.
     *
     * @throws BranchException|IntegrationException
     */
    public function update(array $input, ?int $employeeId = null): void
    {
        $before = $this->all();
        $after  = $before;

        foreach (array_keys(self::FLAGS) as $name) {
            if (array_key_exists($name, $input)) {
                $after[$name] = (bool) $input[$name];
            }
        }

        if (array_key_exists('second_language_code', $input)) {
            $code = trim((string) $input['second_language_code']);

            if ($code !== '' && ! in_array($code, self::locales(), true)) {
                throw new IntegrationException(IntegrationError::SettingInvalid, 'The second language is not a language of the application.', ['code' => $code]);
            }

            $after['second_language_code'] = $code;
        }

        if (($after['second_language'] || $after['second_language_print']) && $after['second_language_code'] === '') {
            throw new IntegrationException(IntegrationError::SettingInvalid, 'A second language needs the language it is.', ['code' => '']);
        }

        $changes = array_filter($after, static fn (bool|string $value, string $name): bool => $value !== $before[$name], ARRAY_FILTER_USE_BOTH);

        if ($changes === []) {
            return;
        }

        if (array_key_exists('api', $changes)) {
            $this->access->requireKeys($employeeId);
        }

        if (array_diff_key($changes, ['api' => true]) !== []) {
            $this->access->requireManage($employeeId);
        }

        $values = [];

        foreach ($changes as $name => $value) {
            $values[$name === 'second_language_code' ? self::SECOND_LANGUAGE_CODE : self::FLAGS[$name]] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        $this->config->batch_save($values);
        $this->audit->write('integration', null, 'integration_options_updated', $employeeId, JournalOrigin::User, ['before' => array_intersect_key($before, $changes), 'after' => $changes]);
    }

    private function flag(string $key): bool
    {
        return $this->config->get_value($key, '0') === '1';
    }
}
