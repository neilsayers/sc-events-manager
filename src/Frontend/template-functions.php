<?php

/**
 * Plain template tags for theme code, alongside the [sc_events]
 * shortcode — both call SCEventsManager\Frontend\EventListingShortcode::render().
 * Not autoloaded (it's not a class), required directly from the main
 * plugin bootstrap file.
 */

if (! function_exists('scem_events_listing')) {
    /**
     * @param array<string, mixed> $atts Same attributes as the [sc_events] shortcode.
     */
    function scem_events_listing(array $atts = []): string
    {
        return \SCEventsManager\Frontend\EventListingShortcode::render($atts);
    }
}

if (! function_exists('scem_the_events_listing')) {
    /**
     * @param array<string, mixed> $atts Same attributes as the [sc_events] shortcode.
     */
    function scem_the_events_listing(array $atts = []): void
    {
        echo scem_events_listing($atts); // Already-escaped HTML from render().
    }
}

if (! function_exists('scem_get_events')) {
    /**
     * Raw occurrence data — the plugin's stable data API for theme code
     * that wants to render its own markup instead of scem_the_events_listing()'s
     * HTML. Same attributes as the [sc_events] shortcode; same
     * filtering/sorting/limiting behind it.
     *
     * A REST equivalent (GET /wp-json/scem/v1/events, same query params)
     * exists for anything outside this site's own PHP — see
     * Frontend\EventsRestController. Both call the same underlying query,
     * so results always match.
     *
     * See Events Manager -> Documentation in wp-admin for the full list
     * of fields each occurrence array contains.
     *
     * @param array<string, mixed> $atts Same attributes as the [sc_events] shortcode.
     * @return array<int, array<string, mixed>>
     */
    function scem_get_events(array $atts = []): array
    {
        return \SCEventsManager\Frontend\EventListingShortcode::query($atts);
    }
}
