<?php

namespace SCEventsManager\MetaBoxes;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\EventMeta;

/**
 * Side meta box — saving is still handled entirely by
 * EventDetailsMetaBox::saveMetaBox(), see that class's docblock. The
 * age-range/responsible-adult show-hide behaviour lives in
 * event-meta-box.js and doesn't care which box these fields render
 * in, only that the element IDs stay the same.
 */
final class RestrictionsMetaBox implements Hookable
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
            'scem_restrictions',
            'Restrictions',
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
            <label>
                <input type="checkbox" id="scem_age_restricted" name="scem[age_restricted]" value="1" <?php \checked($meta['age_restricted']); ?>>
                This event has an age restriction
            </label>
        </p>
        <p id="scem-age-range-row">
            <label>Over <input type="number" id="scem_age_over" name="scem[age_over]" min="0" max="120" class="small-text" value="<?php echo \esc_attr($meta['age_over']); ?>"> only</label><br>
            <label>Under <input type="number" id="scem_age_under" name="scem[age_under]" min="0" max="120" class="small-text" value="<?php echo \esc_attr($meta['age_under']); ?>"> (if applicable)</label>
        </p>
        <p id="scem-responsible-adult-row">
            <label>
                <input type="checkbox" name="scem[responsible_adult_required]" value="1" <?php \checked($meta['responsible_adult_required']); ?>>
                Responsible adult must be present
            </label>
        </p>
        <p>
            <label>
                <input type="checkbox" name="scem[dogs_allowed]" value="1" <?php \checked($meta['dogs_allowed']); ?>>
                Dogs allowed
            </label><br>
            <span class="description">Guide/assistance dogs are always permitted regardless of this setting.</span>
        </p>
        <?php
    }
}
