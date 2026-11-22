<?php

namespace App\Libraries\EInvoicing;

use Config\Zatca;

/**
 * The environments of ZATCA: the developer portal (sandbox), simulation and production, each with its own address,
 * its own template of certificate and its own approval of the owner.
 */
enum ZatcaEnvironment: string
{
    case Sandbox    = 'sandbox';
    case Simulation = 'simulation';
    case Production = 'production';

    public function baseUrl(Zatca $config): string
    {
        return rtrim(match ($this) {
            self::Sandbox    => $config->sandboxUrl,
            self::Simulation => $config->simulationUrl,
            self::Production => $config->productionUrl,
        }, '/');
    }

    public function template(): string
    {
        return CsrBuilder::TEMPLATES[$this->value];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $environment): string => $environment->value, self::cases());
    }
}
