<?php

namespace Tests\Support;

use App\Libraries\PurchaseCycle\PurchaseCycleSettings;
use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocuments;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;
use CodeIgniter\Exceptions\PageNotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * What the four screens of the purchase cycle have in common, tried on the family a test class names: the list and who sees what of
 * it, the form, the page of a document with the actions the employee may do, that every change is a POST the service may refuse
 * in words, and the settings. A class that uses it also uses DatabaseTestTrait, FeatureTestTrait and the fixture traits of
 * the cycle, and says which family it is with kind().
 */
trait PurchaseCycleScreenTestTrait
{
    abstract protected function kind(): PurchaseDocumentKind;

    protected function setUpScreen(): void
    {
        $this->startBuying();
        $this->startCycle();
        $this->setConfigValue('invoice_enable', '1');
    }

    protected function tearDownScreen(): void
    {
        $this->releaseOpenTransactions();
        $this->stopCycle();
        $this->receivingIds = [...$this->receivingIds, ...array_map('intval', array_column($this->accountingDb()->table('receivings')->select('receiving_id')->where('supplier_id', $this->supplierId)->get()->getResultArray(), 'receiving_id'))];
        $this->stopBuying();
    }

    protected function permission(string $action): string
    {
        return $this->kind()->permission($action);
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    protected function actAsWith(array $permissions, ?array $branchIds = null, bool $withStock = true): int
    {
        $personId = $this->employeeWithGrants([$this->kind()->module(), ...($withStock ? ['receivings_stock'] : []), ...$permissions], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    protected function module(): string
    {
        return $this->kind()->module();
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(string $quantity = '4'): array
    {
        return [
            'supplier_id'   => (string) $this->supplierId,
            'location_id'   => (string) self::LOCATION_ID,
            'document_date' => '2026-10-21',
            'due_date'      => '2026-10-31',
            'note'          => 'from the form',
            'lines'         => [['item_id' => (string) $this->goods, 'quantity' => $quantity, 'unit_price' => '3.50', 'description' => 'typed']],
        ];
    }

    protected function document(?int $by = null, array $override = []): int
    {
        return $this->makeDocument($this->kind(), $override, $by);
    }

    protected function turnOnManualStatus(): void
    {
        $this->setConfigValue($this->kind()->manualStatusSetting(), '1');
    }

    public function testGetIndex_ListsTheDocumentsAndOffersTheNewOneToWhoMayAddThem(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::ADD), $this->permission(PurchaseDocuments::VIEW_ALL)]);

        $page = $this->get('/' . $this->module());

        $page->assertStatus(200);
        $page->assertSee('id="document_' . $documentId . '"');
        $page->assertSee(lang('Purchase_cycle.status_open'));
        $page->assertSee('id="document_new"');
        $page->assertSee(lang('Purchase_cycle.title_' . $this->kind()->value));
    }

    public function testGetIndex_OffersNoNewDocumentToWhoMayOnlyLookAndNoSettingsEither(): void
    {
        $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $page = $this->get('/' . $this->module());

        $page->assertStatus(200);
        $page->assertDontSee('id="document_new"');
        $page->assertDontSee('id="purchase_cycle_settings"');
    }

    public function testGetIndex_ShowsTheEmptyStateAndNoDocumentWhileTheCycleIsOff(): void
    {
        $this->document();
        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->actAsWith([$this->permission(PurchaseDocuments::ADD), $this->permission(PurchaseDocuments::VIEW_ALL), PurchaseCycleSettings::PERMISSION]);

        $page = $this->get('/' . $this->module());

        $page->assertStatus(200);
        $page->assertSee('id="purchase_cycle_off"');
        $page->assertDontSee('id="documents_table"');
        $page->assertDontSee('id="document_new"');
        $page->assertSee('id="purchase_cycle_settings"');
    }

    public function testGetIndex_OffersTheSwitchesOfTheCycleToWhoHoldsTheConfigPermissionWithTheirState(): void
    {
        $this->turnOnManualStatus();
        $this->actAsWith([PurchaseCycleSettings::PERMISSION, $this->permission(PurchaseDocuments::VIEW_ALL)]);

        $body = $this->get('/' . $this->module())->getBody();

        $this->assertStringContainsString('id="purchase_cycle_settings"', $body);
        $this->assertStringContainsString('id="pc_manual_' . $this->kind()->value . '" name="manual_' . $this->kind()->value . '" value="1" checked', $body);

        foreach (PurchaseDocumentKind::cases() as $other) {
            if ($other !== $this->kind()) {
                $this->assertStringNotContainsString('id="pc_manual_' . $other->value . '" name="manual_' . $other->value . '" value="1" checked', $body);
            }
        }
    }

    public function testGetIndex_FiltersByStatusSearchAndDates(): void
    {
        $old       = $this->document(null, ['document_date' => '2026-09-01', 'due_date' => '2026-09-05']);
        $recent    = $this->document(null, ['document_date' => '2026-10-20']);
        $cancelled = $this->document(null, ['document_date' => '2026-10-21']);

        $this->documentsOf($this->kind())->cancel($cancelled, 'no', $this->clerk);
        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $byStatus = $this->get('/' . $this->module() . '?status=cancelled');

        $byStatus->assertSee('id="document_' . $cancelled . '"');
        $byStatus->assertDontSee('id="document_' . $recent . '"');

        $byDate = $this->get('/' . $this->module() . '?from=2026-10-01&to=2026-10-31&status=open');

        $byDate->assertSee('id="document_' . $recent . '"');
        $byDate->assertDontSee('id="document_' . $old . '"');
        $byDate->assertDontSee('id="document_' . $cancelled . '"');
    }

    public function testGetIndex_OffersInTheStatusFilterOnlyTheStatusesTheFamilyCanHave(): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $page    = $this->get('/' . $this->module());
        $isOrder = $this->kind() === PurchaseDocumentKind::Order;

        foreach (['open', 'sent', 'approved', 'cancelled'] as $status) {
            $page->assertSee('<option value="' . $status . '"');
        }

        $page->{$isOrder ? 'assertDontSee' : 'assertSee'}('<option value="converted"');

        foreach (['partly_received', 'received'] as $status) {
            $page->{$isOrder ? 'assertSee' : 'assertDontSee'}('<option value="' . $status . '"');
        }
    }

