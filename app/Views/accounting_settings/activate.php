<?php
/**
 * @var array<string, mixed>|null                                   $status
 * @var bool                                                        $enabled
 * @var bool                                                        $locked
 * @var string                                                      $currency
 * @var string                                                      $currency_source
 * @var string                                                      $start_date
 * @var bool                                                        $needs_years
 * @var int                                                         $entities
 * @var bool                                                        $has_documents
 * @var array{before: array<string, int>, from: array<string, int>} $impact
 * @var list<string>                                                $payment_types
 * @var list<string>                                                $blockers
 * @var string|null                                                 $routing_url
 * @var list<string>                                                $steps
 * @var string                                                      $ack_impact
 * @var string                                                      $ack_documents
 * @var string                                                      $ack_payments
 */
$panels    = ['overview', 'currency', 'date', 'notices', 'review', 'run', 'result'];
$kinds     = ['sales', 'receivings', 'expenses', 'cashups'];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$texts     = [];

foreach (['progress_years', 'progress_settings', 'progress_entities', 'progress_commit', 'progress_verify', 'result_failed', 'ack_documents', 'impact_none'] as $key) {
    $texts[$key] = lang('Accounting_activation.' . $key);
}

foreach ($steps as $step) {
    $texts['step_name_' . $step] = lang('Accounting_activation.step_name_' . $step);
}

$unfinished = $status !== null && ! $status['done'];
?>

<?= view('partial/header') ?>

