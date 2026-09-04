<?php

namespace SCEventsManager\Admin;

use SCEventsManager\Contracts\Hookable;

/**
 * A single top-level "Event Types" menu that every configured event
 * type's own post list nests under, instead of each type getting its
 * own top-level admin menu entry — see PostTypes\EventPostTypes,
 * which points every type's show_in_menu at PARENT_SLUG. WordPress's
 * own _add_post_type_submenus() then adds exactly one submenu item
 * per type (its "All X" list) automatically — a custom show_in_menu
 * parent only gets that, not an "Add New" entry the way a post type's
 * own default top-level menu does — so, deliberately, that's the only
 * per-type link this menu ever shows. "Add New" is still one click
 * away, from inside a type's own list.
 *
 * Distinct from Admin\EventTypesPage (the "Events Manager > Event
 * Types" settings screen where types are created/renamed/deleted) —
 * this is a separate top-level menu grouping the resulting post
 * *content*, not settings.
 */
final class EventTypesMenu implements Hookable
{
    public const PARENT_SLUG = 'scem-event-types';

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'registerMenu']);
    }

    public function registerMenu(): void
    {
        \add_menu_page(
            'Event Types',
            'Event Types',
            'edit_posts',
            self::PARENT_SLUG,
            [$this, 'renderPage'],
            'dashicons-calendar',
            26.1
        );
    }

    public function renderPage(): void
    {
        ?>
        <div class="wrap">
            <h1>Event Types</h1>
            <p>Pick an event type from the menu on the left to see its own list.</p>
        </div>
        <?php
    }
}
