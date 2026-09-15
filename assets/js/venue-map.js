/**
 * A plain read-only pin for scem_render_venue_map()'s container —
 * this plugin's own bundled Leaflet (assets/vendor/leaflet), the same
 * one behind the admin's venue picker (event-meta-box.js), so a
 * single-event page's venue map never depends on another plugin (e.g.
 * SC Maps) being active. No marker styling/hover behaviour to match —
 * just OpenStreetMap tiles and Leaflet's own default pin.
 */
(function () {
    'use strict';

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);

        return div.innerHTML;
    }

    function initMap(container) {
        var config;

        try {
            config = JSON.parse(container.getAttribute('data-config'));
        } catch (error) {
            return;
        }

        var map = L.map(container, { scrollWheelZoom: false });

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
        }).addTo(map);

        var marker = L.marker([config.lat, config.lng]).addTo(map);

        if (config.name || config.address) {
            var popupHtml = config.name ? '<strong>' + escapeHtml(config.name) + '</strong>' : '';

            if (config.address) {
                popupHtml += (popupHtml ? '<br>' : '') + escapeHtml(config.address);
            }

            marker.bindPopup(popupHtml).openPopup();
        }

        map.setView([config.lat, config.lng], 15);
    }

    function init() {
        document.querySelectorAll('.scem-venue-map').forEach(initMap);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
