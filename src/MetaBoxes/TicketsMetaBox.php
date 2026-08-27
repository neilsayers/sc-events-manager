<?php

namespace SCEventsManager\MetaBoxes;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\EventMeta;

/**
 * Side meta box — saving is still handled entirely by
 * EventDetailsMetaBox::saveMetaBox(), see that class's docblock.
 */
final class TicketsMetaBox implements Hookable
{
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
            'scem_tickets',
            'Tickets & Price',
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
            <label for="scem_ticket_url">Ticket/booking link</label><br>
            <input type="url" id="scem_ticket_url" name="scem[ticket_url]" class="widefat" value="<?php echo \esc_attr($meta['ticket_url']); ?>" placeholder="https://...">
        </p>
        <p>
            <label for="scem_price">Price</label><br>
            <input type="text" id="scem_price" name="scem[price]" class="widefat" value="<?php echo \esc_attr($meta['price']); ?>" placeholder="e.g. £12 / £8 concessions">
        </p>
        <?php
    }
}
