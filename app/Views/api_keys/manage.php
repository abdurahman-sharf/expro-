<?php

use App\Libraries\Integrations\ApiKey;
use App\Libraries\Integrations\ApiScope;

/**
 * @var list<ApiKey>       $keys
 * @var list<ApiScope>     $scopes
 * @var array<int, string> $branches
 * @var bool               $enabled
 * @var string             $now
 */
$branchChoices = ['' => lang('Integrations.key_every_branch')] + $branches;
?>

<?= view('partial/header') ?>

<script type="text/javascript">
    $(document).ready(function() {
        $('#key_form').on('submit', function(event) {
            event.preventDefault();
            $.ajax({
                type: 'POST',
                url: $(this).attr('action'),
                data: $(this).serialize(),
                dataType: 'json',
                success: function(response) {
                    toast_support.show(response.message, response.success ? 'success' : 'danger');
                    if (response.success) {
                        $('#key_secret_value').text(response.secret);
                        $('#key_secret_box').prop('hidden', false);
                        $('#key_form').trigger('reset');
                    }
                }
            });
        });

        $('#key_done').on('click', function() {
            window.location.reload();
        });

        $('.key-revoke').on('click', function() {
            if (!window.confirm($(this).data('confirm'))) {
                return;
            }

            $.post($(this).data('url'), function(response) {
                toast_support.show(response.message, response.success ? 'success' : 'danger');
                if (response.success) {
                    window.location.reload();
                }
            }, 'json');
        });
    });
</script>

<div id="title_bar" class="print_hide btn-toolbar">
    <h1 class="h5 me-auto mb-0"><?= lang('Integrations.api_keys') ?></h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?= esc(base_url('integrations'), 'attr') ?>"><?= lang('Integrations.back') ?></a>
</div>

<p class="text-body-secondary"><?= lang('Integrations.keys_help') ?></p>
<p class="small text-body-secondary"><?= lang('Integrations.keys_endpoints') ?></p>

<?php if (! $enabled): ?>
    <div class="alert alert-warning" id="api_off" role="status"><?= lang('Integrations.api_off') ?></div>
<?php endif; ?>

<div class="alert alert-success" id="key_secret_box" role="alert" hidden>
    <strong><?= lang('Integrations.key_secret_once') ?></strong>
    <div><code id="key_secret_value" dir="ltr" class="user-select-all"></code></div>
    <button type="button" class="btn btn-outline-secondary btn-sm mt-2" id="key_done"><?= lang('Integrations.key_done') ?></button>
</div>

<?= form_open('api_keys/create', ['id' => 'key_form', 'class' => 'app-form card mb-3']) ?>
    <div class="card-body">
        <h2 class="h6"><?= lang('Integrations.key_new') ?></h2>
        <div class="form-grid">
            <div class="field">
                <label class="form-label" for="key_name"><?= lang('Integrations.key_name') ?></label>
                <div class="field-control"><input type="text" class="form-control form-control-sm" id="key_name" name="name" maxlength="100"></div>
            </div>
            <div class="field">
                <label class="form-label"><?= lang('Integrations.key_scopes') ?></label>
                <div class="field-control">
                    <?php foreach ($scopes as $scope): ?>
                        <label class="choice"><input type="checkbox" class="form-check-input" name="scopes[]" value="<?= esc($scope->value, 'attr') ?>"> <?= esc(lang($scope->languageKey())) ?> <code><?= esc($scope->value) ?></code></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="key_branch"><?= lang('Integrations.key_branch') ?></label>
                <div class="field-control">
                    <?= form_dropdown('branch_id', $branchChoices, '', ['class' => 'form-select form-select-sm', 'id' => 'key_branch']) ?>
                    <p class="form-text mb-0"><?= lang('Integrations.key_branch_help') ?></p>
                </div>
            </div>
            <div class="field">
                <label class="form-label" for="key_rate"><?= lang('Integrations.key_rate') ?></label>
                <div class="field-control"><input type="number" class="form-control form-control-sm" id="key_rate" name="rate_limit" min="1" max="6000" value="60"></div>
            </div>
            <div class="field">
                <label class="form-label" for="key_expires"><?= lang('Integrations.key_expires') ?></label>
                <div class="field-control"><input type="date" class="form-control form-control-sm" id="key_expires" name="expires_on" min="<?= esc(substr($now, 0, 10), 'attr') ?>"></div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary btn-sm" id="key_submit"><?= lang('Integrations.key_create') ?></button>
    </div>
<?= form_close() ?>

<?php if ($keys === []): ?>
    <p class="text-body-secondary" id="keys_empty"><?= lang('Integrations.no_keys') ?></p>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle" id="keys_table">
            <thead>
                <tr>
                    <th><?= lang('Integrations.key_name') ?></th>
                    <th><?= lang('Integrations.key_prefix') ?></th>
                    <th><?= lang('Integrations.key_scopes') ?></th>
                    <th><?= lang('Integrations.key_branch') ?></th>
                    <th><?= lang('Integrations.key_rate') ?></th>
                    <th><?= lang('Integrations.key_expires') ?></th>
                    <th><?= lang('Integrations.key_last_used') ?></th>
                    <th><?= lang('Integrations.key_status') ?></th>
                    <th><span class="visually-hidden"><?= lang('Integrations.actions') ?></span></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keys as $key): ?>
                    <?php $status = $key->revoked ? 'revoked' : ($key->isExpired($now) ? 'expired' : 'active'); ?>
                    <tr data-key="<?= $key->id ?>">
                        <td><?= esc($key->name) ?></td>
                        <td><code dir="ltr"><?= esc($key->prefix) ?></code></td>
                        <td class="small"><?= esc(implode(', ', array_map(static fn (ApiScope $scope): string => $scope->value, $key->scopes))) ?></td>
                        <td><?= $key->branchId === null ? esc(lang('Integrations.key_every_branch')) : esc($branches[$key->branchId] ?? (string) $key->branchId) ?></td>
                        <td><?= $key->rateLimit ?></td>
                        <td><?= $key->expiresAt === null ? '-' : esc(substr($key->expiresAt, 0, 10)) ?></td>
                        <td><?= $key->lastUsedAt === null ? '-' : esc($key->lastUsedAt) ?></td>
                        <td><span class="badge <?= $status === 'active' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= lang('Integrations.key_status_' . $status) ?></span></td>
                        <td>
                            <?php if (! $key->revoked): ?>
                                <button type="button" class="btn btn-outline-danger btn-sm key-revoke" data-url="<?= esc(base_url('api_keys/revoke/' . $key->id), 'attr') ?>" data-confirm="<?= esc(lang('Integrations.key_revoke_confirm'), 'attr') ?>"><?= lang('Integrations.key_revoke') ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?= view('partial/footer') ?>