    public function testGetIndex_ReadsAFilterThatCameAsAListAsNoFilter(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $page = $this->get('/' . $this->module() . '?status[]=x&search[]=y&from[]=z');

        $page->assertStatus(200);
        $page->assertSee('id="document_' . $documentId . '"');
    }

    public function testGetIndex_ShowsOnlyTheDocumentsOfTheBranchesOfTheEmployee(): void
    {
        $other     = $this->createBranch();
        $otherSite = $this->locationOfBranch($other);
        $mine      = $this->document();
        $theirs    = $this->document(null, ['location_id' => $otherSite]);

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)], [$other]);

        $page = $this->get('/' . $this->module());

        $page->assertSee('id="document_' . $theirs . '"');
        $page->assertDontSee('id="document_' . $mine . '"');
    }

    public function testGetIndex_ShowsOnlyTheirOwnDocumentsToWhoMayNotViewAll(): void
    {
        $mine = $this->document();

        $personId = $this->actAsWith([$this->permission(PurchaseDocuments::ADD)]);
        $own      = $this->document($personId);

        $page = $this->get('/' . $this->module());

        $page->assertSee('id="document_' . $own . '"');
        $page->assertDontSee('id="document_' . $mine . '"');
    }

    public function testGetIndex_IsNotOpenedByAnEmployeeWithoutTheModuleGrant(): void
    {
        $personId = $this->employeeWithGrants([$this->permission(PurchaseDocuments::VIEW_ALL)]);
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        $page = $this->get('/' . $this->module());

        $page->assertRedirect();
        $this->assertStringContainsString('no_access/' . $this->module(), $page->getRedirectUrl());
    }

    public function testGetIndex_IsNotOpenedByAnEmployeeWhoHoldsOnlyTheModuleOfAnotherFamily(): void
    {
        $other = $this->kind() === PurchaseDocumentKind::Order ? PurchaseDocumentKind::Request : PurchaseDocumentKind::Order;

        $personId = $this->employeeWithGrants([$other->module(), $other->permission(PurchaseDocuments::VIEW_ALL), $other->permission(PurchaseDocuments::ADD)]);
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        $page = $this->get('/' . $this->module());

        $page->assertRedirect();
        $this->assertStringContainsString('no_access/' . $this->module(), $page->getRedirectUrl());
    }

    public function testGetForm_OffersTheFormToWhoMayAddAndTheLocationsOfTheReceiving(): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::ADD)]);

        $body = $this->get('/' . $this->module() . '/add')->getBody();

        $this->assertStringContainsString('id="document_form"', $body);
        $this->assertStringContainsString('<option value="' . self::LOCATION_ID . '"', $body);
        $this->assertStringContainsString(lang($this->kind()->dueLabelKey()), $body);
        $this->assertSame($this->kind()->hasSupplier(), str_contains($body, 'id="supplier_name"'));
    }

    public function testGetForm_OffersNoLocationToAnEmployeeWhoMayNotWorkWithTheStockOfThereceiving(): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::ADD)], null, false);

        $this->assertStringNotContainsString('<option value="' . self::LOCATION_ID . '"', $this->get('/' . $this->module() . '/add')->getBody());
    }

    public function testGetForm_IsNotFoundToWhoMayNotAddAndRedirectsWhileTheCycleIsOff(): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        try {
            $this->get('/' . $this->module() . '/add');
            $this->fail('A person who may not add has no form.');
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $this->setConfigValue('enable_purchase_cycle', '0');
        $this->actAsWith([$this->permission(PurchaseDocuments::ADD)]);

        $this->get('/' . $this->module() . '/add')->assertRedirectTo(site_url($this->module()));
    }

    public function testGetForm_OpensAnOpenDocumentForEditingAndNotACancelledOne(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::EDIT), $this->permission(PurchaseDocuments::VIEW_ALL)]);

        $this->get('/' . $this->module() . '/edit/' . $documentId)->assertSee('id="document_form"');

        $this->documentsOf($this->kind())->cancel($documentId, 'no longer wanted', $this->clerk);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $this->module() . '/edit/' . $documentId);
    }

    public function testGetForm_IsNotFoundToWhoMayNotEdit(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::ADD), $this->permission(PurchaseDocuments::VIEW_ALL)]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $this->module() . '/edit/' . $documentId);
    }

    public function testGetShow_ShowsTheHeaderTheStatusTheLinesAndTheActionsOfAnOpenDocument(): void
    {
        $this->turnOnManualStatus();

        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::EDIT), $this->permission(PurchaseDocuments::CONVERT), $this->permission(PurchaseDocuments::DELETE)]);

        $page = $this->get('/' . $this->module() . '/view/' . $documentId);

        $page->assertStatus(200);
        $page->assertSee('id="document_header"');
        $page->assertSee(lang('Purchase_cycle.status_open'));
        $page->assertSee('id="document_lines"');
        $page->assertSee('id="document_edit"');
        $page->assertSee('id="document_convert"');
        $page->assertSee('id="document_cancel"');
        $page->assertSee('data-status="sent"');
        $page->assertSee('data-status="approved"');
        $page->assertDontSee('data-status="open"');
        $page->assertSee('first line');
        $page->assertSee(lang($this->kind()->dueLabelKey()));
    }

    public function testGetShow_OffersTheStatusActionsOnlyWhereTheSwitchOfTheFamilyIsOn(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::EDIT)]);

        $this->get('/' . $this->module() . '/view/' . $documentId)->assertDontSee('data-status=');

        $this->turnOnManualStatus();

        $this->get('/' . $this->module() . '/view/' . $documentId)->assertSee('data-status="sent"');
    }

    public function testGetShow_OffersOnlyWhatTheEmployeeMayDo(): void
    {
        $this->turnOnManualStatus();

        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $page = $this->get('/' . $this->module() . '/view/' . $documentId);

        $page->assertSee('id="document_header"');
        $page->assertDontSee('id="document_edit"');
        $page->assertDontSee('id="document_convert"');
        $page->assertDontSee('id="document_cancel"');
        $page->assertDontSee('data-status=');
    }

    public function testGetShow_ShowsTheCancelledDocumentWithItsReasonAndNoActions(): void
    {
        $documentId = $this->document();

        $this->documentsOf($this->kind())->cancel($documentId, 'a reason of its own', $this->clerk);
        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::EDIT), $this->permission(PurchaseDocuments::CONVERT), $this->permission(PurchaseDocuments::DELETE)]);

        $page = $this->get('/' . $this->module() . '/view/' . $documentId);

        $page->assertSee(lang('Purchase_cycle.status_cancelled'));
        $page->assertSee('id="document_cancel_reason"');
        $page->assertSee('a reason of its own');
        $page->assertDontSee('id="document_edit"');
        $page->assertDontSee('id="document_convert"');
        $page->assertDontSee('id="document_cancel"');
    }

    public function testGetShow_IsNotFoundForADocumentOfAnotherBranchAnotherKindOrThatDoesNotExist(): void
    {
        $other     = $this->createBranch();
        $otherSite = $this->locationOfBranch($other);
        $mine      = $this->document();
        $elsewhere = $this->document(null, ['location_id' => $otherSite]);
        $otherKind = $this->kind() === PurchaseDocumentKind::Order ? PurchaseDocumentKind::Request : PurchaseDocumentKind::Order;

        $this->makeDocument($otherKind);
        $this->makeDocument($otherKind);

        $foreign = $this->makeDocument($otherKind);

        $this->assertSame(0, $this->accountingDb()->table($this->kind()->table())->where($this->kind()->idColumn(), $foreign)->countAllResults(), 'the id of the document of the other family is no id of this one');

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)], [1]);

        $this->get('/' . $this->module() . '/view/' . $mine)->assertStatus(200);

        foreach ([$elsewhere, $foreign, 999999] as $documentId) {
            try {
                $this->get('/' . $this->module() . '/view/' . $documentId);
                $this->fail('The document ' . $documentId . ' should not be found.');
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testGetShow_IsNotFoundWhileTheCycleIsOff(): void
    {
        $documentId = $this->document();

        $this->setConfigValue('enable_purchase_cycle', '0');
        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $this->module() . '/view/' . $documentId);
    }

    public function testGetShow_HidesTheDocumentOfAnotherEmployeeFromWhoMayNotViewAll(): void
    {
        $mine = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::ADD)]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $this->module() . '/view/' . $mine);
    }

    public function testPostSave_SavesTheDocumentThroughTheServiceAndSendsTheEmployeeToIt(): void
    {
        $before = $this->bookCounts();

        $this->actAsWith([$this->permission(PurchaseDocuments::ADD), $this->permission(PurchaseDocuments::VIEW_ALL)]);

        $result = $this->postJson('/' . $this->module() . '/save', $this->formData());

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringEndsWith('/' . $this->module() . '/view/' . $result['id'], $result['redirect']);
        $this->assertSame(1, $this->countRows($this->kind()->table()));
        $this->assertSame($before, $this->bookCounts());

        $lines = $this->documentsOf($this->kind())->lines((int) $result['id']);

        $this->assertSame(['4.000', '3.50', 'typed'], [$lines[0]->quantity, $lines[0]->unit_price, $lines[0]->description]);
    }

    public function testScreens_WorkWhileAccountingIsOffAndLeaveTheBooksAlone(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $before = $this->bookCounts();

        $this->actAsWith([$this->permission(PurchaseDocuments::ADD), $this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::DELETE)]);

        $result = $this->postJson('/' . $this->module() . '/save', $this->formData());

        $this->assertTrue($result['success'], $result['message']);
        $this->get('/' . $this->module())->assertSee('id="document_' . $result['id'] . '"');
        $this->get('/' . $this->module() . '/view/' . $result['id'])->assertSee('id="document_header"');
        $this->assertTrue($this->postJson('/' . $this->module() . '/cancel/' . $result['id'], ['reason' => 'no'])['success']);
        $this->assertSame($before, $this->bookCounts());

        $this->setConfigValue('accounting_enabled', '1');
    }

    public function testPostSave_RefusesInWordsWhatTheServiceRefuses(): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::ADD)]);

        $data          = $this->formData();
        $data['lines'] = [];

        $result = $this->postJson('/' . $this->module() . '/save', $data);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_document_no_lines'), $result['message']);
        $this->assertSame(0, $this->countRows($this->kind()->table()));
    }

    public function testPostSave_RefusesALineThatIsAListInsteadOfAFigureWithoutAServerError(): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::ADD)]);

        $data                       = $this->formData();
        $data['lines'][0]['quantity'] = ['1'];

        $result = $this->postJson('/' . $this->module() . '/save', $data);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_document_line_invalid', [1]), $result['message']);
    }

    public function testPostSave_RequiresThePermissionToAddAndToEdit(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $added = $this->postJson('/' . $this->module() . '/save', $this->formData());
        $this->assertFalse($added['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $added['message']);

        $edited = $this->postJson('/' . $this->module() . '/save/' . $documentId, $this->formData());
        $this->assertFalse($edited['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $edited['message']);
        $this->assertSame(1, $this->countRows($this->kind()->table()));
    }

    public function testPostSave_ChangesADocumentThatWasNotConverted(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::EDIT), $this->permission(PurchaseDocuments::VIEW_ALL)]);

        $result = $this->postJson('/' . $this->module() . '/save/' . $documentId, $this->formData('7'));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('7.000', (string) $this->accountingDb()->table($this->kind()->linesTable())->where($this->kind()->idColumn(), $documentId)->get()->getRow()->quantity);
    }

    public function testPostSave_TreatsADocumentOfAnotherBranchAsOneThatDoesNotExist(): void
    {
        $other      = $this->createBranch();
        $otherSite  = $this->locationOfBranch($other);
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::EDIT), $this->permission(PurchaseDocuments::VIEW_ALL)], [$other]);

        $result = $this->postJson('/' . $this->module() . '/save/' . $documentId, [...$this->formData('9'), 'location_id' => (string) $otherSite]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_document_not_found'), $result['message']);
        $this->assertSame('10.000', (string) $this->accountingDb()->table($this->kind()->linesTable())->where($this->kind()->idColumn(), $documentId)->get()->getRow()->quantity);
    }

    public function testPostStatus_MovesTheLabelWhereTheSwitchIsOnAndRefusesWhereItIsOffOrTheStatusIsNotSetByHand(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::EDIT), $this->permission(PurchaseDocuments::VIEW_ALL)]);

        $off = $this->postJson('/' . $this->module() . '/status/' . $documentId, ['status' => 'sent']);

        $this->assertFalse($off['success']);
        $this->assertSame(lang('Accounting.error_purchase_document_manual_status_off'), $off['message']);

        $this->turnOnManualStatus();

        $this->assertTrue($this->postJson('/' . $this->module() . '/status/' . $documentId, ['status' => 'approved'])['success']);
        $this->assertSame(PurchaseDocumentStatus::Approved, $this->documentsOf($this->kind())->statusOf($documentId));

        $current = lang(PurchaseDocumentStatus::Approved->languageKey());

        foreach (['cancelled', 'converted', 'received', 'partly_received', 'nonsense', ''] as $status) {
            $refused = $this->postJson('/' . $this->module() . '/status/' . $documentId, ['status' => $status]);
            $named   = PurchaseDocumentStatus::tryFrom($status);

            $this->assertFalse($refused['success'], $status);
            $this->assertSame(lang('Accounting.error_purchase_document_status_not_allowed', [$current, $named === null ? $status : lang($named->languageKey())]), $refused['message'], $status);
        }

        $this->assertSame('approved', $this->accountingDb()->table($this->kind()->table())->where($this->kind()->idColumn(), $documentId)->get()->getRow()->status);
    }

    public function testPostStatus_RequiresThePermissionToEdit(): void
    {
        $this->turnOnManualStatus();

        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $result = $this->postJson('/' . $this->module() . '/status/' . $documentId, ['status' => 'sent']);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $result['message']);
        $this->assertSame('open', $this->accountingDb()->table($this->kind()->table())->where($this->kind()->idColumn(), $documentId)->get()->getRow()->status);
    }

    public function testPostCancel_RequiresAReasonAndThePermissionToDelete(): void
    {
        $documentId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $denied = $this->postJson('/' . $this->module() . '/cancel/' . $documentId, ['reason' => 'x']);
        $this->assertFalse($denied['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $denied['message']);

        $this->actAsWith([$this->permission(PurchaseDocuments::DELETE), $this->permission(PurchaseDocuments::VIEW_ALL)]);

        $noReason = $this->postJson('/' . $this->module() . '/cancel/' . $documentId, ['reason' => '  ']);
        $this->assertFalse($noReason['success']);
        $this->assertSame(lang('Accounting.error_reason_required', [255]), $noReason['message']);

        $this->assertTrue($this->postJson('/' . $this->module() . '/cancel/' . $documentId, ['reason' => 'no longer needed'])['success']);
        $this->assertSame(PurchaseDocumentStatus::Cancelled, $this->documentsOf($this->kind())->statusOf($documentId));
    }

    public function testPostSettings_RequiresTheConfigPermissionAndSavesEverySwitch(): void
    {
        $this->setConfigValue('enable_purchase_cycle', '0');

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);
        $this->assertFalse($this->postJson('/' . $this->module() . '/settings', ['enabled' => '1', 'allow_excess' => '1'])['success']);
        $this->assertFalse($this->documentsOf($this->kind())->isEnabled());

        $this->actAsWith([PurchaseCycleSettings::PERMISSION, $this->permission(PurchaseDocuments::VIEW_ALL)]);
        $this->assertTrue($this->postJson('/' . $this->module() . '/settings', ['enabled' => '1', 'allow_excess' => '1', 'manual_' . $this->kind()->value => '1'])['success']);

        $settings = new PurchaseCycleSettings($this->accountingDb());

        $this->assertTrue($settings->isEnabled());
        $this->assertTrue($settings->allowsExcess());
        $this->assertTrue($settings->allowsManualStatus($this->kind()));

        foreach (PurchaseDocumentKind::cases() as $other) {
            if ($other !== $this->kind()) {
                $this->assertFalse($settings->allowsManualStatus($other), $other->value);
            }
        }
    }

    #[DataProvider('provideChange_IsNotReachableByAGetRequest')]
    public function testRoutes_RejectAGetRequestToAChange(string $action): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::ADD), $this->permission(PurchaseDocuments::EDIT), $this->permission(PurchaseDocuments::DELETE), $this->permission(PurchaseDocuments::CONVERT), $this->permission(PurchaseDocuments::VIEW_ALL), PurchaseCycleSettings::PERMISSION]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $this->module() . '/' . $action);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideChange_IsNotReachableByAGetRequest(): iterable
    {
        return [
            'save'     => ['save'],
            'save one' => ['save/1'],
            'status'   => ['status/1'],
            'cancel'   => ['cancel/1'],
            'convert'  => ['convert/1'],
            'settings' => ['settings'],
        ];
    }

    public function testGetItems_AnswersOnlyToWhoMayWriteADocumentWithTheCostOfTheItem(): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::ADD)]);

        $number = (string) $this->accountingDb()->table('items')->where('item_id', $this->goods)->get()->getRow()->item_number;
        $items  = json_decode($this->get('/' . $this->module() . '/items?term=' . rawurlencode($number))->getJSON(), true);

        $this->assertContains($this->goods, array_column($items, 'value'));
        $this->assertContains('4.00', array_map(static fn (array $item): string => number_format((float) $item['price'], 2, '.', ''), $items));

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $this->get('/' . $this->module() . '/items')->assertStatus(403);
    }

    public function testGetSuppliers_AnswersOnlyWhereTheDocumentHasASupplierAndToWhoMayWriteIt(): void
    {
        $this->actAsWith([$this->permission(PurchaseDocuments::ADD)]);

        if (! $this->kind()->hasSupplier()) {
            $this->get('/' . $this->module() . '/suppliers')->assertStatus(403);

            return;
        }

        $suppliers = json_decode($this->get('/' . $this->module() . '/suppliers?term=Gulf')->getJSON(), true);

        $this->assertContains($this->supplierId, array_column($suppliers, 'value'));

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $this->get('/' . $this->module() . '/suppliers')->assertStatus(403);
    }
}
