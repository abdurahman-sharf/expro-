<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Where ZATCA is reached and whether it may be. Nothing is sent anywhere unless the administrator of the server turns
 * the network on in .env (zatca.networkEnabled = true) and names the environment the owner approved
 * (zatca.approvedEnvironments = sandbox, then simulation, then production: D-10, D-20, D-21), and an onboarding holder
 * turns the integration on for that environment in the application (ZatcaNetworkGuard). Under tests nothing is ever
 * sent. The addresses are those the Fatoora portal manual publishes for simulation and production; the one of the
 * developer portal (sandbox) is checked when the sandbox is approved.
 */
class Zatca extends BaseConfig
{
    public bool $networkEnabled = false;

    /**
     * The environments the owner approved, separated by commas.
     */
    public string $approvedEnvironments = '';

    public string $sandboxUrl    = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal';
    public string $simulationUrl = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/simulation';
    public string $productionUrl = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/core';

    /**
     * Seconds a request may take, and seconds to connect.
     */
    public int $timeout = 30;

    public int $connectTimeout = 10;

    /**
     * The language the answers of ZATCA are asked in (Accept-Language).
     */
    public string $language = 'en';

    /**
     * The file of the master keys that seal the secrets of the certificates (KeyVault), outside the application.
     */
    public string $keyRing = '';
}
