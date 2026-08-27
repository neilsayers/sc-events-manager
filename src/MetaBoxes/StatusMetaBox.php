<?php

namespace SCEventsManager\MetaBoxes;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\EventMeta;

/**
 * A domain status (scheduled/postponed/cancelled/sold out) kept as
 * its own postmeta field rather than a custom post_status — an event
 * being "cancelled" is a fact about the event, not about whether the
 * page should still be published, and those two need to vary
 * independently (a cancelled event usually stays live with a notice,
 * not unpublished).
 *
 * Saving is still handled entirely by
 * EventDetailsMetaBox::saveMetaBox(), see that class's docblock.
 */
final class StatusMetaBox implements Hookable
{
    public const CHOICES = [
        'scheduled' => 'Scheduled',
        'postponed' => 'Postponed',
        'cancelled' => 'Cancelled',
        'sold_out' => 'Sold out',
    ];

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('add_meta_boxes', [$this, 'addMetaBox']);
    }

    public function addMetaBox(): void
    {
        $eventPostTypes = \array_keys($this->settings->allEventTypes());

        // add_meta_box() treats an empty $screen array as "use the
        // current screen" rather than "no screens" — without this
        // guard, zero configured event types means this box would
        // register itself on whatever post type is being edited.
        if ($eventPostTypes === []) {
            return;
        }

        \add_meta_box(
            'scem_event_status',
            'Event Status',
            [$this, 'render'],
            $eventPostTypes,
            'side',
            'default'
        );
    }

    public function render(\WP_Post $post): void
    {
        $meta = EventMeta::read($post->ID);
        ?>
        <p>
            <select id="scem_status" name="scem[status]" class="widefat">
                <?php foreach (self::CHOICES as $value => $label) : ?>
                    <option value="<?php echo \esc_attr($value); ?>" <?php \selected($meta['status'], $value); ?>><?php echo \esc_html($label); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <?php
    }
}
