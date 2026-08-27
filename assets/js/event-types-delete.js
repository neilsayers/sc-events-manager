(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var inputs = document.querySelectorAll('.scem-delete-confirm-input');

        inputs.forEach(function (input) {
            var dialog = input.closest('dialog');
            var submit = dialog ? dialog.querySelector('.scem-delete-confirm-submit') : null;

            if (!submit) {
                return;
            }

            input.addEventListener('input', function () {
                submit.disabled = input.value !== input.dataset.confirm;
            });

            // A closed/reopened <dialog> keeps its previous value (and
            // the submit button's now-stale enabled state) unless this
            // resets both — otherwise cancelling and reopening leaves
            // the button enabled from a match that's no longer visible.
            dialog.addEventListener('close', function () {
                input.value = '';
                submit.disabled = true;
            });
        });
    }
})();
