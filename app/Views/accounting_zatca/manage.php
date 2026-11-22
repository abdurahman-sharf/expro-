<?php
/**
 * @var array{environments: array<string, list<string>>, enabled: bool, environment: string, phase_two: bool, unit: object|null, certificates: list<array<string, mixed>>, queue: array<string, int>} $overview
 * @var list<array<string, mixed>>                                                                                                                                                                 $documents
 * @var string|null                                                                                                                                                                                $status
 * @var list<string>                                                                                                                                                                               $statuses
 * @var int                                                                                                                                                                                        $offset
 * @var int                                                                                                                                                                                        $page
 * @var list<string>                                                                                                                                                                               $environments
 * @var bool                                                                                                                                                                                       $can_onboard
 * @var bool                                                                                                                                                                                       $can_manage
 * @var array<string, string>                                                                                                                                                                      $unit_fields
 */
$onboard = $can_onboard ? '' : ' disabled';
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const send = function(url, data, reload, after) {
            $.post(url, data, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (after) {
                    after(response);
                }
                if (response.success && reload) {
                    window.setTimeout(function() {
                        window.location.reload();
                    }, 600);
                }
            }, 'json');
        };

        $('form[data-zatca]').on('submit', function(event) {
            event.preventDefault();
            const form = $(this);
            const action = $(event.originalEvent && event.originalEvent.submitter ? event.originalEvent.submitter : form.find('[type=submit]').first());
            const data = form.serializeArray();
            if (action.attr('name')) {
                data.push({name: action.attr('name'), value: action.val()});
            }
            send(form.data('zatca'), $.param(data), form.data('reload') === 1, function(response) {
                const list = $('#zatca_check_results').empty();
                (response.results || []).forEach(function(result) {
                    list.append($('<li>').text(result.subtype + ' / ' + result.kind + ': ' + result.outcome + (result.errors.length ? ' - ' + result.errors.join('; ') : '')));
                });
            });
        });

        $('button[data-document-action]').on('click', function() {
            send($(this).data('document-action'), {}, true);
        });
    });
</script>

