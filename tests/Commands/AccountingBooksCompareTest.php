<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\StreamFilterTrait;

class AccountingBooksCompareTest extends CIUnitTestCase
{
    use StreamFilterTrait;

    /**
     * @var list<string>
     */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            unlink($file);
        }

        $this->files = [];

        parent::tearDown();
    }

    private function file(string $debit, string $content = ''): string
    {
        $file = WRITEPATH . 'books-compare-' . bin2hex(random_bytes(6)) . '.json';

        file_put_contents($file, $content !== '' ? $content : json_encode([
            'accounts' => [7 => ['code' => '1201', 'name' => 'Cash', 'debit' => $debit, 'credit' => '0.0000']],
            'totals'   => ['debit' => $debit, 'credit' => $debit],
            'stock'    => [1 => ['account_id' => 9, 'account_balance' => '50.0000', 'movements_value' => '50.0000', 'unattributed' => '0.0000']],
        ]));

        $this->files[] = $file;

        return $file;
    }

    private function runCompare(string $arguments): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:books_compare ' . $arguments);

        return $this->getStreamFilterBuffer();
    }

    public function testRun_AcceptsTwoSnapshotsThatAgree(): void
    {
        $output = $this->runCompare($this->file('100.0000') . ' ' . $this->file('100.0000'));

        $this->assertStringContainsString('The two snapshots agree on all 1 account(s) and 1 stock location(s).', $output);
    }

    public function testRun_RejectsSnapshotsThatDifferAndListsEachFigure(): void
    {
        $output = $this->runCompare($this->file('100.0000') . ' ' . $this->file('90.0000'));

        $this->assertStringContainsString('account 7 debit: 100.0000 -> 90.0000', $output);
        $this->assertStringContainsString('3 figure(s) differ.', $output);
    }

    public function testRun_RequiresTwoFilesThatAreSnapshots(): void
    {
        $message = 'Name two snapshot files written by accounting:books_snapshot';

        $this->assertStringContainsString($message, $this->runCompare($this->file('100.0000')));
        $this->assertStringContainsString($message, $this->runCompare($this->file('100.0000') . ' ' . $this->file('', '{"accounts": 1}')));
        $this->assertStringContainsString($message, $this->runCompare($this->file('100.0000') . ' ' . $this->file('', 'not json')));
    }
}
