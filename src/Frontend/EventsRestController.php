<?php

namespace SCEventsManager\Frontend;

use SCEventsManager\Contracts\Hookable;

/**
 * GET /wp-json/scem/v1/events — the plugin's public, versioned data
 * contract for anything outside this site's own PHP (a decoupled
 * front end, another site, a build step, ...). Same query params as
 * the [sc_events] shortcode, same underlying query as scem_get_events()
 * (see EventListingShortcode::query()) — this is a thin JSON wrapper
 * around it, not a second implementation.
 *
 * "v1" is a promise: existing fields in the response won't be renamed
 * or removed within v1. A field can be added; a genuinely breaking
 * change gets a v2 route instead, so anything already integrated
 * against v1 keeps working. See Events Manager -> Documentation.
 */
final class EventsRestController implements Hookable
{
    private const NAMESPACE = 'scem/v1';
    private const ROUTE = '/events';

    public function register(): void
    {
        \add_action('rest_api_init', [$this, 'registerRoutes']);
    }

    public function registerRoutes(): void
    {
        \register_rest_route(self::NAMESPACE, self::ROUTE, [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handleRequest'],
            // Read-only, and every field it returns is already public on
            // a published post's own front-end page — nothing here is
            // gated behind a capability check.
            'permission_callback' => '__return_true',
            'args' => [
                'type' => [
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'Comma-separated event type post_type keys (e.g. "gig,show"). Empty means every configured type.',
                ],
                'range' => [
                    'type' => 'string',
                    'default' => 'future',
                    'enum' => ['future', 'past', 'month', 'week'],
                    'description' => 'Which window of dates to search.',
                ],
                'month' => [
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => static fn ($value): bool => $value === '' || \preg_match('/^\d{4}-\d{2}$/', (string) $value) === 1,
                    'description' => 'range="month" only — target month as "YYYY-MM". Empty means the current calendar month.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'default' => 10,
                    'sanitize_callback' => 'absint',
                    'description' => 'Maximum occurrences to return. 0 means no limit.',
                ],
                'venue' => [
                    'type' => 'integer',
                    'default' => 0,
                    'sanitize_callback' => 'absint',
                    'description' => 'A Venue post ID to filter by.',
                ],
                'taxonomy' => [
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_key',
                    'description' => 'A custom taxonomy slug to filter by (paired with term).',
                ],
                'term' => [
                    'type' => 'string',
                    'default' => '',
                    'sanitize_callback' => 'sanitize_text_field',
                    'description' => 'The term slug within taxonomy to filter by.',
                ],
                'show_cancelled' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include events marked Cancelled.',
                ],
                'combine_multidate_events' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Collapse a multi-day event into a single row with a date range instead of one row per date.',
                ],
            ],
        ]);
    }

    public function handleRequest(\WP_REST_Request $request): \WP_REST_Response
    {
        $atts = [
            'type' => (string) $request->get_param('type'),
            'range' => (string) $request->get_param('range'),
            'month' => (string) $request->get_param('month'),
            'limit' => (string) $request->get_param('limit'),
            'venue' => (string) $request->get_param('venue'),
            'taxonomy' => (string) $request->get_param('taxonomy'),
            'term' => (string) $request->get_param('term'),
            'show_cancelled' => $request->get_param('show_cancelled') ? 'true' : 'false',
            'combine_multidate_events' => $request->get_param('combine_multidate_events') ? 'true' : 'false',
        ];

        $occurrences = \array_map(
            [$this, 'forPublicResponse'],
            EventListingShortcode::query($atts)
        );

        return new \WP_REST_Response([
            'events' => \array_values($occurrences),
            'total' => \count($occurrences),
        ]);
    }

    /**
     * Strips edit_url — a wp-admin post-edit link, useful to trusted
     * same-site PHP (scem_get_events()) but not something an anonymous
     * public API response should be handing out. Every other field is
     * already what the post's own front-end page shows.
     *
     * @param array<string, mixed> $occurrence
     * @return array<string, mixed>
     */
    private function forPublicResponse(array $occurrence): array
    {
        unset($occurrence['edit_url']);

        return $occurrence;
    }
}
