<?php

namespace SCEventsManager\MetaBoxes;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\Gallery;

/**
 * The event's image gallery — pick images from the Media Library,
 * drag to reorder, remove with the ×. Sits last in the "normal"
 * context, under Tickets & Prices.
 *
 * Saving is still handled entirely by
 * EventDetailsMetaBox::saveMetaBox(), see that class's docblock.
 */
final class GalleryMetaBox implements Hookable
{
    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('add_meta_boxes', [$this, 'addMetaBox']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    private function isEventPostType(string $postType): bool
    {
        return $this->settings->getEventType($postType) !== null;
    }

    public function addMetaBox(): void
    {
        $eventPostTypes = \array_keys($this->settings->allEventTypes());

        // See OrganiserMetaBox: an empty screen list would mean "the
        // current screen", not "none".
        if ($eventPostTypes === []) {
            return;
        }

        \add_meta_box(
            'scem_gallery',
            'Gallery',
            [$this, 'render'],
            $eventPostTypes,
            'normal',
            'low'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (! \in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        if (! $this->isEventPostType(\get_current_screen()->post_type ?? '')) {
            return;
        }

        \wp_enqueue_media();
        \wp_enqueue_style('scem-admin', SCEM_URL.'assets/css/admin.css', [], SCEM_VERSION);
        \wp_enqueue_script('scem-gallery', SCEM_URL.'assets/js/gallery.js', ['jquery', 'jquery-ui-sortable'], SCEM_VERSION, true);
        \wp_localize_script('scem-gallery', 'scemGallery', [
            'max' => Gallery::limit(),
            'title' => 'Choose gallery images',
            'button' => 'Add to gallery',
        ]);
    }

    public function render(\WP_Post $post): void
    {
        $ids = Gallery::read($post->ID);
        $max = Gallery::limit();
        ?>
        <div class="scem-gallery" data-max="<?php echo (int) $max; ?>">
            <p class="description">Up to <?php echo (int) $max; ?> images. Drag to reorder.</p>

            <ul class="scem-gallery-list" id="scem_gallery_list">
                <?php foreach ($ids as $id) : ?>
                    <?php $this->renderItem($id); ?>
                <?php endforeach; ?>
            </ul>

            <p>
                <button type="button" class="button" id="scem_gallery_add">Add images</button>
                <span class="scem-gallery-count" id="scem_gallery_count"></span>
            </p>
        </div>
        <?php
    }

    private function renderItem(int $id): void
    {
        $thumb = \wp_get_attachment_image($id, 'thumbnail', false, ['alt' => '']);
        ?>
        <li class="scem-gallery-item" data-id="<?php echo (int) $id; ?>">
            <?php echo $thumb; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <input type="hidden" name="scem[gallery][]" value="<?php echo (int) $id; ?>">
            <button type="button" class="scem-gallery-remove" aria-label="Remove image">&times;</button>
        </li>
        <?php
    }
}
