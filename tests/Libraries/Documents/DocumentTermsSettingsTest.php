<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\DocumentTerms;
use App\Libraries\Documents\DocumentTermsSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class DocumentTermsSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipe();
    }

    protected function tearDown(): void
    {
        $this->wipe();

        parent::tearDown();
    }

    private function wipe(): void
    {
        $db = Database::connect();
        $db->table('app_config')->whereIn('key', DocumentTerms::settingKeys())->delete();
        $db->table('accounting_audit_log')->where('action', 'document_terms_updated')->delete();
    }

    private function audit(): array
    {
        return Database::connect()->table('accounting_audit_log')->where('action', 'document_terms_updated')->orderBy('audit_id', 'ASC')->get()->getResult();
    }

    public function testAll_ListsEveryFamilyEmptyWhileNothingIsSaved(): void
    {
        $this->assertSame(array_fill_keys(DocumentTerms::settingKeys(), ''), (new DocumentTermsSettings())->all());
    }

    public function testUpdate_SavesTheTextsAndAuditsWhatWasReplacedAndWhatItBecame(): void
    {
        $settings = new DocumentTermsSettings();

        $changed = $settings->update(['document_terms_invoice' => 'Goods stay ours until paid.', 'document_terms_quote' => 'Valid for 14 days.'], 1);

        $this->assertSame(['document_terms_invoice' => 'Goods stay ours until paid.', 'document_terms_quote' => 'Valid for 14 days.'], $changed);
        $this->assertSame('Goods stay ours until paid.', $settings->all()['document_terms_invoice']);

        $entries = $this->audit();

        $this->assertCount(1, $entries);
        $this->assertSame(1, (int) $entries[0]->employee_id);
        $this->assertSame('document_terms', $entries[0]->entity_type);
        $this->assertSame(['document_terms_invoice' => '', 'document_terms_quote' => ''], json_decode((string) $entries[0]->details, true)['before']);
        $this->assertSame($changed, json_decode((string) $entries[0]->details, true)['after']);
    }

    public function testUpdate_WritesNothingWhenTheSameTextIsSavedAgain(): void
    {
        $settings = new DocumentTermsSettings();
        $settings->update(['document_terms_invoice' => 'Terms'], 1);

        $this->assertSame([], $settings->update(['document_terms_invoice' => "  Terms\n"], 1));
        $this->assertCount(1, $this->audit());
    }

    public function testUpdate_LeavesASettingThatIsNotInTheInputAsItIs(): void
    {
        $settings = new DocumentTermsSettings();
        $settings->update(['document_terms_invoice' => 'Invoice terms', 'document_terms_quote' => 'Quote terms'], 1);

        $settings->update(['document_terms_quote' => 'New quote terms'], 1);

        $this->assertSame('Invoice terms', $settings->all()['document_terms_invoice']);
        $this->assertSame('New quote terms', $settings->all()['document_terms_quote']);
    }

    public function testUpdate_ClearsATextWhenAnEmptyOneIsSavedAndAuditsIt(): void
    {
        $settings = new DocumentTermsSettings();
        $settings->update(['document_terms_invoice' => 'Terms'], 1);

        $settings->update(['document_terms_invoice' => ''], 2);

        $entries = $this->audit();

        $this->assertSame('', $settings->all()['document_terms_invoice']);
        $this->assertCount(2, $entries);
        $this->assertSame(['document_terms_invoice' => 'Terms'], json_decode((string) $entries[1]->details, true)['before']);
        $this->assertSame(['document_terms_invoice' => ''], json_decode((string) $entries[1]->details, true)['after']);
    }

    public function testUpdate_IgnoresASettingThatIsNotAFamilyOfTerms(): void
    {
        $settings = new DocumentTermsSettings();

        $this->assertSame([], $settings->update(['company' => 'Hijacked', 'document_terms_receipt_voucher' => 'x'], 1));
        $this->assertSame([], $this->audit());
        $this->assertNull(Database::connect()->table('app_config')->where('key', 'document_terms_receipt_voucher')->get()->getRow());
    }

    public function testUpdate_CutsATextToTheLengthASettingHolds(): void
    {
        $settings = new DocumentTermsSettings();

        $settings->update(['document_terms_invoice' => str_repeat('a', DocumentTerms::MAX_LENGTH + 100)], 1);

        $this->assertSame(DocumentTerms::MAX_LENGTH, strlen($settings->all()['document_terms_invoice']));
    }

    public function testUpdate_KeepsTextThatMarkupIsWrittenInAsText(): void
    {
        $settings = new DocumentTermsSettings();

        $settings->update(['document_terms_invoice' => '<script>alert(1)</script>'], 1);

        $this->assertSame('<script>alert(1)</script>', $settings->all()['document_terms_invoice']);
    }
}
