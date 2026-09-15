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

if (! function_exists('scem_get_event')) {
    /**
     * One event's full schedule — every occurrence of a single event
     * post, in the same shape scem_get_events() returns per row. A
     * one-day event returns a single row; a multi-day event returns
     * one row per date in its range; a recurring event returns one
     * row per date up to its recurrence_until (or an 18-month window,
     * whichever comes first — same cap scem_get_events() itself
     * uses). Meant for a single-event template, which needs the
     * event's own whole schedule rather than a cross-event listing —
     * scem_get_events() has no "just this one post" query shape.
     *
     * Returns [] when $postId isn't a configured event post type, or
     * has no valid start date.
     *
     * @return array<int, array<string, mixed>>
     */
    function scem_get_event(?int $postId = null): array
    {
        $postId = $postId ?: \get_the_ID();
        $settings = \SCEventsManager\Plugin::instance()->settings();

        if (! $postId || $settings->getEventType(\get_post_type($postId) ?: '') === null) {
            return [];
        }

        $startDate = (string) \get_post_meta($postId, '_scem_start_date', true);

        if (! \preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            return [];
        }

        $today = new \DateTimeImmutable('today');
        $anchor = new \DateTimeImmutable($startDate);
        $rangeStart = $anchor < $today ? $anchor : $today;
        $rangeEnd = $rangeStart->modify('+18 months');

        return (new \SCEventsManager\Calendar\EventOccurrences($settings))->forPost($postId, $rangeStart, $rangeEnd);
    }
}

if (! function_exists('scem_render_venue_map')) {
    /**
     * A plain Leaflet/OpenStreetMap pin for one point — this plugin's
     * own bundled map (Frontend\VenueMapAssets gets it onto the page;
     * the same vendored Leaflet as the admin's venue picker), so a
     * single-event template showing its venue's location never has to
     * depend on another plugin (e.g. SC Maps) being active. Pass the
     * venue_lat/venue_lng/venue_name/venue_address values
     * scem_get_event() already gives you. Returns '' when lat/lng are
     * missing — the venue has never been pinned on the admin map.
     *
     * @param array{lat: float|string, lng: float|string, name?: string, address?: string} $point
     * @param array{height?: int} $args
     */
    function scem_render_venue_map(array $point, array $args = []): string
    {
        if (($point['lat'] ?? '') === '' || ($point['lng'] ?? '') === '') {
            return '';
        }

        // Registered (not yet enqueued) by VenueMapAssets::maybeEnqueueStyles()
        // early enough for its styles to print — the scripts are
        // in_footer, so enqueuing them for real this late is still fine.
        \wp_enqueue_script('scem-leaflet');
        \wp_enqueue_script('scem-venue-map');

        $height = \max(150, (int) ($args['height'] ?? 200));
        $id = \uniqid('scem-venue-map-');

        $config = [
            'lat' => (float) $point['lat'],
            'lng' => (float) $point['lng'],
            'name' => (string) ($point['name'] ?? ''),
            'address' => (string) ($point['address'] ?? ''),
        ];

        return \sprintf(
            '<div id="%1$s" class="scem-venue-map" style="height:%2$dpx;" data-config="%3$s"></div>',
            \esc_attr($id),
            $height,
            \esc_attr((string) \wp_json_encode($config))
        );
    }
}

if (! function_exists('scem_the_venue_map')) {
    /**
     * Echoes scem_render_venue_map() — for direct use in a template file.
     *
     * @param array{lat: float|string, lng: float|string, name?: string, address?: string} $point
     * @param array{height?: int} $args
     */
    function scem_the_venue_map(array $point, array $args = []): void
    {
        echo scem_render_venue_map($point, $args); // phpcs:ignore WordPress.Security.EscapeOutput -- already-escaped HTML above.
    }
}

if (! function_exists('scem_get_ticket_prices')) {
    /**
     * An event's ticket price rows, ready to render: each row has a
     * label ("Child (under 5)") and a display price ("Free", "£10"),
     * in canonical order. Empty array when the event has no prices
     * set, or when $postId isn't an event.
     *
     * @return array<int, array{type: string, type_label: string, label: string, age_from: string, age_under: string, free: bool, amount: string, display: string}>
     */
    function scem_get_ticket_prices(?int $postId = null): array
    {
        $postId = $postId ?: \get_the_ID();

        if (! $postId || \SCEventsManager\Plugin::instance()->settings()->getEventType(\get_post_type($postId) ?: '') === null) {
            return [];
        }

        $currency = \SCEventsManager\Plugin::instance()->settings()->currency();

        return \array_map(
            static fn (array $row): array => $row + [
                'type_label' => \SCEventsManager\Support\TicketPrices::TYPES[$row['type']],
                'label' => \SCEventsManager\Support\TicketPrices::rowLabel($row),
                'display' => \SCEventsManager\Support\TicketPrices::rowPrice($row, $currency),
            ],
            \SCEventsManager\Support\TicketPrices::read($postId)
        );
    }
}

if (! function_exists('scem_the_ticket_prices')) {
    /**
     * Prints the full price breakdown for an event — the table a
     * listing card deliberately doesn't have room for, plus any
     * ticket conditions. Prints nothing when there are no prices, so
     * it's safe to call unconditionally from a single-post template.
     */
    function scem_the_ticket_prices(?int $postId = null): void
    {
        $postId = $postId ?: \get_the_ID();
        $rows = scem_get_ticket_prices($postId);
        $notes = $postId ? \SCEventsManager\Support\TicketPrices::readNotes($postId) : '';

        if ($rows === []) {
            return;
        }

        \wp_enqueue_style('scem-frontend', SCEM_URL.'assets/css/frontend.css', [], SCEM_VERSION);

        echo '<div class="scem-ticket-prices">';
        echo '<table class="scem-ticket-prices-table"><caption class="screen-reader-text">Ticket prices</caption><tbody>';

        foreach ($rows as $row) {
            echo '<tr>'
                .'<th scope="row">'.\esc_html($row['label']).'</th>'
                .'<td>'.\esc_html($row['display']).'</td>'
                .'</tr>';
        }

        echo '</tbody></table>';

        if ($notes !== '') {
            echo '<p class="scem-ticket-prices-notes">'.\esc_html($notes).'</p>';
        }

        echo '</div>';
    }
}