<div class="acc-wizard" id="activation_wizard">
    <h2 class="acc-wizard-title"><?= lang('Accounting_activation.title') ?></h2>
    <p class="text-secondary"><?= lang('Accounting_activation.intro') ?></p>

    <?php if ($unfinished): ?>
        <div class="alert alert-warning" id="resume_card" role="status">
            <strong><?= lang('Accounting_activation.resume_title') ?></strong>
            <p class="mb-2"><?= lang('Accounting_activation.resume_text') ?></p>
            <button type="button" class="btn btn-sm btn-warning" id="resume_run" data-run="<?= esc((string) $status['run_id'], 'attr') ?>"><?= lang('Accounting_activation.resume_button') ?></button>
        </div>
    <?php endif; ?>

    <ol class="acc-wizard-steps" id="wizard_steps">
        <?php foreach ($panels as $panel): ?>
            <li data-step-of="<?= $panel ?>"><?= lang('Accounting_activation.step_' . $panel) ?></li>
        <?php endforeach; ?>
    </ol>

    <section class="acc-wizard-panel" data-panel="overview">
        <p><?= lang('Accounting_activation.overview_intro') ?></p>
        <ul>
            <li><?= lang('Accounting_activation.auto_settings') ?></li>
            <?php if ($needs_years): ?>
                <li><?= lang('Accounting_activation.auto_years') ?></li>
            <?php endif; ?>
            <?php if ($entities > 0): ?>
                <li><?= lang('Accounting_activation.auto_entities', [(string) $entities]) ?></li>
            <?php endif; ?>
        </ul>
        <p class="text-secondary"><?= lang('Accounting_activation.overview_nothing') ?></p>
        <div class="acc-wizard-actions">
            <button type="button" class="btn btn-primary" data-go="currency"><?= lang('Accounting_activation.next') ?></button>
        </div>
    </section>

    <section class="acc-wizard-panel" data-panel="currency" hidden>
        <label class="form-label" for="currency"><?= lang('Accounting_activation.currency_label') ?></label>
        <input type="text" class="form-control form-control-sm acc-wizard-input" id="currency" maxlength="3" dir="ltr" autocomplete="off" value="<?= esc($currency, 'attr') ?>"<?= $locked ? ' readonly' : '' ?>>
        <p class="form-text"><?= lang('Accounting_activation.currency_source_' . $currency_source) ?> <?= lang('Accounting_activation.currency_help') ?></p>
        <?php if ($locked): ?>
            <p class="text-warning"><?= lang('Accounting_activation.currency_locked') ?></p>
        <?php endif; ?>
        <p class="text-danger" id="currency_error" hidden></p>
        <div class="acc-wizard-actions">
            <button type="button" class="btn btn-outline-secondary" data-go="overview"><?= lang('Accounting_activation.back') ?></button>
            <button type="button" class="btn btn-primary" id="currency_next"><?= lang('Accounting_activation.next') ?></button>
        </div>
    </section>

    <section class="acc-wizard-panel" data-panel="date" hidden>
        <label class="form-label" for="start_date"><?= lang('Accounting_activation.date_label') ?></label>
        <input type="date" class="form-control form-control-sm acc-wizard-input" id="start_date" value="<?= esc($start_date, 'attr') ?>"<?= $locked ? ' readonly' : '' ?>>
        <p class="form-text"><?= lang('Accounting_activation.date_help') ?></p>

        <div class="acc-wizard-impact" id="impact_panel" aria-live="polite">
            <h3 class="acc-wizard-subtitle"><?= lang('Accounting_activation.impact_title') ?></h3>
            <table class="table table-sm" id="impact_table">
                <thead>
                    <tr>
                        <th scope="col"></th>
                        <th scope="col"><?= lang('Accounting_activation.impact_before') ?></th>
                        <th scope="col"><?= lang('Accounting_activation.impact_from') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($kinds as $kind): ?>
                        <tr data-kind="<?= $kind ?>">
                            <th scope="row"><?= lang('Accounting_activation.impact_kind_' . $kind) ?></th>
                            <td class="before"><?= (int) $impact['before'][$kind] ?></td>
                            <td class="from"><?= (int) $impact['from'][$kind] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="text-secondary" id="impact_none" <?= $has_documents ? 'hidden' : '' ?>><?= lang('Accounting_activation.impact_none') ?></p>
            <p><?= lang('Accounting_activation.impact_explain_before') ?></p>
            <p><?= lang('Accounting_activation.impact_explain_dates') ?></p>
            <p id="impact_from_text" hidden><?= lang('Accounting_activation.impact_explain_from') ?></p>
        </div>

        <div class="form-check">
            <input type="checkbox" class="form-check-input" id="ack_impact" value="<?= esc($ack_impact, 'attr') ?>">
            <label class="form-check-label" for="ack_impact"><?= lang('Accounting_activation.ack_impact') ?></label>
        </div>
        <div class="form-check" id="ack_documents_row" hidden>
            <input type="checkbox" class="form-check-input" id="ack_documents" value="<?= esc($ack_documents, 'attr') ?>">
            <label class="form-check-label" for="ack_documents" id="ack_documents_label"></label>
        </div>
        <p class="text-danger" id="date_error" hidden></p>
        <div class="acc-wizard-actions">
            <button type="button" class="btn btn-outline-secondary" data-go="currency"><?= lang('Accounting_activation.back') ?></button>
            <button type="button" class="btn btn-primary" id="date_next"><?= lang('Accounting_activation.next') ?></button>
        </div>
    </section>

    <section class="acc-wizard-panel" data-panel="notices" hidden>
        <?php if ($blockers !== []): ?>
            <div class="alert alert-danger" role="alert">
                <strong><?= lang('Accounting_activation.blockers_title') ?></strong>
                <ul class="mb-0">
                    <?php foreach ($blockers as $blocker): ?>
                        <li><?= esc($blocker) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($payment_types !== []): ?>
            <div class="alert alert-warning" role="alert">
                <strong><?= lang('Accounting_activation.payment_types_title') ?></strong>
                <p class="mb-1"><?= lang('Accounting_activation.payment_types_help') ?></p>
                <ul>
                    <?php foreach ($payment_types as $type): ?>
                        <li><?= esc($type) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="form-check">
                <input type="checkbox" class="form-check-input" id="ack_payment_types" value="<?= esc($ack_payments, 'attr') ?>">
                <label class="form-check-label" for="ack_payment_types"><?= lang('Accounting_activation.ack_payment_types') ?></label>
            </div>
        <?php endif; ?>

        <?php if ($blockers === [] && $payment_types === []): ?>
            <p class="text-success"><?= lang('Accounting_activation.no_notices') ?></p>
        <?php endif; ?>

        <?php if ($routing_url !== null && ($blockers !== [] || $payment_types !== [])): ?>
            <p><a href="<?= esc($routing_url, 'attr') ?>"><?= lang('Accounting_activation.routing_link') ?></a></p>
        <?php endif; ?>

        <p class="text-danger" id="notices_error" hidden></p>
        <div class="acc-wizard-actions">
            <button type="button" class="btn btn-outline-secondary" data-go="date"><?= lang('Accounting_activation.back') ?></button>
            <button type="button" class="btn btn-primary" id="notices_next"<?= $blockers !== [] ? ' disabled' : '' ?>><?= lang('Accounting_activation.next') ?></button>
        </div>
    </section>

    <section class="acc-wizard-panel" data-panel="review" hidden>
        <h3 class="acc-wizard-subtitle"><?= lang('Accounting_activation.review_title') ?></h3>
        <dl class="acc-wizard-summary">
            <dt><?= lang('Accounting_activation.currency_label') ?></dt>
            <dd><bdi dir="ltr" id="review_currency"></bdi></dd>
            <dt><?= lang('Accounting_activation.date_label') ?></dt>
            <dd><bdi dir="ltr" id="review_date"></bdi></dd>
            <?php if ($entities > 0): ?>
                <dt><?= lang('Accounting_activation.review_entities') ?></dt>
                <dd><?= $entities ?></dd>
            <?php endif; ?>
        </dl>
        <p class="text-secondary"><?= lang('Accounting_activation.review_note') ?></p>
        <p class="text-danger" id="begin_error" hidden></p>
        <div class="acc-wizard-actions">
            <button type="button" class="btn btn-outline-secondary" data-go="notices"><?= lang('Accounting_activation.back') ?></button>
            <button type="button" class="btn btn-success" id="agree"><?= lang('Accounting_activation.agree') ?></button>
        </div>
    </section>

    <section class="acc-wizard-panel" data-panel="run" hidden>
        <div class="progress" role="progressbar" aria-label="<?= esc(lang('Accounting_activation.step_run'), 'attr') ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="progress_holder">
            <div class="progress-bar progress-bar-striped progress-bar-animated" id="progress_bar" style="width: 5%"></div>
        </div>
        <p class="mt-2" id="progress_text" aria-live="polite"></p>
        <div class="alert alert-danger" id="run_error" role="alert" hidden>
            <p class="mb-1" id="run_error_text"></p>
            <p class="mb-2 text-secondary"><?= lang('Accounting_activation.result_failed_help') ?></p>
            <button type="button" class="btn btn-sm btn-danger" id="run_retry"><?= lang('Accounting_activation.retry') ?></button>
        </div>
    </section>

    <section class="acc-wizard-panel" data-panel="result" hidden>
        <div class="alert alert-success" role="status"><?= lang('Accounting_activation.result_success') ?></div>
        <p class="text-secondary"><?= lang('Accounting_activation.result_permissions') ?></p>
        <a class="btn btn-primary" href="<?= esc(site_url('accounting_reports/overview'), 'attr') ?>"><?= lang('Accounting_activation.result_open') ?></a>
    </section>
