(function () {
    'use strict';

    var WEEKDAY_LABELS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

    document.addEventListener('DOMContentLoaded', init);

    function init() {
        var root = document.getElementById('scem-calendar-root');
        var dataScript = document.getElementById('scem-calendar-initial-data');

        if (!root || !dataScript) {
            return;
        }

        var state = {};

        try {
            var initialData = JSON.parse(dataScript.textContent);
            state.year = initialData.year;
            state.month = initialData.month;
            renderGrid(root, initialData);
        } catch (e) {
            return;
        }

        root.addEventListener('click', function (event) {
            var prevBtn = event.target.closest('.scem-cal-prev');
            var nextBtn = event.target.closest('.scem-cal-next');
            var occurrenceBtn = event.target.closest('.scem-cal-occurrence');
            var closeBtn = event.target.closest('.scem-cal-modal-close');
            var backdrop = event.target.closest('.scem-cal-modal-backdrop');

            if (prevBtn && !prevBtn.disabled) {
                navigate(root, state, -1);
            } else if (nextBtn && !nextBtn.disabled) {
                navigate(root, state, 1);
            } else if (occurrenceBtn) {
                openModal(root, occurrenceBtn);
            } else if (closeBtn || backdrop) {
                closeModal(root);
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeModal(root);
            }
        });
    }

    function navigate(root, state, direction) {
        var month = state.month + direction;
        var year = state.year;

        if (month < 1) {
            month = 12;
            year -= 1;
        } else if (month > 12) {
            month = 1;
            year += 1;
        }

        var params = new URLSearchParams({
            action: window.scemCalendar.action,
            nonce: window.scemCalendar.nonce,
            year: year,
            month: month
        });

        root.classList.add('scem-cal-loading');

        fetch(window.scemCalendar.ajaxUrl + '?' + params.toString())
            .then(function (response) {
                return response.json();
            })
            .then(function (json) {
                if (!json.success) {
                    return;
                }

                state.year = json.data.year;
                state.month = json.data.month;
                renderGrid(root, json.data);
            })
            .catch(function () {
                root.classList.remove('scem-cal-loading');
            });
    }

    function renderGrid(root, data) {
        root.classList.remove('scem-cal-loading');
        root.innerHTML = gridHtml(data) + modalShellHtml();
    }

    function gridHtml(data) {
        var html = '<div class="scem-cal-header">';
        html += '<button type="button" class="button scem-cal-prev"' + (data.canGoPrev ? '' : ' disabled') + '>&larr; Prev</button>';
        html += '<h2>' + escapeHtml(data.label) + '</h2>';
        html += '<button type="button" class="button scem-cal-next"' + (data.canGoNext ? '' : ' disabled') + '>Next &rarr;</button>';
        html += '</div>';

        html += '<table class="scem-cal-grid"><thead><tr>';
        WEEKDAY_LABELS.forEach(function (label) {
            html += '<th>' + label + '</th>';
        });
        html += '</tr></thead><tbody>';

        data.weeks.forEach(function (week) {
            html += '<tr>';
            week.forEach(function (day) {
                html += dayCellHtml(day);
            });
            html += '</tr>';
        });

        html += '</tbody></table>';

        return html;
    }

    function dayCellHtml(day) {
        var classes = ['scem-cal-day'];

        if (!day.inMonth) {
            classes.push('scem-cal-day--outside');
        }

        if (day.isToday) {
            classes.push('scem-cal-day--today');
        }

        var html = '<td class="' + classes.join(' ') + '">';
        html += '<div class="scem-cal-day-number">' + day.day + '</div>';

        day.occurrences.forEach(function (occurrence) {
            html += occurrenceChipHtml(occurrence);
        });

        html += '</td>';

        return html;
    }

    function occurrenceChipHtml(occurrence) {
        var label = occurrence.start_time ? occurrence.start_time + ' ' + occurrence.title : occurrence.title;

        return '<button type="button" class="scem-cal-occurrence scem-cal-occurrence--' + escapeHtml(occurrence.status) + '" data-occurrence="' + escapeAttr(JSON.stringify(occurrence)) + '">'
            + escapeHtml(label)
            + '</button>';
    }

    function modalShellHtml() {
        return '<div class="scem-cal-modal-backdrop" hidden></div><div class="scem-cal-modal" role="dialog" aria-modal="true" hidden></div>';
    }

    function openModal(root, button) {
        var occurrence;

        try {
            occurrence = JSON.parse(button.getAttribute('data-occurrence'));
        } catch (e) {
            return;
        }

        var backdrop = root.querySelector('.scem-cal-modal-backdrop');
        var modal = root.querySelector('.scem-cal-modal');

        if (!backdrop || !modal) {
            return;
        }

        modal.innerHTML = modalContentHtml(occurrence);
        backdrop.hidden = false;
        modal.hidden = false;
    }

    function closeModal(root) {
        var backdrop = root.querySelector('.scem-cal-modal-backdrop');
        var modal = root.querySelector('.scem-cal-modal');

        if (backdrop) {
            backdrop.hidden = true;
        }

        if (modal) {
            modal.hidden = true;
        }
    }

    function modalContentHtml(occurrence) {
        var when = formatDate(occurrence.date);

        if (occurrence.start_time) {
            when += ' at ' + occurrence.start_time;

            if (occurrence.end_time) {
                when += '–' + occurrence.end_time;
            }
        }

        var html = '<div class="scem-cal-modal-header">';
        html += '<h2>' + escapeHtml(occurrence.title) + '</h2>';
        html += '<button type="button" class="scem-cal-modal-close" aria-label="Close">&times;</button>';
        html += '</div>';

        html += '<div class="scem-cal-modal-body">';
        html += '<p><strong>' + escapeHtml(occurrence.type_label) + '</strong> &middot; ' + escapeHtml(when) + '</p>';

        if (occurrence.venue_name) {
            html += '<p>' + escapeHtml(occurrence.venue_name) + '</p>';
        }

        if (occurrence.price) {
            html += '<p>' + escapeHtml(occurrence.price) + '</p>';
        }

        html += '<p class="scem-cal-modal-status scem-cal-occurrence--' + escapeHtml(occurrence.status) + '">' + escapeHtml(occurrence.status_label) + '</p>';
        html += '</div>';

        html += '<div class="scem-cal-modal-footer">';

        if (occurrence.ticket_url) {
            html += '<a class="button button-primary" href="' + escapeAttr(occurrence.ticket_url) + '" target="_blank" rel="noopener">Tickets</a> ';
        }

        html += '<a class="button" href="' + escapeAttr(occurrence.edit_url) + '">Edit event</a> ';
        html += '<a class="button" href="' + escapeAttr(occurrence.view_url) + '" target="_blank" rel="noopener">View on site</a>';
        html += '</div>';

        return html;
    }

    function formatDate(dateStr) {
        var date = new Date(dateStr + 'T00:00:00');

        if (isNaN(date.getTime())) {
            return dateStr;
        }

        return date.toLocaleDateString(undefined, { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/"/g, '&quot;');
    }
})();
