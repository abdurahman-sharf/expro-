<?php

namespace Tests\Libraries\Accounting\Ai;

use App\Libraries\Accounting\Ai\EvidenceLabels;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * The words that stand for the codes of an evidence row on a screen, in the language of the reader.
 *
 * @internal
 */
final class EvidenceLabelsTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Services::language()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Services::language()->setLocale('en');

        parent::tearDown();
    }

    public function testValue_GivesTheLabelOfTheSourceOfAJournal(): void
    {
        $this->assertSame('Sale', EvidenceLabels::value('source', 'sale'));
        $this->assertSame('Receiving', EvidenceLabels::value('source', 'purchase'));
    }

    public function testValue_GivesTheLabelOfWhoOwnsAnAccount(): void
    {
        $this->assertSame('Customer', EvidenceLabels::value('entity_type', 'customer'));
        $this->assertSame('Supplier', EvidenceLabels::value('entity_type', 'supplier'));
        $this->assertSame('Stock location', EvidenceLabels::value('entity_type', 'stock_location'));
    }

    public function testValue_GivesTheLabelOfTheStatusOfAJournal(): void
    {
        $this->assertSame('Posted', EvidenceLabels::value('status', 'posted'));
        $this->assertSame('Reversed', EvidenceLabels::value('status', 'reversed'));
    }

    public function testValue_KeepsTheCodeThatHasNoLabel(): void
    {
        $this->assertSame('nosuchsource', EvidenceLabels::value('source', 'nosuchsource'));
        $this->assertSame('nosuchowner', EvidenceLabels::value('entity_type', 'nosuchowner'));
        $this->assertSame('nosuchstatus', EvidenceLabels::value('status', 'nosuchstatus'));
    }

    public function testValue_KeepsTheValueOfAnyOtherKey(): void
    {
        $this->assertSame('sale', EvidenceLabels::value('account', 'sale'));
        $this->assertSame('posted', EvidenceLabels::value('number', 'posted'));
        $this->assertSame('250.0000', EvidenceLabels::value('amount', '250.0000'));
    }

    public function testValue_SpeaksTheLanguageOfTheReader(): void
    {
        Services::language()->setLocale('ar-EG');

        $this->assertSame('عميل', EvidenceLabels::value('entity_type', 'customer'));
        $this->assertSame('مرحَّل', EvidenceLabels::value('status', 'posted'));
        $this->assertNotSame('sale', EvidenceLabels::value('source', 'sale'));
    }
}
