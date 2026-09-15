<?php

namespace SCEventsManager\Frontend;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;

/**
 * Gets this plugin's own bundled Leaflet onto a singular event page —
 * the same vendored copy (assets/vendor/leaflet) the admin's own
 * venue picker already uses (Support\LocationFields::enqueueMapAssets()),
 * just the plain read-only pin instead of the geocoder/search box.
 * scem_render_venue_map() calls this plugin's own map, not another
 * plugin's (e.g. SC Maps') — deactivating that plugin should never
 * take this one's own venue map down with it.
 */
final class VenueMapAssets implements Hookable
{
    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('wp_enqueue_scripts', [$this, 'maybeEnqueueStyles']);
    }

    /**
     * Styles only, and only on a page that could actually render one
     * of this plugin's own event types — wp_head() (and so
     * wp_print_styles()) fires before the template renders, so a
     * style only enqueued once scem_render_venue_map() is known to
     * have been called would already have missed its one chance to
     * print. The script half doesn't have this problem: it's
     * registered here (not enqueued) so scem_render_venue_map() can
     * enqueue it for real once it knows a map is actually being
     * rendered — in_footer, so that's still early enough.
     */
    public function maybeEnqueueStyles(): void
    {
        $eventTypes = \array_keys($this->settings->allEventTypes());

        if ($eventTypes === [] || ! \is_singular($eventTypes)) {
            return;
        }

        \wp_enqueue_style('scem-leaflet', SCEM_URL.'assets/vendor/leaflet/leaflet.css', [], '1.9.4');
        \wp_enqueue_style('scem-frontend', SCEM_URL.'assets/css/frontend.css', [], SCEM_VERSION);

        \wp_register_script('scem-leaflet', SCEM_URL.'assets/vendor/leaflet/leaflet.js', [], '1.9.4', true);
        \wp_register_script('scem-venue-map', SCEM_URL.'assets/js/venue-map.js', ['scem-leaflet'], SCEM_VERSION, true);
    }
}