<div class="container-fluid py-3" id="zatca_screen">
    <h1 class="h4"><?= lang('Accounting_zatca.title') ?></h1>

    <div class="alert alert-warning" role="alert" id="zatca_offline_banner">
        <strong><?= lang('Accounting_zatca.offline_title') ?></strong>
        <?= lang('Accounting_zatca.offline_text') ?>
    </div>

    <section class="acc-setup-card" aria-labelledby="zatca_status_title">
        <h2 id="zatca_status_title" class="acc-setup-title"><?= lang('Accounting_zatca.status_title') ?></h2>
        <div class="table-responsive">
            <table class="table table-sm align-middle" id="zatca_environments">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_zatca.environment') ?></th>
                        <th scope="col"><?= lang('Accounting_zatca.can_send') ?></th>
                        <th scope="col"><?= lang('Accounting_zatca.blocked_by') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($overview['environments'] as $environment => $reasons): ?>
                        <tr data-environment="<?= esc($environment, 'attr') ?>">
                            <td><?= lang('Accounting_zatca.environment_' . $environment) ?></td>
                            <td><span class="badge <?= $reasons === [] ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= lang($reasons === [] ? 'Accounting_zatca.open' : 'Accounting_zatca.closed') ?></span></td>
                            <td><?= esc(implode(', ', array_map(static fn (string $reason): string => lang('Accounting_zatca.reason_' . $reason), $reasons))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <ul class="list-unstyled mb-0" id="zatca_state">
            <li><?= lang('Accounting_zatca.integration') ?>: <strong><?= lang($overview['enabled'] ? 'Accounting_zatca.on_for' : 'Accounting_zatca.off', [lang('Accounting_zatca.environment_' . $overview['environment'])]) ?></strong></li>
            <li><?= lang('Accounting_zatca.phase_two') ?>: <strong><?= lang($overview['phase_two'] ? 'Accounting_zatca.on' : 'Accounting_zatca.off') ?></strong></li>
            <li><?= lang('Accounting_zatca.unit') ?>: <strong><?= esc((string) ($overview['unit']->name ?? '')) ?></strong> (<?= lang('Accounting_zatca.unit_' . (string) ($overview['unit']->status ?? 'draft')) ?>)</li>
        </ul>
    </section>

    <section class="acc-setup-card" aria-labelledby="zatca_certificates_title">
        <h2 id="zatca_certificates_title" class="acc-setup-title"><?= lang('Accounting_zatca.certificates_title') ?></h2>
        <?php if ($overview['certificates'] === []): ?>
            <p class="text-body-secondary" id="zatca_certificates_none"><?= lang('Accounting_zatca.certificates_none') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="zatca_certificates">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_zatca.environment') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.certificate_kind') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.certificate_status') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.valid_to') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.days_left') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($overview['certificates'] as $certificate): ?>
                            <tr data-credential="<?= (int) $certificate['credential_id'] ?>">
                                <td><?= lang('Accounting_zatca.environment_' . $certificate['environment']) ?></td>
                                <td><?= lang('Accounting_zatca.kind_' . $certificate['kind']) ?></td>
                                <td><?= lang('Accounting_zatca.certificate_' . $certificate['status']) ?></td>
                                <td><bdi dir="ltr"><?= esc($certificate['valid_to']) ?></bdi></td>
                                <td class="<?= $certificate['status'] === 'active' && $certificate['days_left'] !== null && $certificate['days_left'] <= 30 ? 'text-danger fw-semibold' : '' ?>"><bdi dir="ltr"><?= esc((string) ($certificate['days_left'] ?? '')) ?></bdi></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($can_onboard): ?>
        <section class="acc-setup-card" aria-labelledby="zatca_switch_title">
            <h2 id="zatca_switch_title" class="acc-setup-title"><?= lang('Accounting_zatca.switch_title') ?></h2>
            <p class="form-text"><?= lang('Accounting_zatca.switch_help') ?></p>
            <form id="zatca_integration_form" class="app-form" data-zatca="<?= esc(site_url('accounting_zatca/integration'), 'attr') ?>" data-reload="1" novalidate>
                <div class="form-grid">
                    <div class="field">
                        <label class="form-label" for="integration_environment"><?= lang('Accounting_zatca.environment') ?></label>
                        <div class="field-control">
                            <select name="environment" id="integration_environment" class="form-select form-select-sm">
                                <?php foreach ($environments as $environment): ?>
                                    <option value="<?= esc($environment, 'attr') ?>"<?= $environment === $overview['environment'] ? ' selected' : '' ?>><?= lang('Accounting_zatca.environment_' . $environment) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="field span-all">
                        <label class="form-label" for="integration_reason"><?= lang('Accounting_zatca.reason') ?></label>
                        <div class="field-control"><input type="text" name="reason" id="integration_reason" class="form-control form-control-sm" maxlength="500" required></div>
                    </div>
                </div>
                <button type="submit" name="action" value="enable" class="btn btn-outline-primary btn-sm"><?= lang('Accounting_zatca.turn_on') ?></button>
                <button type="submit" name="action" value="disable" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_zatca.turn_off') ?></button>
            </form>

            <form id="zatca_phase_two_form" class="app-form mt-3" data-zatca="<?= esc(site_url('accounting_zatca/phase_two'), 'attr') ?>" data-reload="1" novalidate>
                <p class="form-text"><?= lang('Accounting_zatca.phase_two_help') ?></p>
                <div class="form-grid">
                    <div class="field span-all">
                        <label class="form-label" for="phase_two_reason"><?= lang('Accounting_zatca.reason') ?></label>
                        <div class="field-control"><input type="text" name="reason" id="phase_two_reason" class="form-control form-control-sm" maxlength="500" required></div>
                    </div>
                </div>
                <button type="submit" name="action" value="enable" class="btn btn-outline-primary btn-sm"><?= lang('Accounting_zatca.phase_two_turn_on') ?></button>
                <button type="submit" name="action" value="disable" class="btn btn-outline-secondary btn-sm"><?= lang('Accounting_zatca.phase_two_turn_off') ?></button>
            </form>
        </section>

        <section class="acc-setup-card" aria-labelledby="zatca_onboarding_title">
            <h2 id="zatca_onboarding_title" class="acc-setup-title"><?= lang('Accounting_zatca.onboarding_title') ?></h2>
            <p class="form-text"><?= lang('Accounting_zatca.onboarding_help') ?></p>
            <?php foreach (['compliance' => true, 'compliance_checks' => false, 'production' => false, 'renew' => true] as $step => $withUnit): ?>
                <form id="zatca_<?= $step ?>_form" class="app-form border-top pt-2 mt-2" data-zatca="<?= esc(site_url('accounting_zatca/' . $step), 'attr') ?>" data-reload="<?= $step === 'compliance_checks' ? 0 : 1 ?>" novalidate>
                    <h3 class="h6"><?= lang('Accounting_zatca.step_' . $step) ?></h3>
                    <div class="form-grid">
                        <div class="field">
                            <label class="form-label" for="<?= $step ?>_environment"><?= lang('Accounting_zatca.environment') ?></label>
                            <div class="field-control">
                                <select name="environment" id="<?= $step ?>_environment" class="form-select form-select-sm">
                                    <?php foreach ($environments as $environment): ?>
                                        <option value="<?= esc($environment, 'attr') ?>"><?= lang('Accounting_zatca.environment_' . $environment) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <?php if ($withUnit): ?>
                            <?php foreach ($unit_fields as $field => $value): ?>
                                <div class="field">
                                    <label class="form-label" for="<?= $step . '_' . $field ?>"><?= lang('Accounting_zatca.unit_field_' . $field) ?></label>
                                    <div class="field-control"><input type="text" name="<?= $field ?>" id="<?= $step . '_' . $field ?>" class="form-control form-control-sm" value="<?= esc($value, 'attr') ?>" dir="auto"></div>
                                </div>
                            <?php endforeach; ?>
                            <div class="field">
                                <label class="form-label" for="<?= $step ?>_otp"><?= lang('Accounting_zatca.otp') ?></label>
                                <div class="field-control"><input type="password" name="otp" id="<?= $step ?>_otp" class="form-control form-control-sm" inputmode="numeric" maxlength="6" autocomplete="one-time-code"></div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" class="btn btn-outline-primary btn-sm"<?= $onboard ?>><?= lang('Accounting_zatca.run_' . $step) ?></button>
                </form>
            <?php endforeach; ?>
            <ul class="small mt-2" id="zatca_check_results"></ul>
        </section>
    <?php endif; ?>

    <section class="acc-setup-card" aria-labelledby="zatca_queue_title">
        <h2 id="zatca_queue_title" class="acc-setup-title"><?= lang('Accounting_zatca.queue_title') ?></h2>
        <p class="mb-2" id="zatca_queue_counts">
            <?php foreach ($statuses as $one): ?>
                <a class="badge text-decoration-none <?= $status === $one ? 'text-bg-primary' : 'text-bg-light' ?>" href="<?= esc(site_url('accounting_zatca') . '?status=' . $one, 'attr') ?>"><?= lang('Accounting_zatca.state_' . $one) ?>: <bdi dir="ltr"><?= (int) ($overview['queue'][$one] ?? 0) ?></bdi></a>
            <?php endforeach; ?>
            <a class="badge text-decoration-none text-bg-light" href="<?= esc(site_url('accounting_zatca'), 'attr') ?>"><?= lang('Accounting_zatca.all') ?></a>
        </p>
        <?php if (($overview['queue']['late'] ?? 0) > 0): ?>
            <div class="alert alert-danger py-2" role="alert" id="zatca_late"><?= lang('Accounting_zatca.late', [(int) $overview['queue']['late']]) ?></div>
        <?php endif; ?>
        <?php if ($documents === []): ?>
            <p class="text-body-secondary" id="zatca_documents_none"><?= lang('Accounting_zatca.documents_none') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="zatca_documents">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_zatca.number') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.document_kind') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.date') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.total') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.state') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.attempts') ?></th>
                            <th scope="col"><?= lang('Accounting_zatca.answer') ?></th>
                            <th scope="col"><span class="visually-hidden"><?= lang('Accounting_zatca.actions') ?></span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $document): ?>
                            <tr data-document="<?= (int) $document['document_id'] ?>" data-state="<?= esc($document['status'], 'attr') ?>">
                                <td><bdi dir="ltr"><?= esc($document['number']) ?></bdi></td>
                                <td><?= lang('Accounting_zatca.subtype_' . $document['subtype']) ?> / <?= lang('Accounting_zatca.doc_' . $document['kind']) ?></td>
                                <td><?= esc($document['date']) ?></td>
                                <td><bdi dir="ltr"><?= esc($document['total']) ?></bdi></td>
                                <td><?= lang('Accounting_zatca.state_' . $document['status']) ?></td>
                                <td><bdi dir="ltr"><?= (int) $document['attempts'] ?></bdi></td>
                                <td class="small">
                                    <?php foreach ([...$document['errors'], ...$document['warnings']] as $message): ?>
                                        <div dir="auto"><?= esc($message) ?></div>
                                    <?php endforeach; ?>
                                    <?php if ($document['errors'] === [] && $document['warnings'] === [] && $document['error'] !== ''): ?>
                                        <div dir="auto"><?= esc($document['error']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($can_manage && in_array($document['status'], ['pending', 'retry', 'unknown'], true)): ?>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-document-action="<?= esc(site_url('accounting_zatca/retry/' . (int) $document['document_id']), 'attr') ?>"><?= lang('Accounting_zatca.retry') ?></button>
                                    <?php elseif ($can_manage && $document['status'] === 'rejected'): ?>
                                        <button type="button" class="btn btn-outline-danger btn-sm" data-document-action="<?= esc(site_url('accounting_zatca/reissue/' . (int) $document['document_id']), 'attr') ?>"><?= lang('Accounting_zatca.reissue') ?></button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <nav class="d-flex gap-2" aria-label="<?= esc(lang('Accounting_zatca.queue_title'), 'attr') ?>">
                <?php if ($offset > 0): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('accounting_zatca') . '?' . http_build_query(['status' => $status, 'offset' => max(0, $offset - $page)]), 'attr') ?>"><?= lang('Accounting_zatca.previous') ?></a>
                <?php endif; ?>
                <?php if (count($documents) === $page): ?>
                    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(site_url('accounting_zatca') . '?' . http_build_query(['status' => $status, 'offset' => $offset + $page]), 'attr') ?>"><?= lang('Accounting_zatca.next') ?></a>
                <?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
</div>

<?= view('partial/footer') ?>
