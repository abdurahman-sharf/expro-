<?php

namespace App\Libraries\Integrations;

/**
 * The words for a refusal of the integration settings, in the language in use.
 */
final class IntegrationMessage
{
    private const ARGUMENTS = ['name', 'min', 'max', 'code', 'date', 'id'];

    public static function of(IntegrationException $exception): string
    {
        $arguments = [];

        foreach (self::ARGUMENTS as $name) {
            if (array_key_exists($name, $exception->context)) {
                $arguments[] = (string) $exception->context[$name];
            }
        }

        return lang('Integrations.error_' . $exception->error->value, $arguments);
    }
}
