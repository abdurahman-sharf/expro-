<?php

namespace Tests\Libraries\Accounting\Ai;

use CodeIgniter\Test\CIUnitTestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * The layer must not reach the network and must not hold a model: the code of its namespace, its screens, its views
 * and its commands are read for anything that opens a connection, sends a message or runs a program.
 *
 * @internal
 */
final class AiNamespaceTest extends CIUnitTestCase
{
    private const FORBIDDEN = [
        'curl_'                 => 'a cURL call',
        'CURLRequest'           => 'the HTTP client of the framework',
        'curlrequest'           => 'the HTTP client of the framework',
        'Guzzle'                => 'an HTTP client',
        'HttpClient'            => 'an HTTP client',
        'http_client'           => 'an HTTP client',
        'fsockopen'             => 'a socket',
        'pfsockopen'            => 'a socket',
        'stream_socket_client'  => 'a socket',
        'socket_create'         => 'a socket',
        'socket_connect'        => 'a socket',
        'stream_context_create' => 'a stream context for a remote request',
        'file_get_contents'     => 'a read that can reach a URL',
        'allow_url_fopen'       => 'a remote open',
        'SoapClient'            => 'a SOAP client',
        'proc_open'             => 'a program',
        'shell_exec'            => 'a program',
        'passthru('             => 'a program',
        'popen('                => 'a program',
        'exec('                 => 'a program',
        'system('               => 'a program',
        'mail('                 => 'a message sent out',
        'Email_lib'             => 'a message sent out',
        'Sms_lib'               => 'a message sent out',
        'Mailchimp'             => 'a call to a service',
        'http://'               => 'an address on the network',
        'https://'              => 'an address on the network',
    ];

    /**
     * @return array<string, string> The text of every file that belongs to the agent layer, by path.
     */
    private function files(): array
    {
        $paths = [APPPATH . 'Controllers/AccountingAi.php'];

        foreach (glob(APPPATH . 'Commands/AccountingAi*.php') ?: [] as $path) {
            $paths[] = $path;
        }

        foreach ([APPPATH . 'Libraries/Accounting/Ai', APPPATH . 'Views/accounting_ai'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $paths[] = $file->getPathname();
                }
            }
        }

        $files = [];

        foreach ($paths as $path) {
            $files[substr($path, strlen(APPPATH))] = (string) file_get_contents($path);
        }

        return $files;
    }

    public function testNamespace_ReadsTheFilesOfTheWholeLayer(): void
    {
        $paths = array_keys($this->files());

        $this->assertContains('Libraries/Accounting/Ai/ProposalService.php', $paths);
        $this->assertContains('Libraries/Accounting/Ai/Rules/DuplicateRule.php', $paths);
        $this->assertContains('Controllers/AccountingAi.php', $paths);
        $this->assertContains('Commands/AccountingAiScan.php', $paths);
        $this->assertContains('Views/accounting_ai/proposal.php', $paths);
        $this->assertGreaterThan(30, count($paths));
    }

    public function testNamespace_OpensNoConnectionSendsNothingAndRunsNoProgram(): void
    {
        $found = [];

        foreach ($this->files() as $path => $text) {
            foreach (self::FORBIDDEN as $token => $meaning) {
                if (stripos($text, $token) !== false) {
                    $found[] = "{$path} holds {$token}, {$meaning}";
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function testNamespace_ImportsNothingFromTheHttpNamespaceOfTheFrameworkExceptTheResponseOfAScreen(): void
    {
        $found = [];

        foreach ($this->files() as $path => $text) {
            if (preg_match_all('/^use CodeIgniter\\\\HTTP\\\\(\w+);/m', $text, $matches) > 0) {
                foreach ($matches[1] as $class) {
                    if ($class !== 'ResponseInterface' || ! str_starts_with($path, 'Controllers/')) {
                        $found[] = "{$path} imports {$class}";
                    }
                }
            }
        }

        $this->assertSame([], $found);
    }

    public function testNamespace_DeclaresEveryClassUnderItsOwnNamespace(): void
    {
        foreach ($this->files() as $path => $text) {
            if (! str_starts_with($path, 'Libraries/Accounting/Ai/')) {
                continue;
            }

            $expected = rtrim('App\\' . str_replace('/', '\\', dirname($path)), '\\');

            $this->assertStringContainsString('namespace ' . $expected . ';', $text, $path);
        }
    }

    public function testNamespace_HoldsNoFreeTextPromptOrModelCall(): void
    {
        foreach ($this->files() as $path => $text) {
            foreach (['prompt', 'completion', 'openai', 'anthropic', 'gpt-', 'llm'] as $word) {
                $this->assertStringNotContainsStringIgnoringCase($word, str_replace(['Free text prompt'], '', $text), "{$path} mentions {$word}");
            }
        }
    }
}
