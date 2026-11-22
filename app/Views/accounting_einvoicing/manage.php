<?php
/**
 * @var bool                                                                                                                 $enabled
 * @var bool                                                                                                                 $active
 * @var array<string, string>                                                                                                $values
 * @var string                                                                                                               $seller_name
 * @var string                                                                                                               $seller_vat
 * @var list<PartyIdentificationType>                                                                                        $seller_types
 * @var array<string, list<string>>                                                                                          $exemptions
 * @var list<ReadinessCheck>                                                                                                 $checks
 * @var array{total: int, businesses: int, rows: list<array{person_id: int, name: string, business: bool, problem: string}>} $customers
 * @var array{total: int, offset: int, size: int}                                                                            $customers_pager
 * @var list<array<string, mixed>>                                                                                           $documents
 * @var array{total: int, offset: int, size: int}                                                                            $documents_pager
 * @var list<array{debit_note_id: int, number: string, customer_id: int, customer: string, total: string, date: string}>     $notes
 * @var list<array{document_id: int, number: string, kind: EInvoiceKind, customer_id: int}>                                  $originals
 * @var bool                                                                                                                 $can_manage
 * @var bool                                                                                                                 $can_download
 * @var list<string>                                                                                                         $reasons
 * @var array<string, mixed>                                                                                                 $tax_profile
 */

use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\PartyIdentificationType;
use App\Libraries\EInvoicing\ReadinessCheck;

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$disabled  = $can_manage ? '' : ' disabled';
$icons     = [ReadinessCheck::PASS => 'bi-check-circle-fill text-success', ReadinessCheck::WARN => 'bi-exclamation-triangle-fill text-warning', ReadinessCheck::FAIL => 'bi-x-circle-fill text-danger'];
$detailOf  = static function (ReadinessCheck $check): string {
    if (! in_array($check->id, ['signing', 'transport'], true)) {
        return esc($check->detail);
    }

    $key = 'Accounting_einvoicing.detail_' . $check->detail;

    return lang($key) === $key ? esc($check->detail) : lang($key);
};
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const send = function(url, data, reload) {
            $.post(url, data, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success && reload) {
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 600);
                }
            }, 'json');
        };

        $('#saudi_mode_form').on('submit', function(event) {
            event.preventDefault();
            send('accounting_einvoicing/saudi_mode', $(this).serialize(), true);
        });

        $('#einvoice_settings_form').on('submit', function(event) {
            event.preventDefault();
            send('accounting_einvoicing/save_settings', $(this).serialize(), true);
        });

        $('#debit_note_form').on('submit', function(event) {
            event.preventDefault();
            const noteId = parseInt($('#debit_note_id').val(), 10);
            if (noteId > 0) {
                send('accounting_einvoicing/issue_debit_note/' + noteId, $(this).serialize(), true);
            }
        });

        const fillOriginals = function() {
            const customer = String($('#debit_note_id option:selected').data('customer'));
            const select = $('#original_document_id');
            select.find('option').each(function() {
                const fits = String($(this).data('customer')) === customer;
                $(this).prop('hidden', !fits).prop('disabled', !fits);
            });
            if (select.find('option:selected').prop('disabled')) {
                select.find('option:not(:disabled)').first().prop('selected', true);
            }
        };
        $('#debit_note_id').on('change', fillOriginals);
        fillOriginals();

        const categories = <?= json_encode($exemptions, $jsonFlags) ?>;
        const fillReasonCodes = function() {
            const codes = categories[$('#exempt_category').val()] || [];
            const selected = $('#exempt_reason_code').data('selected');
            const select = $('#exempt_reason_code').empty().append($('<option>').val('').text(''));
            codes.forEach(function(code) {
                select.append($('<option>').val(code).text(code).prop('selected', code === selected));
            });
        };
        $('#exempt_category').on('change', function() {
            $('#exempt_reason_code').data('selected', '');
            fillReasonCodes();
        });
        fillReasonCodes();
    });
</script>

