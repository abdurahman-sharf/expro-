<?php

use App\Libraries\EInvoicing\IssuedEInvoice;

/**
 * @var array{state: string, submission: string, document: ?IssuedEInvoice, in_sync: bool, can_issue: bool, is_return: bool, can_manage: bool, default_subtype: string, original_issued: bool} $einvoice
 * @var int                                                                                                                                                                                    $sale_id
 * @var list<string>                                                                                                                                                                           $reasons
 */
$document   = $einvoice['document'];
$stateClass = match ($einvoice['state']) {
    'signed'    => 'text-bg-success',
    'generated' => 'text-bg-primary',
    default     => 'text-bg-secondary',
};
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<div class="print_hide card card-body my-2" id="einvoice_panel" data-state="<?= esc($einvoice['state'], 'attr') ?>">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <strong><?= lang('Accounting_einvoicing.panel_title') ?></strong>
        <span class="badge text-bg-warning" title="<?= esc(lang('Accounting_einvoicing.candidate_text'), 'attr') ?>"><?= lang('Accounting_einvoicing.candidate_badge') ?></span>
        <span class="badge <?= $stateClass ?>" id="einvoice_state"><?= lang('Accounting_einvoicing.state_' . $einvoice['state']) ?></span>
        <span class="badge text-bg-light border" id="einvoice_submission"><?= lang('Accounting_einvoicing.submission_' . $einvoice['submission']) ?></span>
    </div>

    <?php if ($document !== null): ?>
        <dl class="row mb-0 mt-2 small">
            <dt class="col-sm-3"><?= lang('Accounting_einvoicing.number') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc($document->number) ?></bdi> (<?= lang($document->kind->languageKey()) ?>, <?= lang($document->subtype->languageKey()) ?>)</dd>
            <dt class="col-sm-3"><?= lang('Accounting_einvoicing.counter') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= (int) $document->icv ?></bdi></dd>
            <dt class="col-sm-3"><?= lang('Accounting_einvoicing.uuid') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc($document->uuid) ?></bdi></dd>
            <dt class="col-sm-3"><?= lang('Accounting_einvoicing.hash') ?></dt>
            <dd class="col-sm-9"><bdi dir="ltr"><?= esc($document->hash) ?></bdi></dd>
        </dl>

        <?php if (! $einvoice['in_sync']): ?>
            <div class="alert alert-warning mt-2 mb-0" role="alert" id="einvoice_drift"><?= lang('Accounting_einvoicing.drift') ?></div>
        <?php endif; ?>

        <?php if ($einvoice['can_manage']): ?>
            <p class="mt-2 mb-0"><a class="btn btn-outline-secondary btn-sm" id="einvoice_download" href="<?= esc(site_url('accounting_einvoicing/xml/' . $document->documentId), 'attr') ?>"><span class="bi bi-file-earmark-code" aria-hidden="true"></span> <?= lang('Accounting_einvoicing.download') ?></a></p>
        <?php endif; ?>
    <?php elseif ($einvoice['can_issue']): ?>
        <?php if ($einvoice['is_return'] && ! $einvoice['original_issued']): ?>
            <p class="mt-2 mb-0 text-body-secondary" id="einvoice_original_missing"><?= lang('Accounting_einvoicing.original_missing') ?></p>
        <?php else: ?>
            <form id="einvoice_issue_form" class="d-flex flex-wrap align-items-end gap-2 mt-2">
                <?php if (! $einvoice['is_return']): ?>
                    <div>
                        <label class="form-label" for="einvoice_subtype"><?= lang('Accounting_einvoicing.subtype') ?></label>
                        <select name="subtype" id="einvoice_subtype" class="form-select form-select-sm">
                            <?php foreach (['standard', 'simplified'] as $subtype): ?>
                                <option value="<?= $subtype ?>"<?= $einvoice['default_subtype'] === $subtype ? ' selected' : '' ?>><?= lang('Accounting_einvoicing.subtype_' . $subtype) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="flex-grow-1">
                        <label class="form-label" for="einvoice_reason"><?= lang('Accounting_einvoicing.reason') ?></label>
                        <input type="text" name="reason" id="einvoice_reason" class="form-control form-control-sm" maxlength="1000" list="einvoice_reasons">
                        <datalist id="einvoice_reasons">
                            <?php foreach ($reasons as $reason): ?>
                                <option value="<?= esc($reason, 'attr') ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                <?php endif; ?>
                <button type="submit" class="btn btn-outline-primary btn-sm" id="einvoice_issue"><span class="bi bi-file-earmark-code" aria-hidden="true"></span> <?= lang('Accounting_einvoicing.issue') ?></button>
            </form>
            <script type="text/javascript">
                $(document).ready(function() {
                    $('#einvoice_issue_form').on('submit', function(event) {
                        event.preventDefault();
                        $.post(<?= json_encode(site_url('accounting_einvoicing/issue/' . $sale_id), $jsonFlags) ?>, $(this).serialize(), function(response) {
                            toast_support.show(response.message, response.success ? 'success' : 'danger');
                            if (response.success) {
                                window.setTimeout(function() {
                                    window.location.reload();
                                }, 600);
                            }
                        }, 'json');
                    });
                });
            </script>
        <?php endif; ?>
    <?php endif; ?>
</div>
