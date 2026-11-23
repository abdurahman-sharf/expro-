(function($) {

    $(function() {
        const state = JSON.parse(document.getElementById('login-state').textContent);
        const t = state.i18n;
        const $form = $('#login-form');
        const $heading = $('#form-heading');
        const $fields = $('#login-fields');
        const $inputs = $fields.find('input');
        const $username = $('#input-username');
        const $password = $('#input-password');
        const $usernameError = $('#username-error');
        const $button = $('#submit-button');
        const $label = $button.find('.login-submit-label');
        const $spinner = $button.find('.login-spin');
        const $progress = $('#migration-progress');
        const $success = $('#migration-success');
        const $error = $('#migration-error');

        const busy = function(on, label) {
            $button.prop('disabled', on).attr('aria-busy', on ? 'true' : 'false');
            $spinner.prop('hidden', !on);
            $label.text(label);
            $inputs.prop('readOnly', on);
        };

        const showRequired = function(on) {
            $usernameError.prop('hidden', !on);
            $username.attr('aria-invalid', on ? 'true' : null).closest('.login-field').toggleClass('has-error', on);
        };

        const clearAlerts = function() {
            $('#migration-warning, #migration-refusal, #login-throttled, .login-error').remove();
            $success.prop('hidden', true);
            $error.prop('hidden', true);
        };

        if (state.invalid) {
            $password.val('').trigger('focus');
        } else if (state.mode !== 'init') {
            $username.trigger('focus');
        }

        $username.on('input', function() {
            if (this.value.trim() !== '') {
                showRequired(false);
            }
        });

        const migrate = function() {
            const init = state.mode === 'init';

            clearAlerts();
            $('#migration-progress-title').text(init ? t.migrationInitializing : t.migratingDatabase);
            $progress.prop('hidden', false);
            $fields.prop('hidden', true);
            busy(true, init ? t.initializing : t.upgrading);

            const failed = function(message) {
                $progress.prop('hidden', true);
                $('#migration-error-message').text(message);
                $error.prop('hidden', false);
                $fields.prop('hidden', init);
                busy(false, init ? t.initialize : t.migrate);
            };

            $.ajax({
                url: state.migrateUrl,
                type: 'POST',
                dataType: 'json',
                timeout: 3600000,
                data: {
                    [state.csrfToken]: state.csrfHash,
                    username: $username.val(),
                    password: $password.val()
                }
            }).done(function(response) {
                if (!response.success) {
                    failed(response.message || t.migrationFailed);
                    return;
                }

                if (init) {
                    window.location.reload();
                    return;
                }

                state.mode = 'login';
                $progress.prop('hidden', true);
                $success.find('p').text(t.migrationCompleteMigrate);
                $success.prop('hidden', false);
                $heading.text(t.welcome);
                $fields.prop('hidden', false);
                $password.val('');
                busy(false, t.go);
                $password.trigger('focus');
            }).fail(function(xhr) {
                failed(xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : t.migrationErrorConnection);
            });
        };

        $form.on('submit', function(event) {
            if (state.mode !== 'init' && $username.val().trim() === '') {
                event.preventDefault();
                showRequired(true);
                $username.trigger('focus');

                return;
            }

            if (state.mode !== 'login') {
                event.preventDefault();
                migrate();

                return;
            }

            busy(true, t.checking);
        });

        window.addEventListener('pageshow', function(event) {
            if (event.persisted && state.mode === 'login') {
                busy(false, t.go);
            }
        });
    });

})(jQuery);
