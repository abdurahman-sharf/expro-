<?php

namespace Tests\Support;

/**
 * Running the official ZATCA SDK (D-09) on files from a test, through tools/zatca/validate.sh so that the tests and
 * the script read its results the same way: PASSED, FAILED, or NOT FULLY VALIDATED, which is never taken as passing.
 * Offline only: the SDK validates the files on this machine.
 */
trait ZatcaSdkTrait
{
    /**
     * The folder the official SDK was unpacked in; the test is skipped when it is not installed.
     */
    protected function zatcaSdk(): string
    {
        $sdk = (string) getenv('ZATCA_SDK_HOME');

        if ($sdk === '' || ! is_file($sdk . '/Apps/fatoora')) {
            $this->markTestSkipped('The official SDK is not installed (D-09): set ZATCA_SDK_HOME to the folder it was unpacked in.');
        }

        return $sdk;
    }

    /**
     * @return array<string, array{0: string, 1: string}> By file name: the result and what the script says of it.
     */
    protected function officialResults(string $sdk, string $directory, bool $chain = false): array
    {
        $output = (string) shell_exec(
            'ZATCA_SDK_HOME=' . escapeshellarg($sdk) . ' ' . escapeshellarg(ROOTPATH . 'tools/zatca/validate.sh') . ($chain ? ' --chain ' : ' ') . escapeshellarg($directory) . ' 2>&1',
        );

        preg_match_all('/^(PASSED|FAILED|NOT FULLY VALIDATED)\s+(\S+)\s*(.*)$/m', $output, $rows, PREG_SET_ORDER);

        $results = [];

        foreach ($rows as $row) {
            $results[$row[2]] = [$row[1], trim($row[3])];
        }

        $this->assertCount(count(glob($directory . '/*.xml') ?: []), $results, $output);

        return $results;
    }

    /**
     * Every file that must pass passed, and every file broken on purpose failed for the reason it was broken for. A
     * file the SDK did not fully validate counts as neither: the test is then marked incomplete and names it.
     *
     * @param array<string, array{0: string, 1: string}> $results
     * @param array<string, string>                      $broken  The pattern the reason of each broken file must match.
     */
    protected function assertOfficialResults(array $results, array $broken): void
    {
        $partial = [];

        foreach ($results as $name => [$result, $detail]) {
            if ($result === 'NOT FULLY VALIDATED') {
                $partial[] = $name . ': ' . $detail;

                continue;
            }

            if (isset($broken[$name])) {
                $this->assertSame('FAILED', $result, $name . ' was broken on purpose and must be refused.');
                $this->assertMatchesRegularExpression($broken[$name], $detail, $name . ' was refused for another reason than the one it was broken for.');
            } else {
                $this->assertSame('PASSED', $result, $name . ': ' . $detail);
            }
        }

        if ($partial !== []) {
            $this->markTestIncomplete("The official SDK did not fully validate:\n" . implode("\n", $partial));
        }
    }
}
