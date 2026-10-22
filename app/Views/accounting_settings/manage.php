<?php
/**
 * @var bool $enabled
 * @var list<string> $issues
 * @var string $currency
 * @var string $start_date
 * @var bool $auto_year
 * @var bool $locked
 * @var int $next_number
 * @var bool $can_manage
 * @var array{name: string, start_date: string, end_date: string}|null $first_year
 * @var bool $can_first_year
 * @var list<array{name: string, username: string, permissions: list<string>, all_branches: bool, branches: list<string>}> $holders
 */

$messages = [
    'enable'  => lang('Accounting_settings.confirm_enable'),
    'disable' => lang('Accounting_settings.confirm_disable'),
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        const confirmations = <?= json_encode($messages, $jsonFlags) ?>;

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

        $('#settings_form').on('submit', function(event) {
            event.preventDefault();
            send('accounting_settings/save', $(this).serialize(), true);
        });

        $('#first_year_form').on('submit', function(event) {
            event.preventDefault();
            send('accounting_settings/first_year', $(this).serialize(), true);
        });

        $('[data-settings-action]').on('click', function() {
            const action = $(this).data('settings-action');

            if (confirm(confirmations[action])) {
                send('accounting_settings/' + action, {}, true);
            }
        });
    });
</script>

<div class="acc-setup">
    <section class="acc-setup-card" aria-labelledby="status_title">
        <h2 id="status_title" class="acc-setup-title"><?= lang('Accounting_settings.status_title') ?></h2>
        <p class="mb-2">
            <span class="badge <?= $enabled ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= lang($enabled ? 'Accounting_settings.state_enabled' : 'Accounting_settings.state_disabled') ?></span>
        </p>
        <p class="text-body-secondary small"><?= lang($enabled ? 'Accounting_settings.enabled_desc' : 'Accounting_settings.disabled_desc') ?></p>

        <?php if ($issues === []): ?>
            <p class="acc-ready"><span class="bi bi-check-circle-fill text-success" aria-hidden="true"></span> <?= lang('Accounting_settings.ready') ?></p>
        <?php else: ?>
            <p class="mb-1"><?= lang('Accounting_settings.readiness_title') ?></p>
            <ul class="acc-readiness">
                <?php foreach ($issues as $issue): ?>
                    <li><span class="bi bi-x-circle-fill text-danger" aria-hidden="true"></span> <?= esc($issue) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($can_manage): ?>
            <?php if ($enabled): ?>
                <button type="button" class="btn btn-outline-danger btn-sm" data-settings-action="disable"><?= lang('Accounting_settings.disable') ?></button>
            <?php else: ?>
                <button type="button" class="btn btn-primary btn-sm" data-settings-action="enable"<?= $issues === [] ? '' : ' disabled' ?>><?= lang('Accounting_settings.enable') ?></button>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="acc-setup-card" aria-labelledby="general_title">
        <h2 id="general_title" class="acc-setup-title"><?= lang('Accounting_settings.general_title') ?></h2>

        <form id="settings_form" class="app-form" novalidate>
            <div class="form-grid">
                <div class="field">
                    <label class="form-label" for="base_currency"><?= lang('Accounting_settings.base_currency') ?></label>
                    <div class="field-control">
                        <input type="text" name="base_currency" id="base_currency" class="form-control form-control-sm ltr-input" dir="ltr" maxlength="3" value="<?= esc($currency, 'attr') ?>"<?= $locked || ! $can_manage ? ' disabled' : '' ?>>
                    </div>
                </div>

                <div class="field">
                    <label class="form-label" for="start_date"><?= lang('Accounting_settings.start_date') ?></label>
                    <div class="field-control">
                        <input type="date" name="start_date" id="start_date" class="form-control form-control-sm" value="<?= esc($start_date, 'attr') ?>"<?= $locked || ! $can_manage ? ' disabled' : '' ?>>
                    </div>
                </div>

                <?php if ($locked): ?>
                    <p class="form-text span-all"><span class="bi bi-lock" aria-hidden="true"></span> <?= lang('Accounting_settings.locked_note') ?></p>
                <?php endif; ?>

                <div class="field span-all">
                    <div class="form-check form-switch">
                        <input type="hidden" name="auto_fiscal_year" value="0">
                        <input type="checkbox" class="form-check-input" role="switch" name="auto_fiscal_year" id="auto_fiscal_year" value="1"<?= $auto_year ? ' checked' : '' ?><?= $can_manage ? '' : ' disabled' ?>>
                        <label class="form-check-label" for="auto_fiscal_year"><?= lang('Accounting_settings.auto_year') ?></label>
                    </div>
                    <p class="form-text"><?= lang('Accounting_settings.auto_year_desc') ?></p>
                </div>

                <div class="field">
                    <span class="form-label"><?= lang('Accounting_settings.next_number') ?></span>
                    <div class="field-control"><span class="form-control-plaintext"><bdi dir="ltr"><?= sprintf('%06d', $next_number) ?></bdi></span></div>
                </div>
            </div>

            <?php if ($can_manage): ?>
                <button type="submit" class="btn btn-primary btn-sm"><?= lang('Common.submit') ?></button>
            <?php else: ?>
                <p class="form-text mb-0" role="note"><span class="bi bi-shield-lock" aria-hidden="true"></span> <?= lang('Accounting_periods.permission_note') ?></p>
            <?php endif; ?>
        </form>
    </section>

    <section class="acc-setup-card" aria-labelledby="holders_title">
        <h2 id="holders_title" class="acc-setup-title"><?= lang('Accounting_settings.holders_title') ?></h2>
        <p class="text-body-secondary small"><?= lang('Accounting_settings.holders_desc') ?></p>

        <?php if ($holders === []): ?>
            <p class="mb-0"><?= lang('Accounting_settings.holders_none') ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="sensitive_holders">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_settings.holders_employee') ?></th>
                            <th scope="col"><?= lang('Accounting_settings.holders_permissions') ?></th>
                            <th scope="col"><?= lang('Accounting_settings.holders_branches') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($holders as $holder): ?>
                            <tr>
                                <td data-label="<?= esc(lang('Accounting_settings.holders_employee'), 'attr') ?>"><?= esc($holder['name']) ?> <bdi dir="ltr" class="text-body-secondary">(<?= esc($holder['username']) ?>)</bdi></td>
                                <td data-label="<?= esc(lang('Accounting_settings.holders_permissions'), 'attr') ?>">
                                    <ul class="list-unstyled mb-0">
                                        <?php foreach ($holder['permissions'] as $label): ?>
                                            <li><?= esc($label) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </td>
                                <td data-label="<?= esc(lang('Accounting_settings.holders_branches'), 'attr') ?>"><?= $holder['all_branches'] ? esc(lang('Accounting_settings.holders_all_branches')) : '<bdi dir="ltr">' . esc(implode(', ', $holder['branches'])) . '</bdi>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($first_year !== null): ?>
        <section class="acc-setup-card" aria-labelledby="year_title">
            <h2 id="year_title" class="acc-setup-title"><?= lang('Accounting_settings.first_year_title') ?></h2>
            <p class="text-body-secondary small"><?= lang('Accounting_settings.first_year_desc') ?></p>

            <form id="first_year_form" class="app-form" novalidate>
                <div class="form-grid">
                    <div class="field">
                        <label class="form-label" for="year_name"><?= lang('Accounting_periods.year_name') ?></label>
                        <div class="field-control"><input type="text" name="name" id="year_name" class="form-control form-control-sm" maxlength="100" value="<?= esc($first_year['name'], 'attr') ?>"<?= $can_first_year ? '' : ' disabled' ?>></div>
                    </div>
                    <div class="field">
                        <label class="form-label" for="year_start"><?= lang('Accounting_periods.start_date') ?></label>
                        <div class="field-control"><input type="date" name="start_date" id="year_start" class="form-control form-control-sm" value="<?= esc($first_year['start_date'], 'attr') ?>"<?= $can_first_year ? '' : ' disabled' ?>></div>
                    </div>
                    <div class="field">
                        <label class="form-label" for="year_end"><?= lang('Accounting_periods.end_date') ?></label>
                        <div class="field-control"><input type="date" name="end_date" id="year_end" class="form-control form-control-sm" value="<?= esc($first_year['end_date'], 'attr') ?>"<?= $can_first_year ? '' : ' disabled' ?>></div>
                    </div>
                </div>

                <?php if ($can_first_year): ?>
                    <button type="submit" class="btn btn-outline-primary btn-sm"><?= lang('Accounting_settings.create_year') ?></button>
                <?php endif; ?>
            </form>
        </section>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
