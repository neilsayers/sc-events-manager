<?php

namespace SCEventsManager\Frontend;

/**
 * The set of occurrence fields a front-end listing can show, and
 * their canonical display order. Settings stores an ordered list of
 * *enabled* keys (not one independent boolean per field) so today's
 * checkbox UI and a future drag-and-drop reorder tool share the exact
 * same data shape — no migration needed when that arrives. Title is
 * not in this list: a listing entry without its own title isn't a
 * listing, so it's always shown.
 */
final class ListingFields
{
    public const AVAILABLE = [
        'featured_image' => 'Featured image',
        'date_time' => 'Date & time',
        'venue' => 'Venue',
        'price' => 'Price',
        'status_badge' => 'Status',
        'type_label' => 'Event type',
        'excerpt' => 'Excerpt',
        'ticket_link' => 'Ticket link',
    ];

    public const DEFAULT_ENABLED = ['featured_image', 'date_time', 'venue', 'price', 'ticket_link'];

    public static function labelFor(string $key): string
    {
        return self::AVAILABLE[$key] ?? $key;
    }

    /**
     * @param string[] $keys
     * @return string[] Only recognised keys, in AVAILABLE's canonical order.
     */
    public static function sanitize(array $keys): array
    {
        return \array_values(\array_intersect(\array_keys(self::AVAILABLE), $keys));
    }
}
