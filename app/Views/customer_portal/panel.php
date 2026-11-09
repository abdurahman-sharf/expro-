<?php
/**
 * @var bool        $enabled
 * @var object|null $account
 * @var int         $customerId
 */
?>
<div class="portal-panel" id="portal_panel" data-token-name="<?= esc(csrf_token(), 'attr') ?>" data-token="<?= esc(csrf_hash(), 'attr') ?>">
    <p class="text-secondary"><?= esc(lang('Portal.manage_note')) ?></p>

    <div class="form-check mb-3">
        <input type="checkbox" class="form-check-input" id="portal_enabled" <?= $enabled ? 'checked' : '' ?>>
        <label class="form-check-label" for="portal_enabled"><?= esc(lang('Portal.manage_setting')) ?></label>
        <button type="button" class="btn btn-outline-primary btn-sm ms-2" id="portal_enabled_save" data-url="<?= esc(site_url('customer_portal/enabled'), 'attr') ?>"><?= esc(lang('Portal.save')) ?></button>
    </div>

    <?php if ($account === null) : ?>
        <div class="mb-3" id="portal_create_block">
            <label class="form-label" for="portal_username"><?= esc(lang('Portal.username')) ?></label>
            <input type="text" class="form-control form-control-sm" id="portal_username" maxlength="50" dir="ltr" autocapitalize="none">
            <button type="button" class="btn btn-primary btn-sm mt-2" id="portal_create" data-url="<?= esc(site_url('customer_portal/create/' . $customerId), 'attr') ?>"><?= esc(lang('Portal.manage_create')) ?></button>
        </div>
    <?php else : ?>
        <dl class="row" id="portal_account">
            <dt class="col-sm-4"><?= esc(lang('Portal.username')) ?></dt>
            <dd class="col-sm-8"><bdi dir="ltr"><?= esc($account->username) ?></bdi></dd>
            <dt class="col-sm-4"><?= esc(lang('Portal.status')) ?></dt>
            <dd class="col-sm-8"><?= esc(lang($account->status === 'active' ? 'Portal.status_active' : 'Portal.status_disabled')) ?></dd>
            <dt class="col-sm-4"><?= esc(lang('Portal.manage_last_login')) ?></dt>
            <dd class="col-sm-8"><?= $account->last_login_at === null ? esc(lang('Portal.manage_never')) : '<bdi dir="ltr">' . esc((string) $account->last_login_at) . '</bdi>' ?></dd>
            <?php if ((int) $account->must_change === 1) : ?>
                <dt class="col-sm-4"><?= esc(lang('Portal.status')) ?></dt>
                <dd class="col-sm-8"><?= esc(lang('Portal.manage_must_change')) ?></dd>
            <?php endif; ?>
        </dl>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm" id="portal_reset" data-url="<?= esc(site_url('customer_portal/reset/' . (int) $account->account_id), 'attr') ?>"><?= esc(lang('Portal.manage_reset')) ?></button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="portal_toggle" data-active="<?= $account->status === 'active' ? '0' : '1' ?>" data-url="<?= esc(site_url('customer_portal/status/' . (int) $account->account_id), 'attr') ?>"><?= esc(lang($account->status === 'active' ? 'Portal.manage_disable' : 'Portal.manage_enable')) ?></button>
        </div>
    <?php endif; ?>

    <div class="alert alert-info mt-3 d-none" role="status" id="portal_result">
        <span id="portal_result_message"></span>
        <div class="d-none" id="portal_temporary_block">
            <?= esc(lang('Portal.manage_temporary_password')) ?>:
            <code dir="ltr" id="portal_temporary_password"></code>
            <div class="form-text"><?= esc(lang('Portal.manage_temporary_note')) ?></div>
        </div>
    </div>
</div>
<script type="text/javascript">
    $(document).ready(function() {
        const panel = document.getElementById('portal_panel');
        const result = document.getElementById('portal_result');
        const post = function(url, fields) {
            const body = new FormData();
            body.append(panel.dataset.tokenName, panel.dataset.token);
            Object.keys(fields).forEach(function(name) {
                body.append(name, fields[name]);
            });

            return fetch(url, {method: 'POST', body: body, credentials: 'same-origin', headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(function(response) {
                return response.json();
            }).then(function(answer) {
                result.classList.remove('d-none');
                document.getElementById('portal_result_message').textContent = answer.message || '';
                document.getElementById('portal_temporary_block').classList.toggle('d-none', !answer.temporary_password);
                document.getElementById('portal_temporary_password').textContent = answer.temporary_password || '';
            });
        };
        const bind = function(id, fields) {
            const button = document.getElementById(id);

            if (button !== null) {
                button.addEventListener('click', function() {
                    post(button.dataset.url, fields(button));
                });
            }
        };

        bind('portal_enabled_save', function() {
            return {enabled: document.getElementById('portal_enabled').checked ? '1' : '0'};
        });
        bind('portal_create', function() {
            return {username: document.getElementById('portal_username').value};
        });
        bind('portal_reset', function() {
            return {};
        });
        bind('portal_toggle', function(button) {
            return {active: button.dataset.active};
        });
    });
</script>
