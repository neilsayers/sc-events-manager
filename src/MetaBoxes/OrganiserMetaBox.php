<?php

namespace SCEventsManager\MetaBoxes;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\EventMeta;

/**
 * Side meta box — saving is still handled entirely by
 * EventDetailsMetaBox::saveMetaBox(), see that class's docblock.
 */
final class OrganiserMetaBox implements Hookable
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
            'scem_organiser',
            'Organiser',
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
            <label for="scem_organiser_name">Name</label><br>
            <input type="text" id="scem_organiser_name" name="scem[organiser_name]" class="widefat" value="<?php echo \esc_attr($meta['organiser_name']); ?>">
        </p>
        <p>
            <label for="scem_organiser_email">Email</label><br>
            <input type="email" id="scem_organiser_email" name="scem[organiser_email]" class="widefat" value="<?php echo \esc_attr($meta['organiser_email']); ?>">
        </p>
        <p>
            <label for="scem_organiser_phone">Phone</label><br>
            <input type="text" id="scem_organiser_phone" name="scem[organiser_phone]" class="widefat" value="<?php echo \esc_attr($meta['organiser_phone']); ?>">
        </p>
        <?php
    }
}
