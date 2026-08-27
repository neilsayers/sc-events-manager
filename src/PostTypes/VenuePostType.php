<?php

namespace SCEventsManager\PostTypes;

use SCEventsManager\Contracts\Hookable;

/**
 * A single global "Venue" post type shared across every event type —
 * a venue isn't tied to any one kind of event (a "Show" and a "Gig"
 * can both happen in the same room), so unlike event types this one
 * is fixed rather than user-named, and always registered.
 */
final class VenuePostType implements Hookable
{
    public const POST_TYPE = 'scem_venue';

    public function register(): void
    {
        \add_action('init', [$this, 'registerPostType']);
        \add_action('admin_menu', [$this, 'registerAddNewSubmenu']);
    }

    /**
     * A custom show_in_menu parent (rather than `true`) only gets WP's
     * automatic "All Items" submenu, not "Add New" — add it explicitly
     * so a new venue is one visible click away, not just the toolbar's
     * "+ New" dropdown.
     */
    public function registerAddNewSubmenu(): void
    {
        \add_submenu_page(
            'scem-settings',
            'Add New Venue',
            'Add New Venue',
            'edit_posts',
            'post-new.php?post_type='.self::POST_TYPE
        );
    }

    public function registerPostType(): void
    {
        \register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => 'Venues',
                'singular_name' => 'Venue',
                'add_new' => 'Add New Venue',
                'add_new_item' => 'Add New Venue',
                'edit_item' => 'Edit Venue',
                'new_item' => 'New Venue',
                'view_item' => 'View Venue',
                'view_items' => 'View Venues',
                'search_items' => 'Search Venues',
                'not_found' => 'No venues found',
                'not_found_in_trash' => 'No venues found in Trash',
                'all_items' => 'All Venues',
                'menu_name' => 'Venues',
                'name_admin_bar' => 'Venue',
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'scem-settings',
            'menu_icon' => 'dashicons-location-alt',
            'show_in_rest' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
        ]);
    }
}
