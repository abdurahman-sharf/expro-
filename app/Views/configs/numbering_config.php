<?php
/**
 * @var list<NumberingSequence> $numbering
 */

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Numbering\NumberingSequence;

$numbering ??= [];
?>

<div id="config_wrapper">
    <p class="text-body-secondary"><?= lang('Numbering.intro') ?></p>
    <p class="text-body-secondary"><?= lang('Numbering.rules') ?></p>

    <?php foreach ($numbering as $sequence): ?>
        <?php $type = $sequence->type;
        $key        = $type->value; ?>
        <?= form_open('config/saveNumbering/' . $key, ['id' => 'numbering_form_' . $key, 'class' => 'app-form numbering-form', 'data-type' => $key]) ?>
            <fieldset class="mb-4" id="numbering_<?= esc($key, 'attr') ?>">
                <legend class="h6">
                    <?= lang($type->labelKey()) ?>
                    <span class="badge <?= $sequence->enabled ? 'text-bg-success' : 'text-bg-secondary' ?> ms-2 numbering-status"><?= lang($sequence->enabled ? 'Numbering.status_on' : 'Numbering.status_off') ?></span>
                </legend>

                <?php if (in_array($type, [DocumentType::SalesInvoice, DocumentType::SalesQuote], true)): ?>
                    <p class="form-text"><?= lang('Numbering.invoice_note') ?></p>
                <?php endif; ?>

                <div class="row mb-3">
                    <?= form_label(lang('Numbering.enabled'), 'numbering_enabled_' . $key, ['class' => 'col-form-label col-6 col-md-3 col-lg-2']) ?>
                    <div class="col-6 col-md-9">
                        <?= form_checkbox([
                            'name'    => 'enabled',
                            'value'   => '1',
                            'id'      => 'numbering_enabled_' . $key,
                            'checked' => $sequence->enabled,
                        ]) ?>
                    </div>
                </div>

                <div class="row mb-3">
                    <?= form_label(lang('Numbering.prefix'), 'numbering_prefix_' . $key, ['class' => 'col-form-label col-6 col-md-3 col-lg-2']) ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <?= form_input([
                            'name'      => 'prefix',
                            'id'        => 'numbering_prefix_' . $key,
                            'class'     => 'form-control form-control-sm',
                            'value'     => $sequence->prefix,
                            'maxlength' => DocumentNumbering::MAX_PREFIX_LENGTH,
                            'dir'       => 'ltr',
                        ]) ?>
                        <div class="form-text"><?= lang('Numbering.prefix_help', [DocumentNumbering::MAX_PREFIX_LENGTH]) ?></div>
                    </div>
                </div>

                <div class="row mb-3">
                    <?= form_label(lang('Numbering.padding'), 'numbering_padding_' . $key, ['class' => 'col-form-label col-6 col-md-3 col-lg-2']) ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <?= form_input([
                            'name'  => 'padding',
                            'id'    => 'numbering_padding_' . $key,
                            'type'  => 'number',
                            'class' => 'form-control form-control-sm',
                            'value' => $sequence->padding,
                            'min'   => 0,
                            'max'   => DocumentNumbering::MAX_PADDING,
                            'dir'   => 'ltr',
                        ]) ?>
                        <div class="form-text"><?= lang('Numbering.padding_help') ?></div>
                    </div>
                </div>

                <div class="row mb-3">
                    <?= form_label(lang('Numbering.next_number'), 'numbering_next_' . $key, ['class' => 'col-form-label col-6 col-md-3 col-lg-2']) ?>
                    <div class="col-6 col-md-4 col-lg-3">
                        <?= form_input([
                            'name'  => 'next_number',
                            'id'    => 'numbering_next_' . $key,
                            'type'  => 'number',
                            'class' => 'form-control form-control-sm',
                            'value' => $sequence->nextNumber,
                            'min'   => $sequence->nextNumber,
                            'max'   => DocumentNumbering::MAX_NEXT,
                            'dir'   => 'ltr',
                        ]) ?>
                        <div class="form-text"><?= lang('Numbering.next_number_help', [$sequence->nextNumber]) ?></div>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-form-label col-6 col-md-3 col-lg-2"><?= lang('Numbering.preview') ?></div>
                    <div class="col-6 col-md-9 col-form-label"><bdi dir="ltr" class="numbering-preview"><?= esc($sequence->preview()) ?></bdi></div>
                </div>

                <?php if ($type->hasDuplicateOption()): ?>
                    <div class="row mb-3">
                        <?= form_label(lang('Numbering.prevent_duplicates'), 'numbering_duplicates_' . $key, ['class' => 'col-form-label col-6 col-md-3 col-lg-2']) ?>
                        <div class="col-6 col-md-9">
                            <?= form_checkbox([
                                'name'    => 'prevent_duplicates',
                                'value'   => '1',
                                'id'      => 'numbering_duplicates_' . $key,
                                'checked' => $sequence->preventDuplicates,
                            ]) ?>
                            <div class="form-text"><?= lang('Numbering.prevent_duplicates_help') ?></div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="settings-actions">
                    <?= form_submit([
                        'name'  => 'submit_numbering_' . $key,
                        'id'    => 'submit_numbering_' . $key,
                        'value' => lang('Common.submit'),
                        'class' => 'btn btn-primary',
                    ]) ?>
                </div>
            </fieldset>
        <?= form_close() ?>
    <?php endforeach; ?>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        $('.numbering-form').each(function() {
            const form = $(this);

            form.validate($.extend({}, form_support.handler, {
                submitHandler: function(target) {
                    $(target).ajaxSubmit({
                        success: function(response) {
                            $.notify({
                                message: response.message
                            }, {
                                type: response.success ? 'success' : 'danger'
                            });

                            if (response.success) {
                                const enabled = form.find('input[name="enabled"]').is(':checked');

                                form.find('input[name="next_number"]').val(response.next).attr('min', response.next);
                                form.find('.numbering-preview').text(response.preview);
                                form.find('.numbering-status')
                                    .text(enabled ? <?= json_encode(lang('Numbering.status_on')) ?> : <?= json_encode(lang('Numbering.status_off')) ?>)
                                    .toggleClass('text-bg-success', enabled)
                                    .toggleClass('text-bg-secondary', !enabled);
                            }
                        },
                        dataType: 'json'
                    });
                }
            }));
        });
    });
</script>
