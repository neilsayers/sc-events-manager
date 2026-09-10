(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var tbody = document.getElementById('scem_price_rows');
        var addButton = document.getElementById('scem_add_price_row');
        var template = document.getElementById('scem_price_row_template');

        if (!tbody || !addButton || !template) {
            return;
        }

        addButton.addEventListener('click', function () {
            tbody.insertAdjacentHTML('beforeend', template.innerHTML.replace(/__INDEX__/g, String(nextIndex(tbody))));
            syncEmptyMessage(tbody);

            var added = tbody.lastElementChild;

            if (added) {
                var type = added.querySelector('.scem-price-type');

                if (type) {
                    type.focus();
                }
            }
        });

        // Delegated so rows added after load behave like rendered ones.
        tbody.addEventListener('click', function (event) {
            var remove = event.target.closest('.scem-price-remove');

            if (!remove) {
                return;
            }

            var row = remove.closest('.scem-price-row');

            if (row) {
                row.remove();
                syncEmptyMessage(tbody);
            }
        });

        tbody.addEventListener('change', function (event) {
            if (event.target.classList.contains('scem-price-free')) {
                syncFreeRow(event.target);
            }
        });

        Array.prototype.forEach.call(tbody.querySelectorAll('.scem-price-free'), syncFreeRow);
    });

    /**
     * Row names are indexed rather than appended with [] so PHP sees
     * a stable key per row. Removing row 1 of 3 would otherwise leave
     * a gap the next Add reuses, silently merging two rows' fields.
     */
    function nextIndex(tbody) {
        var highest = -1;

        Array.prototype.forEach.call(tbody.querySelectorAll('.scem-price-type'), function (select) {
            var match = /\[ticket_prices\]\[(\d+)\]/.exec(select.name || '');

            if (match) {
                highest = Math.max(highest, parseInt(match[1], 10));
            }
        });

        return highest + 1;
    }

    /** A free row has no amount to enter, so the field is disabled and cleared. */
    function syncFreeRow(checkbox) {
        var row = checkbox.closest('.scem-price-row');

        if (!row) {
            return;
        }

        var amount = row.querySelector('.scem-price-amount');

        if (!amount) {
            return;
        }

        amount.disabled = checkbox.checked;

        if (checkbox.checked) {
            amount.value = '';
        }
    }

    function syncEmptyMessage(tbody) {
        var message = document.getElementById('scem_price_empty');

        if (message) {
            message.hidden = tbody.querySelectorAll('.scem-price-row').length > 0;
        }
    }
})();
