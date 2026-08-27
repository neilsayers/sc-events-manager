<?php

namespace SCEventsManager\Venues;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\PostTypes\VenuePostType;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\LocationFields;
use SCEventsManager\Support\MetaField;

/**
 * Events store a live reference to their venue (_scem_venue_id), so
 * editing a saved venue updates every event using it — the whole
 * point. But that means deleting a venue would otherwise leave those
 * events pointing at nothing. Instead, the moment before a venue is
 * actually deleted, its current details are copied into each
 * referencing event's own manual fields and the reference is cleared
 * — the event keeps its location, it just stops being "linked".
 */
final class VenueDeletionGuard implements Hookable
{
    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('before_delete_post', [$this, 'detachFromDeletedVenue']);
    }

    public function detachFromDeletedVenue(int $postId): void
    {
        $post = \get_post($postId);

        if (! $post || $post->post_type !== VenuePostType::POST_TYPE) {
            return;
        }

        $eventPostTypes = \array_keys($this->settings->allEventTypes());

        if ($eventPostTypes === []) {
            return;
        }

        $venueData = LocationFields::readMeta($postId);
        $venueData['venue_name'] = \get_the_title($postId);

        $referencingEvents = \get_posts([
            'post_type' => $eventPostTypes,
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids',
            'meta_query' => [[
                'key' => '_scem_venue_id',
                'value' => $postId,
            ]],
        ]);

        foreach ($referencingEvents as $eventId) {
            foreach ($venueData as $key => $value) {
                if ($key === 'venue_name') {
                    MetaField::saveText($eventId, '_scem_venue_name', $value);

                    continue;
                }

                MetaField::saveValue($eventId, "_scem_{$key}", \is_bool($value) ? ($value ? '1' : '') : (string) $value);
            }

            \delete_post_meta($eventId, '_scem_venue_id');
        }
    }
}
