{{--
    WCAG Fase 6 (A11Y-212): after a rejected submit Filament scrolls the first invalid field into view
    (schemas/resources/js/index.js, handleFormValidationError) but leaves keyboard focus on the submit
    button, so a keyboard or screen-reader user is not taken to the error. This listens to the same
    `form-validation-error` event and moves focus to the first invalid field's control — once per rejected
    submit, never on ordinary re-renders. Registered as a panel render hook in AdminPanelProvider.
--}}
<script>
    (() => {
        if (window.__procyniaAdminFocusFirstError) {
            return;
        }

        window.__procyniaAdminFocusFirstError = true;

        const focusableSelector = [
            'input:not([type="hidden"]):not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            'button:not([disabled])',
            '[contenteditable="true"]',
            '[tabindex]:not([tabindex="-1"])',
        ].join(', ');

        window.addEventListener('form-validation-error', () => {
            // Filament expands collapsed sections on the next tick and scrolls 100 ms later; focus after both.
            window.setTimeout(() => {
                const error = document.querySelector('.fi-main [data-validation-error], .fi-modal [data-validation-error]');
                const wrapper = error?.closest('[data-field-wrapper]');
                const control = wrapper?.querySelector(focusableSelector);

                if (control && typeof control.focus === 'function') {
                    control.focus({ preventScroll: true });
                }
            }, 150);
        });
    })();
</script>
