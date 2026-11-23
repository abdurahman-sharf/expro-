(function() {

    const form = document.getElementById('owner-form');

    if (!form) {
        return;
    }

    const focusTarget = document.getElementById(form.dataset.focus);

    if (focusTarget) {
        focusTarget.focus();
    }

    form.addEventListener('input', function(event) {
        const field = event.target.closest('.login-field');

        if (!field) {
            return;
        }

        field.classList.remove('has-error');
        event.target.removeAttribute('aria-invalid');

        const icon = field.querySelector('.claim-error-icon');
        const badge = field.querySelector('.claim-cleared');

        if (icon) {
            icon.remove();
        }

        if (badge && event.target.value !== '') {
            badge.remove();
        }
    });

    form.addEventListener('submit', function(event) {
        const button = document.getElementById('owner-submit');

        if (button.disabled) {
            event.preventDefault();

            return;
        }

        form.querySelectorAll('input:not([type=hidden])').forEach(function(input) {
            input.readOnly = true;
        });

        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.querySelector('.login-spin').hidden = false;
        button.querySelector('.login-submit-label').textContent = form.dataset.busy;
    });

    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            window.location.reload();
        }
    });

})();