<?php
/**
 * @var bool                                $enabled
 * @var bool                                $canManage
 * @var bool                                $canSwitch
 * @var array<string, string>               $thresholds
 * @var array<string, array<string, mixed>> $defaults
 * @var string|null                         $notice
 */
?>

<?= view('partial/header') ?>
<?= view('accounting_journals/notice', ['notice' => $notice]) ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#ai_settings_form').on('submit', function(event) {
            event.preventDefault();
            $.post(<?= json_encode(base_url('accounting_ai/settings')) ?>, $(this).serialize(), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.href = response.redirect;
                }
            }, 'json');
        });

        $('#ai_disable').on('click', function() {
            $.post(<?= json_encode(base_url('accounting_ai/disable')) ?>, function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.href = response.redirect;
                }
            }, 'json');
        });
    });
</script>

<?= view('accounting_ai/tabs', ['active' => 'settings']) ?>

<div class="container-fluid py-3" id="ai_settings">
    <h1 class="h5"><?= lang('Accounting_ai.settings_title') ?></h1>

    <p class="alert <?= $enabled ? 'alert-success' : 'alert-warning' ?>" id="ai_state" role="status"><?= $enabled ? lang('Accounting_ai.state_on') : lang('Accounting_ai.state_off') ?></p>

    <p class="text-body-secondary small"><?= lang('Accounting_ai.kill_switch_note') ?></p>

    <?php if ($enabled && $canSwitch): ?>
        <p><button type="button" class="btn btn-danger btn-sm" id="ai_disable"><span class="bi bi-power" aria-hidden="true">&nbsp;</span><?= lang('Accounting_ai.switch_off') ?></button></p>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <?= form_open('accounting_ai/settings', ['id' => 'ai_settings_form', 'class' => 'app-form']) ?>
            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" role="switch" name="enabled" id="enabled" value="1"<?= $enabled ? ' checked' : '' ?>>
                <label class="form-check-label" for="enabled"><?= lang('Accounting_ai.enabled') ?></label>
            </div>

            <h2 class="h6"><?= lang('Accounting_ai.thresholds') ?></h2>
            <div class="table-responsive">
                <table class="table table-sm align-middle" id="ai_thresholds">
                    <thead>
                        <tr>
                            <th scope="col"><?= lang('Accounting_ai.threshold') ?></th>
                            <th scope="col"><?= lang('Accounting_ai.value') ?></th>
                            <th scope="col"><?= lang('Accounting_ai.default_value') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($thresholds as $name => $value): ?>
                            <tr>
                                <td><label for="t_<?= esc($name, 'attr') ?>"><?= lang('Accounting_ai.threshold_' . $name) ?></label></td>
                                <td><input type="text" class="form-control form-control-sm ltr-input" dir="ltr" id="t_<?= esc($name, 'attr') ?>" name="threshold[<?= esc($name, 'attr') ?>]" value="<?= esc($value, 'attr') ?>"></td>
                                <td><bdi dir="ltr"><?= esc((string) $defaults[$name]['default']) ?></bdi></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary btn-sm"><?= lang('Accounting_ai.save') ?></button>
        <?= form_close() ?>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm" id="ai_thresholds_readonly">
                <thead>
                    <tr>
                        <th scope="col"><?= lang('Accounting_ai.threshold') ?></th>
                        <th scope="col"><?= lang('Accounting_ai.value') ?></th>
                        <th scope="col"><?= lang('Accounting_ai.default_value') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($thresholds as $name => $value): ?>
                        <tr>
                            <td><?= lang('Accounting_ai.threshold_' . $name) ?></td>
                            <td><bdi dir="ltr"><?= esc($value) ?></bdi></td>
                            <td><bdi dir="ltr"><?= esc((string) $defaults[$name]['default']) ?></bdi></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?= view('partial/footer') ?>