<div class="container-fluid py-3" id="einvoicing_screen">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h1 class="h4"><?= lang('Accounting_einvoicing.title') ?></h1>
        <a class="btn btn-outline-secondary btn-sm" id="einvoice_zatca_link" href="<?= esc(site_url('accounting_zatca'), 'attr') ?>"><span class="bi bi-plug" aria-hidden="true"></span> <?= lang('Accounting_zatca.title') ?></a>
    </div>

    <div class="alert alert-warning" role="alert" id="einvoice_candidate_banner">
        <strong><?= lang('Accounting_einvoicing.candidate_title') ?></strong>
        <?= lang('Accounting_einvoicing.candidate_text') ?>
    </div>

    <section class="acc-setup-card" aria-labelledby="einvoice_status_title">
        <h2 id="einvoice_status_title" class="acc-setup-title"><?= lang('Accounting_einvoicing.readiness_title') ?></h2>
        <p class="mb-2">
            <span class="badge <?= $active ? 'text-bg-success' : 'text-bg-secondary' ?>" id="einvoice_state_badge"><?= lang($active ? 'Accounting_einvoicing.state_on' : 'Accounting_einvoicing.state_off') ?></span>
        </p>
        <ul class="acc-readiness list-unstyled" id="einvoice_checks">
            <?php foreach ($checks as $check): ?>
                <li data-check="<?= esc($check->id, 'attr') ?>" data-status="<?= esc($check->status, 'attr') ?>">
                    <span class="bi <?= $icons[$check->status] ?>" aria-hidden="true"></span>
                    <span class="visually-hidden"><?= lang('Accounting_einvoicing.status_' . $check->status) ?>:</span>
                    <?= lang('Accounting_einvoicing.check_' . $check->id) ?>
                    <?php if ($check->status !== ReadinessCheck::PASS): ?>
                        <small class="text-body-secondary d-block ms-4"><?= lang('Accounting_einvoicing.hint_' . $check->id . ($check->id === 'signing' && $check->status === ReadinessCheck::FAIL ? '_failed' : ''), [$detailOf($check)]) ?></small>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>

    <section class="acc-setup-card" aria-labelledby="tax_identity_title" id="tax_identity_card">
        <h2 id="tax_identity_title" class="acc-setup-title"><?= lang('Tax_profile.card_title') ?></h2>

        <dl class="row mb-2">
            <dt class="col-sm-4"><?= lang('Tax_profile.registration') ?></dt>
            <dd class="col-sm-8" id="tax_identity_registration"><?= lang($tax_profile['registration']->languageKey()) ?></dd>
            <dt class="col-sm-4"><?= lang('Tax_profile.vat_number') ?></dt>
            <dd class="col-sm-8"><bdi dir="ltr" id="tax_identity_number"><?= esc($tax_profile['vat_number']) ?></bdi> <small class="text-body-secondary"><?= lang('Tax_profile.vat_number_where') ?></small></dd>
            <dt class="col-sm-4"><?= lang('Tax_profile.mode') ?></dt>
            <dd class="col-sm-8" id="tax_identity_mode">
                <?php if (! $tax_profile['saudi_mode']): ?>
                    <?= lang('Tax_profile.mode_off') ?>
                <?php elseif ($tax_profile['in_effect']): ?>
                    <?= lang('Tax_profile.mode_on_since', [esc((string) $tax_profile['effective_at'])]) ?>
                <?php else: ?>
                    <?= lang('Tax_profile.mode_on_from', [esc((string) $tax_profile['effective_at'])]) ?>
                <?php endif; ?>
            </dd>
        </dl>

        <p class="form-text"><?= lang('Tax_profile.mode_help') ?></p>

        <?php if ($tax_profile['tax_invoice_title']): ?>
            <div class="alert alert-warning py-2" role="alert" id="tax_identity_title_warning"><?= lang('Tax_profile.warning_tax_invoice_title') ?></div>
        <?php endif; ?>

        <?php if ($tax_profile['can_switch']): ?>
            <form id="saudi_mode_form" class="app-form" novalidate>
                <?php if (! $tax_profile['saudi_mode']): ?>
                    <input type="hidden" name="action" value="enable">
                    <div class="form-grid">
                        <div class="field">
                            <label class="form-label" for="effective_at"><?= lang('Tax_profile.effective_at') ?></label>
                            <div class="field-control">
                                <input type="date" name="effective_at" id="effective_at" class="form-control form-control-sm">
                                <p class="form-text mb-0"><?= lang('Tax_profile.effective_at_help') ?></p>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm" id="saudi_mode_enable"><?= lang('Tax_profile.mode_enable') ?></button>
                <?php else: ?>
                    <input type="hidden" name="action" value="disable">
                    <div class="form-grid">
                        <div class="field span-all">
                            <label class="form-label" for="mode_reason"><?= lang('Tax_profile.reason') ?></label>
                            <div class="field-control">
                                <input type="text" name="reason" id="mode_reason" class="form-control form-control-sm" maxlength="500">
                                <?php if ($tax_profile['documents_issued']): ?>
                                    <p class="form-text mb-0"><?= lang('Tax_profile.mode_disable_needs_onboard') ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-danger btn-sm" id="saudi_mode_disable"><?= lang('Tax_profile.mode_disable') ?></button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </section>

    <section class="acc-setup-card" aria-labelledby="einvoice_settings_title">
        <h2 id="einvoice_settings_title" class="acc-setup-title"><?= lang('Accounting_einvoicing.settings_title') ?></h2>

        <form id="einvoice_settings_form" class="app-form" novalidate>
            <div class="form-grid">
                <div class="field field-check span-all">
                    <label class="form-label" for="enabled"><?= lang('Accounting_einvoicing.enabled') ?></label>
                    <div class="field-control">
                        <input type="checkbox" name="enabled" id="enabled" value="1" class="form-check-input"<?= $enabled ? ' checked' : '' ?><?= $disabled ?>>
                        <p class="form-text mb-0"><?= lang('Accounting_einvoicing.enabled_help') ?></p>
                    </div>
                </div>

                <div class="field">
                    <span class="form-label"><?= lang('Accounting_einvoicing.seller_name') ?></span>
                    <div class="field-control"><bdi id="seller_name"><?= esc($seller_name) ?></bdi> <small class="text-body-secondary"><?= lang('Accounting_einvoicing.seller_from_company') ?></small></div>
                </div>

                <div class="field">
                    <span class="form-label"><?= lang('Accounting_einvoicing.seller_vat') ?></span>
                    <div class="field-control"><bdi dir="ltr" id="seller_vat"><?= esc($seller_vat) ?></bdi> <small class="text-body-secondary"><?= lang('Accounting_einvoicing.seller_vat_rule') ?></small></div>
                </div>

                <div class="field">
                    <label class="form-label" for="seller_identification_type"><?= lang('Accounting_einvoicing.seller_identification_type') ?></label>
                    <div class="field-control">
                        <select name="seller_identification_type" id="seller_identification_type" class="form-select form-select-sm"<?= $disabled ?>>
                            <option value=""></option>
                            <?php foreach ($seller_types as $type): ?>
                                <option value="<?= esc($type->value, 'attr') ?>"<?= $values['seller_identification_type'] === $type->value ? ' selected' : '' ?>><?= esc($type->value) ?> - <?= lang($type->languageKey()) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="field">
                    <label class="form-label" for="seller_identification_number"><?= lang('Accounting_einvoicing.identification_number') ?></label>
                    <div class="field-control"><input type="text" name="seller_identification_number" id="seller_identification_number" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="60" value="<?= esc($values['seller_identification_number'], 'attr') ?>"<?= $disabled ?>></div>
                </div>

                <?php foreach (['seller_street' => 255, 'seller_additional_street' => 127, 'seller_building_number' => 4, 'seller_additional_number' => 10, 'seller_district' => 127, 'seller_city' => 127, 'seller_province' => 127, 'seller_postal_code' => 5] as $field => $length): ?>
                    <div class="field">
                        <label class="form-label" for="<?= esc($field, 'attr') ?>"><?= lang('Accounting_einvoicing.' . $field) ?></label>
                        <div class="field-control"><input type="text" name="<?= esc($field, 'attr') ?>" id="<?= esc($field, 'attr') ?>" class="form-control form-control-sm" maxlength="<?= $length ?>" value="<?= esc($values[$field], 'attr') ?>"<?= $disabled ?>></div>
                    </div>
                <?php endforeach; ?>

                <div class="field">
                    <label class="form-label" for="exempt_category"><?= lang('Accounting_einvoicing.exempt_category') ?></label>
                    <div class="field-control">
                        <select name="exempt_category" id="exempt_category" class="form-select form-select-sm"<?= $disabled ?>>
                            <option value=""></option>
                            <?php foreach (['Z', 'E', 'O'] as $category): ?>
                                <option value="<?= $category ?>"<?= $values['exempt_category'] === $category ? ' selected' : '' ?>><?= lang('Accounting_einvoicing.category_' . $category) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="form-text mb-0"><?= lang('Accounting_einvoicing.exempt_help') ?></p>
                    </div>
                </div>

                <div class="field">
                    <label class="form-label" for="exempt_reason_code"><?= lang('Accounting_einvoicing.exempt_reason_code') ?></label>
                    <div class="field-control"><select name="exempt_reason_code" id="exempt_reason_code" class="form-select form-select-sm" data-selected="<?= esc($values['exempt_reason_code'], 'attr') ?>"<?= $disabled ?>></select></div>
                </div>

                <div class="field span-all">
                    <label class="form-label" for="exempt_reason_text"><?= lang('Accounting_einvoicing.exempt_reason_text') ?></label>
                    <div class="field-control"><input type="text" name="exempt_reason_text" id="exempt_reason_text" class="form-control form-control-sm" maxlength="1000" value="<?= esc($values['exempt_reason_text'], 'attr') ?>"<?= $disabled ?>></div>
                </div>

                <?php if ($can_manage): ?>
                    <div class="field span-all">
                        <label class="form-label" for="key_path"><?= lang('Accounting_einvoicing.key_path') ?></label>
                        <div class="field-control">
                            <input type="text" name="key_path" id="key_path" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="500" value="<?= esc($values['key_path'], 'attr') ?>"<?= $disabled ?>>
                            <p class="form-text mb-0"><?= lang('Accounting_einvoicing.key_path_help') ?></p>
                        </div>
                    </div>

                    <div class="field span-all">
                        <label class="form-label" for="certificate_path"><?= lang('Accounting_einvoicing.certificate_path') ?></label>
                        <div class="field-control"><input type="text" name="certificate_path" id="certificate_path" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="500" value="<?= esc($values['certificate_path'], 'attr') ?>"<?= $disabled ?>></div>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($can_manage): ?>
                <button type="submit" class="btn btn-primary btn-sm" id="einvoice_save"><?= lang('Common.submit') ?></button>
            <?php else: ?>
                <p class="form-text"><?= lang('Accounting_einvoicing.settings_locked') ?></p>
            <?php endif; ?>
        </form>
    </section>

    <section class="acc-setup-card" aria-labelledby="einvoice_customers_title">
        <h2 id="einvoice_customers_title" class="acc-setup-title"><?= lang('Accounting_einvoicing.customers_title') ?></h2>
        <p class="mb-2" id="einvoice_customers_summary"><?= lang('Accounting_einvoicing.customers_summary', [$customers['total'], $customers['businesses']]) ?></p>
        <?php if ($customers['rows'] !== []): ?>
            <div class="table-responsive">
                <table class="table table-sm" id="einvoice_customers">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_einvoicing.customer') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.customer_kind') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.customer_problem') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($customers['rows'] as $row): ?>
                            <tr data-customer="<?= (int) $row['person_id'] ?>">
                                <td><?= esc($row['name']) ?></td>
                                <td><?= lang($row['business'] ? 'Accounting_einvoicing.customer_business' : 'Accounting_einvoicing.customer_individual') ?></td>
                                <td><?= lang('Accounting_einvoicing.problem_' . $row['problem']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= view('accounting_einvoicing/pager', [...$customers_pager, 'name' => 'customers_offset', 'anchor' => 'einvoice_customers_title']) ?>
        <?php endif; ?>
    </section>

    <section class="acc-setup-card" aria-labelledby="einvoice_documents_title">
        <h2 id="einvoice_documents_title" class="acc-setup-title"><?= lang('Accounting_einvoicing.documents_title') ?></h2>
        <?php if ($documents === []): ?>
            <p class="text-body-secondary"><?= lang('Accounting_einvoicing.documents_none') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm" id="einvoice_documents">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_einvoicing.number') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.kind') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.subtype') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.counter') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.issue_date') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.total') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.stamp') ?></th>
                            <th scope="col"><?= lang('Accounting_einvoicing.submission') ?></th>
                            <th scope="col"><span class="visually-hidden"><?= lang('Accounting_einvoicing.download') ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr data-document="<?= (int) $document['document_id'] ?>">
                                <td><bdi dir="ltr"><?= esc($document['number']) ?></bdi></td>
                                <td><?= lang($document['kind']->languageKey()) ?></td>
                                <td><?= lang($document['subtype']->languageKey()) ?></td>
                                <td><bdi dir="ltr"><?= (int) $document['icv'] ?></bdi></td>
                                <td><?= esc($document['date']) ?></td>
                                <td><bdi dir="ltr"><?= esc($document['total']) ?></bdi></td>
                                <td><?= lang($document['signed'] ? 'Accounting_einvoicing.state_signed' : 'Accounting_einvoicing.state_generated') ?></td>
                                <td><?= lang('Accounting_einvoicing.submission_' . $document['submission']) ?></td>
                                <td>
                                    <?php if ($can_download): ?>
                                        <a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('accounting_einvoicing/xml/' . (int) $document['document_id']), 'attr') ?>"><span class="bi bi-file-earmark-code" aria-hidden="true"></span> <?= lang('Accounting_einvoicing.download') ?></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?= view('accounting_einvoicing/pager', [...$documents_pager, 'name' => 'documents_offset', 'anchor' => 'einvoice_documents_title']) ?>
        <?php endif; ?>
    </section>

    <?php if ($can_download): ?>
        <section class="acc-setup-card" aria-labelledby="einvoice_debit_title">
            <h2 id="einvoice_debit_title" class="acc-setup-title"><?= lang('Accounting_einvoicing.debit_title') ?></h2>
            <p class="form-text"><?= lang('Accounting_einvoicing.debit_help') ?></p>
            <?php if ($notes === []): ?>
                <p class="text-body-secondary" id="einvoice_notes_none"><?= lang('Accounting_einvoicing.debit_note_none') ?></p>
            <?php else: ?>
                <form id="debit_note_form" class="app-form" novalidate>
                    <div class="form-grid">
                        <div class="field">
                            <label class="form-label" for="debit_note_id"><?= lang('Accounting_einvoicing.debit_note_id') ?></label>
                            <div class="field-control">
                                <select name="debit_note_id" id="debit_note_id" class="form-select form-select-sm">
                                    <?php foreach ($notes as $note): ?>
                                        <option value="<?= (int) $note['debit_note_id'] ?>" data-customer="<?= (int) $note['customer_id'] ?>"><?= esc($note['number']) ?> - <?= esc($note['customer']) ?> - <?= esc($note['total']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field">
                            <label class="form-label" for="original_document_id"><?= lang('Accounting_einvoicing.original_document') ?></label>
                            <div class="field-control">
                                <select name="original_document_id" id="original_document_id" class="form-select form-select-sm">
                                    <?php foreach ($originals as $original): ?>
                                        <option value="<?= (int) $original['document_id'] ?>" data-customer="<?= (int) $original['customer_id'] ?>"><?= esc($original['number']) ?> (<?= lang($original['kind']->languageKey()) ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="field span-all">
                            <label class="form-label" for="debit_reason"><?= lang('Accounting_einvoicing.reason') ?></label>
                            <div class="field-control">
                                <input type="text" name="reason" id="debit_reason" class="form-control form-control-sm" maxlength="1000" list="einvoice_reasons">
                                <datalist id="einvoice_reasons">
                                    <?php foreach ($reasons as $reason): ?>
                                        <option value="<?= esc($reason, 'attr') ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm"><?= lang('Accounting_einvoicing.issue_debit') ?></button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
