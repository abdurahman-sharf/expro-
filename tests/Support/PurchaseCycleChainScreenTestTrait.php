<?php

namespace Tests\Support;

use App\Libraries\PurchaseCycle\PurchaseDocuments;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;

/**
 * What the screens of the three documents that are made into the next one have in common: the explicit conversion by a POST, that it
 * copies the lines and posts nothing, who may do it, and how the page shows what was made from the document. A class that
 * uses it also uses PurchaseCycleScreenTestTrait.
 */
trait PurchaseCycleChainScreenTestTrait
{
    public function testPostConvert_MakesTheNextDocumentWithTheLinesAndPostsNothing(): void
    {
        $documentId = $this->document();
        $next       = $this->kind()->next();
        $before     = $this->bookCounts();

        $this->actAsWith([$this->permission(PurchaseDocuments::CONVERT), $this->permission(PurchaseDocuments::VIEW_ALL), $next->permission(PurchaseDocuments::ADD)]);

        $result = $this->postJson('/' . $this->module() . '/convert/' . $documentId);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringEndsWith('/' . $next->module() . '/view/' . $result['id'], $result['redirect']);
        $this->assertSame(1, $this->countRows($next->table()));
        $this->assertSame(['10.000'], array_map(static fn (object $line): string => $line->quantity, $this->documentsOf($next)->lines((int) $result['id'])));
        $this->assertSame(PurchaseDocumentStatus::Converted, $this->documentsOf($this->kind())->statusOf($documentId));
        $this->assertSame($before, $this->bookCounts());
    }

    public function testPostConvert_RefusesInWordsWhatTheServiceRefuses(): void
    {
        $documentId = $this->document();
        $next       = $this->kind()->next();

        $this->actAsWith([$this->permission(PurchaseDocuments::CONVERT), $this->permission(PurchaseDocuments::VIEW_ALL), $next->permission(PurchaseDocuments::ADD)]);

        $this->assertTrue($this->postJson('/' . $this->module() . '/convert/' . $documentId)['success']);

        $again = $this->postJson('/' . $this->module() . '/convert/' . $documentId);

        $this->assertFalse($again['success']);
        $this->assertSame(lang('Accounting.error_purchase_document_not_convertible', [lang('Purchase_cycle.status_converted')]), $again['message']);
        $this->assertSame(1, $this->countRows($next->table()));
    }

    public function testPostConvert_RequiresTheConvertPermissionOfTheDocumentAndTheAddPermissionOfTheNextOne(): void
    {
        $documentId = $this->document();
        $next       = $this->kind()->next();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $next->permission(PurchaseDocuments::ADD)]);

        $withoutConvert = $this->postJson('/' . $this->module() . '/convert/' . $documentId);

        $this->assertFalse($withoutConvert['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $withoutConvert['message']);

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::CONVERT)]);

        $withoutAdd = $this->postJson('/' . $this->module() . '/convert/' . $documentId);

        $this->assertFalse($withoutAdd['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $withoutAdd['message']);
        $this->assertSame(0, $this->countRows($next->table()));
    }

    public function testPostConvert_TreatsADocumentOfAnotherBranchAsOneThatDoesNotExist(): void
    {
        $other      = $this->createBranch();
        $documentId = $this->document();
        $next       = $this->kind()->next();

        $this->actAsWith([$this->permission(PurchaseDocuments::CONVERT), $this->permission(PurchaseDocuments::VIEW_ALL), $next->permission(PurchaseDocuments::ADD)], [$other]);

        $result = $this->postJson('/' . $this->module() . '/convert/' . $documentId);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_document_not_found'), $result['message']);
        $this->assertSame(0, $this->countRows($next->table()));
    }

    public function testGetShow_LinksTheDocumentsMadeFromItAndTheDocumentItWasMadeFrom(): void
    {
        $documentId = $this->document();
        $next       = $this->kind()->next();
        $madeId     = $this->documentsOf($this->kind())->convert($documentId, $this->clerk);

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $next->permission(PurchaseDocuments::VIEW_ALL), $next->module()]);

        $page = $this->get('/' . $this->module() . '/view/' . $documentId);

        $page->assertSee('id="document_made_' . $madeId . '"');
        $this->assertStringContainsString('href="' . base_url($next->module() . '/view/' . $madeId) . '"', $page->getBody());
        $page->assertSee(lang('Purchase_cycle.status_converted'));
        $page->assertDontSee('id="document_convert"');

        $this->documentsOf($next)->cancel($madeId, 'wrong', $this->clerk);

        $this->get('/' . $this->module() . '/view/' . $documentId)->assertSee(lang('Purchase_cycle.status_cancelled'));
    }

    public function testGetShow_OffersTheConversionOnlyToWhoMayConvert(): void
    {
        $documentId = $this->document();
        $next       = $this->kind()->next();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::CONVERT)]);

        $page = $this->get('/' . $this->module() . '/view/' . $documentId);

        $page->assertSee('id="document_convert"');
        $page->assertSee(lang('Purchase_cycle.convert_to_' . $next->value));
    }
}
