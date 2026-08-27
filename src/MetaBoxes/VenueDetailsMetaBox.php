<?php

namespace SCEventsManager\MetaBoxes;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\PostTypes\VenuePostType;
use SCEventsManager\Support\LocationFields;

/**
 * The Venue post type's own edit screen: just the shared location
 * fields, since the post title already serves as the venue's name.
 */
final class VenueDetailsMetaBox implements Hookable
{
    private const NONCE_ACTION = 'scem_save_venue_details';
    private const NONCE_NAME = 'scem_venue_details_nonce';

    public function register(): void
    {
        \add_action('edit_form_after_title', [$this, 'renderMetaBox']);
        \add_action('save_post_'.VenuePostType::POST_TYPE, [$this, 'saveMetaBox']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(string $hook): void
    {
        if (! \in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        if ((\get_current_screen()->post_type ?? '') !== VenuePostType::POST_TYPE) {
            return;
        }

        LocationFields::enqueueMapAssets();
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        if ($post->post_type !== VenuePostType::POST_TYPE) {
            return;
        }

        \wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <div class="postbox scem-event-details">
            <h2 class="hndle"><span>Venue Details</span></h2>
            <div class="inside">
                <?php LocationFields::render(LocationFields::readMeta($post->ID), false); ?>
            </div>
        </div>
        <?php
    }

    public function saveMetaBox(int $postId): void
    {
        if (
            ! isset($_POST[self::NONCE_NAME])
            || ! \wp_verify_nonce(\sanitize_text_field(\wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
        ) {
            return;
        }

        if (\defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (! \current_user_can('edit_post', $postId)) {
            return;
        }

        LocationFields::save($postId, \wp_unslash($_POST['scem'] ?? []));
    }
}
