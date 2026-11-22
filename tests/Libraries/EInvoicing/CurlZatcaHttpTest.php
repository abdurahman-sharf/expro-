<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\CurlZatcaHttp;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\ZatcaHttpRequest;
use App\Libraries\EInvoicing\ZatcaIntegration;
use App\Libraries\EInvoicing\ZatcaNetworkGuard;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Zatca;

/**
 * The transport that would reach ZATCA: under tests the guard refuses before anything is opened, and what it would
 * tell cURL is checked without sending: the address of the environment only, HTTPS only, the certificate of the server
 * verified, no redirection followed, and limits of time.
 */
class CurlZatcaHttpTest extends CIUnitTestCase
{
    private function request(string $url = 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/compliance'): ZatcaHttpRequest
    {
        return new ZatcaHttpRequest('sandbox', 'POST', $url, ['OTP' => '123456', 'Content-Type' => 'application/json'], '{"csr":"x"}');
    }

    private function allowed(): Zatca
    {
        $config                       = new Zatca();
        $config->networkEnabled       = true;
        $config->approvedEnvironments = 'sandbox,simulation,production';

        return $config;
    }

    public function testSend_RefusesBeforeOpeningAnythingUnderTests(): void
    {
        try {
            (new CurlZatcaHttp(new ZatcaNetworkGuard($this->allowed(), new ZatcaIntegration()), $this->allowed()))->send($this->request());
            $this->fail('A request left under tests.');
        } catch (EInvoiceException $refusal) {
            $this->assertSame(EInvoiceError::NetworkBlocked, $refusal->error);
            $this->assertStringContainsString(ZatcaNetworkGuard::TESTING, $refusal->getMessage());
        }
    }

    public function testOptions_SendsToTheAddressOfTheEnvironmentOverVerifiedHttpsOnlyWithinItsTime(): void
    {
        $options = (new CurlZatcaHttp(new ZatcaNetworkGuard($this->allowed()), $this->allowed()))->options($this->request());

        $this->assertSame('https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/compliance', $options[CURLOPT_URL]);
        $this->assertSame([CURLPROTO_HTTPS, true, 2, false, 10, 30], [$options[CURLOPT_PROTOCOLS], $options[CURLOPT_SSL_VERIFYPEER], $options[CURLOPT_SSL_VERIFYHOST], $options[CURLOPT_FOLLOWLOCATION], $options[CURLOPT_CONNECTTIMEOUT], $options[CURLOPT_TIMEOUT]]);
        $this->assertContains('OTP: 123456', $options[CURLOPT_HTTPHEADER]);
    }

    public function testOptions_RefusesAnAddressThatIsNotTheOneOfItsEnvironment(): void
    {
        $transport = new CurlZatcaHttp(new ZatcaNetworkGuard($this->allowed()), $this->allowed());

        foreach (['https://gw-fatoora.zatca.gov.sa/e-invoicing/core/compliance', 'http://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal/compliance', 'https://example.com/e-invoicing/developer-portal/compliance', 'https://gw-fatoora.zatca.gov.sa/e-invoicing/developer-portal'] as $url) {
            try {
                $transport->options($this->request($url));
                $this->fail('A request was allowed to ' . $url);
            } catch (EInvoiceException $refusal) {
                $this->assertSame(EInvoiceError::NetworkBlocked, $refusal->error);
            }
        }
    }
}
