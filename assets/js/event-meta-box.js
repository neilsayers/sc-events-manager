(function () {
    'use strict';

    var leafletMap = null;

    document.addEventListener('DOMContentLoaded', function () {
        initToggles();
        initDateTimesTable();
        initMap();
        initVenueSelect();
    });

    function qs(id) {
        return document.getElementById(id);
    }

    function toggleRow(id, show) {
        var row = qs(id);

        if (row) {
            row.hidden = !show;
        }
    }

    /* ---- Show/hide sections depending on the checkboxes/selects above them ---- */

    function initToggles() {
        var isOneDay = qs('scem_is_one_day');
        var differentTimes = qs('scem_different_times_per_date');
        var frequency = qs('scem_recurrence_frequency');
        var recurrenceSection = qs('scem-recurrence-section');
        var ageRestricted = qs('scem_age_restricted');
        var ageUnder = qs('scem_age_under');

        function syncOneDay() {
            var oneDay = isOneDay.checked;

            toggleRow('scem-end-date-row', !oneDay);
            toggleRow('scem-different-times-row', !oneDay);

            if (recurrenceSection) {
                recurrenceSection.hidden = !oneDay;
            }

            if (oneDay && differentTimes) {
                differentTimes.checked = false;
            }

            syncDifferentTimes();
        }

        function syncDifferentTimes() {
            var show = !isOneDay.checked && !!(differentTimes && differentTimes.checked);
            toggleRow('scem-date-times-row', show);
        }

        function syncFrequency() {
            var value = frequency.value;
            toggleRow('scem-recurrence-weekly-row', value === 'weekly');
            toggleRow('scem-recurrence-monthly-row', value === 'monthly');
            toggleRow('scem-recurrence-until-row', value !== '');
        }

        function syncAgeRestricted() {
            var restricted = ageRestricted.checked;
            toggleRow('scem-age-range-row', restricted);
            syncResponsibleAdult();
        }

        function syncResponsibleAdult() {
            var show = ageRestricted.checked && !!(ageUnder && ageUnder.value !== '');
            toggleRow('scem-responsible-adult-row', show);
        }

        if (isOneDay) {
            isOneDay.addEventListener('change', syncOneDay);
            syncOneDay();
        }

        if (differentTimes) {
            differentTimes.addEventListener('change', syncDifferentTimes);
        }

        if (frequency) {
            frequency.addEventListener('change', syncFrequency);
            syncFrequency();
        }

        if (ageRestricted) {
            ageRestricted.addEventListener('change', syncAgeRestricted);
            syncAgeRestricted();
        }

        if (ageUnder) {
            ageUnder.addEventListener('input', syncResponsibleAdult);
        }
    }

    /* ---- Per-date times table, rebuilt whenever the date range changes ---- */

    function initDateTimesTable() {
        var startInput = qs('scem_start_date');
        var endInput = qs('scem_end_date');
        var table = qs('scem-date-times-table');

        if (!startInput || !endInput || !table) {
            return;
        }

        var cache = readExistingDateTimes();

        function rebuild() {
            cacheCurrentRows(table, cache);
            renderRows(table, startInput.value, endInput.value, cache);
        }

        startInput.addEventListener('change', rebuild);
        endInput.addEventListener('change', rebuild);
    }

    function readExistingDateTimes() {
        var script = qs('scem-existing-date-times');

        if (!script) {
            return {};
        }

        try {
            return JSON.parse(script.textContent) || {};
        } catch (e) {
            return {};
        }
    }

    function cacheCurrentRows(table, cache) {
        table.querySelectorAll('tbody tr').forEach(function (row) {
            var date = row.getAttribute('data-date');
            var selects = row.querySelectorAll('select');

            if (!date || selects.length < 4) {
                return;
            }

            cache[date] = {
                start_hour: selects[0].value,
                start_minute: selects[1].value,
                end_hour: selects[2].value,
                end_minute: selects[3].value
            };
        });
    }

    function renderRows(table, start, end, cache) {
        var tbody = table.querySelector('tbody');
        tbody.innerHTML = '';

        dateRange(start, end).forEach(function (date) {
            var existing = cache[date] || {};
            var row = document.createElement('tr');
            row.setAttribute('data-date', date);

            var dateCell = document.createElement('td');
            dateCell.textContent = date;
            row.appendChild(dateCell);

            row.appendChild(timeCell('date_times][' + date + '][start', existing.start_hour, existing.start_minute));
            row.appendChild(timeCell('date_times][' + date + '][end', existing.end_hour, existing.end_minute));

            tbody.appendChild(row);
        });
    }

    function timeCell(namePrefix, hour, minute) {
        var cell = document.createElement('td');
        cell.appendChild(select('scem[' + namePrefix + '_hour]', hourOptions(), hour));
        cell.appendChild(document.createTextNode(' : '));
        cell.appendChild(select('scem[' + namePrefix + '_minute]', ['00', '15', '30', '45'], minute));
        return cell;
    }

    function hourOptions() {
        var options = [];

        for (var h = 0; h < 24; h++) {
            options.push(h < 10 ? '0' + h : '' + h);
        }

        return options;
    }

    function select(name, values, selectedValue) {
        var el = document.createElement('select');
        el.name = name;

        var blank = document.createElement('option');
        blank.value = '';
        blank.textContent = '--';
        el.appendChild(blank);

        values.forEach(function (value) {
            var option = document.createElement('option');
            option.value = value;
            option.textContent = value;
            option.selected = value === selectedValue;
            el.appendChild(option);
        });

        return el;
    }

    function dateRange(start, end) {
        var dates = [];

        if (!start || !end) {
            return dates;
        }

        var startDate = new Date(start + 'T00:00:00');
        var endDate = new Date(end + 'T00:00:00');

        if (isNaN(startDate.getTime()) || isNaN(endDate.getTime()) || endDate < startDate) {
            return dates;
        }

        var cursor = startDate;
        var maxDays = 366;

        while (cursor <= endDate && dates.length < maxDays) {
            dates.push(formatDate(cursor));
            cursor = new Date(cursor.getTime() + 86400000);
        }

        return dates;
    }

    function formatDate(date) {
        var month = ('0' + (date.getMonth() + 1)).slice(-2);
        var day = ('0' + date.getDate()).slice(-2);
        return date.getFullYear() + '-' + month + '-' + day;
    }

    /* ---- Leaflet map with geocoder search + click-to-set marker ---- */

    function initMap() {
        var container = qs('scem-map');

        if (!container || typeof L === 'undefined') {
            return;
        }

        var latInput = qs('scem_venue_lat');
        var lngInput = qs('scem_venue_lng');

        var lat = parseFloat(container.getAttribute('data-lat'));
        var lng = parseFloat(container.getAttribute('data-lng'));
        var hasLocation = !isNaN(lat) && !isNaN(lng);

        var map = L.map(container).setView(hasLocation ? [lat, lng] : [54.5, -3], hasLocation ? 15 : 6);
        leafletMap = map;

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var marker = hasLocation ? L.marker([lat, lng]).addTo(map) : null;

        function setLocation(latLng) {
            if (marker) {
                marker.setLatLng(latLng);
            } else {
                marker = L.marker(latLng).addTo(map);
            }

            latInput.value = latLng.lat.toFixed(6);
            lngInput.value = latLng.lng.toFixed(6);
        }

        if (L.Control && typeof L.Control.geocoder === 'function') {
            L.Control.geocoder({ defaultMarkGeocode: false })
                .on('markgeocode', function (event) {
                    var center = event.geocode.center;
                    map.setView(center, 16);
                    setLocation(center);
                })
                .addTo(map);
        }

        map.on('click', function (event) {
            setLocation(event.latlng);
        });
    }

    /* ---- Saved venue dropdown: swap the manual fields for a read-only summary ---- */

    function initVenueSelect() {
        var select = qs('scem_venue_select');
        var manualFields = qs('scem-manual-venue-fields');
        var summaryRow = qs('scem-venue-summary-row');
        var summary = qs('scem-venue-summary');
        var dataScript = qs('scem-venues-data');

        if (!select || !manualFields || !summaryRow || !summary) {
            return;
        }

        var venues = {};

        if (dataScript) {
            try {
                venues = JSON.parse(dataScript.textContent) || {};
            } catch (e) {
                venues = {};
            }
        }

        var editUrlBase = select.getAttribute('data-edit-url-base') || '';

        function renderSummary(venue) {
            var type = [];

            if (venue.indoor) {
                type.push('Indoor');
            }

            if (venue.outdoor) {
                type.push('Outdoor');
            }

            var lines = [venue.name];
            var addressParts = [venue.address, venue.town, venue.postcode].filter(Boolean);

            if (addressParts.length) {
                lines.push(addressParts.join(', '));
            }

            if (type.length) {
                lines.push(type.join(' + '));
            }

            if (venue.disabled_access) {
                lines.push('Disabled access');
            }

            summary.innerHTML = '<p>' + lines.map(escapeHtml).join('<br>') + '</p>'
                + '<p><a href="' + escapeHtml(editUrlBase + select.value) + '" target="_blank" rel="noopener">Edit this venue</a></p>';
        }

        function sync() {
            var venue = venues[select.value];
            var show = !!venue;

            manualFields.hidden = show;
            summaryRow.hidden = !show;

            if (show) {
                renderSummary(venue);
            } else {
                setTimeout(function () {
                    if (leafletMap) {
                        leafletMap.invalidateSize();
                    }
                }, 0);
            }
        }

        select.addEventListener('change', sync);
        sync();
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    }
})();
