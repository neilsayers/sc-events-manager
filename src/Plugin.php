<?php

namespace SCEventsManager;

use SCEventsManager\Admin\CalendarPage;
use SCEventsManager\Admin\DocumentationPage;
use SCEventsManager\Admin\EventTypesMenu;
use SCEventsManager\Admin\EventTypesPage;
use SCEventsManager\Admin\ListingSettingsPage;
use SCEventsManager\Admin\TaxonomiesPage;
use SCEventsManager\Frontend\EventListingShortcode;
use SCEventsManager\Frontend\EventsRestController;
use SCEventsManager\MetaBoxes\EventDetailsMetaBox;
use SCEventsManager\MetaBoxes\OrganiserMetaBox;
use SCEventsManager\MetaBoxes\RestrictionsMetaBox;
use SCEventsManager\MetaBoxes\StatusMetaBox;
use SCEventsManager\MetaBoxes\TicketsMetaBox;
use SCEventsManager\MetaBoxes\VenueDetailsMetaBox;
use SCEventsManager\PostTypes\EventPostTypes;
use SCEventsManager\PostTypes\VenuePostType;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Taxonomies\EventTaxonomies;
use SCEventsManager\Venues\VenueDeletionGuard;

/**
 * Composes the plugin's features and wires them into WordPress.
 *
 * To grow the plugin (a front-end calendar shortcode, REST endpoints,
 * ...) write a class implementing Contracts\Hookable and add it to
 * the list in boot().
 */
final class Plugin
{
    private static ?self $instance = null;

    private Settings $settings;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    private function __construct()
    {
        $this->settings = new Settings();
    }

    public function boot(): void
    {
        $features = [
            new EventTypesPage($this->settings),
            new EventTypesMenu(),
            new TaxonomiesPage($this->settings),
            new CalendarPage($this->settings),
            new ListingSettingsPage($this->settings),
            new EventListingShortcode($this->settings),
            new EventsRestController(),
            new DocumentationPage($this->settings),
            new EventPostTypes($this->settings),
            new EventTaxonomies($this->settings),
            new EventDetailsMetaBox($this->settings),
            new TicketsMetaBox($this->settings),
            new StatusMetaBox($this->settings),
            new OrganiserMetaBox($this->settings),
            new RestrictionsMetaBox($this->settings),
            new VenuePostType(),
            new VenueDetailsMetaBox(),
            new VenueDeletionGuard($this->settings),
        ];

        foreach ($features as $feature) {
            $feature->register();
        }
    }

    public function settings(): Settings
    {
        return $this->settings;
    }
}