</div>

<script type="text/javascript">
    $(document).ready(function() {
        const texts = <?= json_encode($texts, $jsonFlags) ?>;
        const panels = <?= json_encode($panels, $jsonFlags) ?>;
        const steps = <?= json_encode($steps, $jsonFlags) ?>;
        const kinds = <?= json_encode($kinds, $jsonFlags) ?>;
        let runId = <?= json_encode($unfinished ? (string) $status['run_id'] : null, $jsonFlags) ?>;

        const fill = function(template, values) {
            return template.replace(/\{(\d+)\}/g, function(match, index) {
                return values[index] === undefined ? match : values[index];
            });
        };

        const show = function(panel) {
            $('.acc-wizard-panel').prop('hidden', true);
            $('[data-panel="' + panel + '"]').prop('hidden', false);
            $('#wizard_steps li').removeClass('is-current is-done');

            const index = panels.indexOf(panel);

            $('#wizard_steps li').each(function(position) {
                $(this).toggleClass('is-current', position === index).toggleClass('is-done', position < index);
            });
        };

        const fail = function(selector, message) {
            $(selector).text(message).prop('hidden', false);
        };

        const renderImpact = function(impact) {
            let before = 0;
            let from = 0;

            kinds.forEach(function(kind) {
                const row = $('#impact_table tr[data-kind="' + kind + '"]');

                row.find('.before').text(impact.before[kind]);
                row.find('.from').text(impact.from[kind]);
                before += impact.before[kind];
                from += impact.from[kind];
            });

            $('#impact_none').prop('hidden', before + from > 0);
            $('#impact_from_text').prop('hidden', from === 0);
            $('#ack_documents_row').prop('hidden', from === 0);
            $('#ack_documents_label').text(fill(texts.ack_documents, [from]));

            if (from === 0) {
                $('#ack_documents').prop('checked', false);
            }
        };

        const loadImpact = function() {
            const date = $('#start_date').val();

            if (!date) {
                return;
            }

            $.getJSON('accounting_settings/activation_impact', {date: date}, function(response) {
                if (response.success) {
                    renderImpact(response.impact);
                }
            });
        };

        const acknowledged = function() {
            return $('#ack_impact:checked, #ack_documents:checked, #ack_payment_types:checked').map(function() {
                return this.value;
            }).get();
        };

        const progress = function(run) {
            const known = {years: texts.progress_years, settings: texts.progress_settings, commit: texts.progress_commit, verify: texts.progress_verify};
            const position = steps.indexOf(run.step);
            const percent = Math.max(5, Math.round((position / steps.length) * 100));

            $('#progress_bar').css('width', percent + '%');
            $('#progress_holder').attr('aria-valuenow', percent);
            $('#progress_text').text(run.step === 'entities' ? fill(texts.progress_entities, [run.entities_created, run.entities_total]) : known[run.step]);
        };

        const step = function() {
            $('#run_error').prop('hidden', true);
            show('run');

            $.post('accounting_settings/activation_step', {run_id: runId}, function(response) {
                if (!response.success) {
                    $('#run_error_text').text(response.message);
                    $('#run_error').prop('hidden', false);
                    $('#progress_bar').removeClass('progress-bar-animated');

                    return;
                }

                progress(response.run);

                if (response.run.done) {
                    show('result');

                    return;
                }

                window.setTimeout(step, 150);
            }, 'json').fail(function() {
                $('#run_error_text').text(texts.result_failed.replace('{0}', ''));
                $('#run_error').prop('hidden', false);
            });
        };

        $('[data-go]').on('click', function() {
            show($(this).data('go'));
        });

        $('#currency').on('input', function() {
            this.value = this.value.toUpperCase().replace(/[^A-Z]/g, '');
        });

        $('#currency_next').on('click', function() {
            if (!/^[A-Z]{3}$/.test($('#currency').val())) {
                fail('#currency_error', <?= json_encode(lang('Accounting.error_invalid_currency'), $jsonFlags) ?>);

                return;
            }

            $('#currency_error').prop('hidden', true);
            show('date');
            loadImpact();
        });

        $('#start_date').on('change', loadImpact);

        $('#date_next').on('click', function() {
            if (!$('#start_date').val()) {
                fail('#date_error', <?= json_encode(lang('Accounting.error_invalid_date'), $jsonFlags) ?>);

                return;
            }

            if (!$('#ack_impact').is(':checked') || (!$('#ack_documents_row').prop('hidden') && !$('#ack_documents').is(':checked'))) {
                fail('#date_error', <?= json_encode(lang('Accounting_activation.ack_required'), $jsonFlags) ?>);

                return;
            }

            $('#date_error').prop('hidden', true);
            show('notices');
        });

        $('#notices_next').on('click', function() {
            if ($('#ack_payment_types').length && !$('#ack_payment_types').is(':checked')) {
                fail('#notices_error', <?= json_encode(lang('Accounting_activation.ack_required'), $jsonFlags) ?>);

                return;
            }

            $('#notices_error').prop('hidden', true);
            $('#review_currency').text($('#currency').val());
            $('#review_date').text($('#start_date').val());
            show('review');
        });

        $('#agree').on('click', function() {
            const button = $(this).prop('disabled', true);

            $('#begin_error').prop('hidden', true);

            $.post('accounting_settings/activation_begin', {currency: $('#currency').val(), start_date: $('#start_date').val(), ack: acknowledged()}, function(response) {
                if (!response.success) {
                    button.prop('disabled', false);
                    fail('#begin_error', response.message);

                    return;
                }

                runId = response.run.run_id;
                step();
            }, 'json').fail(function() {
                button.prop('disabled', false);
            });
        });

        $('#run_retry').on('click', step);

        $('#resume_run').on('click', function() {
            runId = $(this).data('run');
            step();
        });

        show('overview');
    });
</script>

<?= view('partial/footer') ?>
