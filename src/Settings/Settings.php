<?php

namespace SCEventsManager\Settings;

use SCEventsManager\Frontend\ListingFields;

/**
 * Reads/writes the plugin's single options-table row: a collection of
 * event types keyed by their post_type slug.
 *
 * For each type, only the post_type key and URL slug are fixed for
 * the lifetime of the site — both derived once from the labels given
 * when the type is created (see addEventType()). Renaming a type
 * afterwards (relabelEventType()) only ever touches its display
 * labels; changing the key or slug later would orphan existing posts
 * or break their permalinks. Deleting one (deleteEventType()) is
 * allowed, but only removes this settings entry — Admin\EventTypesPage
 * is responsible for trashing the type's posts and deleting its
 * taxonomies *before* calling it, so nothing is left orphaned.
 */
final class Settings
{
    private const OPTION_KEY = 'scem_settings';

    private const DEFAULTS = [
        'event_types' => [],
        'taxonomies' => [],
        'listing_fields' => null, // null = not yet saved; resolved to ListingFields::DEFAULT_ENABLED on read.
    ];

    private array $values;

    public function __construct()
    {
        $stored = \get_option(self::OPTION_KEY, []);
        $this->values = \is_array($stored) ? \array_merge(self::DEFAULTS, $stored) : self::DEFAULTS;
    }

    /**
     * @return array<string, array{post_type: string, slug: string, label_singular: string, label_plural: string}>
     */
    public function allEventTypes(): array
    {
        return $this->values['event_types'];
    }

    public function hasEventTypes(): bool
    {
        return $this->values['event_types'] !== [];
    }

    public function getEventType(string $postType): ?array
    {
        return $this->values['event_types'][$postType] ?? null;
    }

    /**
     * Filterable so a site can raise the cap later — e.g.
     * add_filter('scem_max_event_types', fn () => 8) — without
     * touching the plugin itself.
     */
    public function maxEventTypes(): int
    {
        return (int) \apply_filters('scem_max_event_types', 4);
    }

    public function canAddEventType(): bool
    {
        return \count($this->values['event_types']) < $this->maxEventTypes();
    }

    /**
     * A WordPress post type name is capped at 20 characters and must
     * be lowercase alphanumeric/underscore/dash — sanitize_key() plus
     * a hard truncate keeps register_post_type() from silently
     * failing on a long or oddly-punctuated singular label.
     */
    public static function derivePostTypeKey(string $label): string
    {
        return \substr(\sanitize_key($label), 0, 20);
    }

    public static function deriveSlug(string $label): string
    {
        return \sanitize_title($label);
    }

    /**
     * Checked before addEventType() so the admin screen can show one
     * error message covering every failure mode.
     */
    public function validateNewEventType(string $labelSingular, string $labelPlural): ?string
    {
        if ($labelSingular === '' || $labelPlural === '') {
            return \__('Please enter both a singular and plural name.', 'sc-events-manager');
        }

        if (! $this->canAddEventType()) {
            return \sprintf(
                \__('You already have the maximum of %d event types.', 'sc-events-manager'),
                $this->maxEventTypes()
            );
        }

        $postType = self::derivePostTypeKey($labelSingular);

        if ($postType === '') {
            return \__('That singular name can\'t be turned into a valid post type — please use letters or numbers.', 'sc-events-manager');
        }

        if (\post_type_exists($postType) || isset($this->values['event_types'][$postType])) {
            return \__('That singular name is already in use — please try a different one.', 'sc-events-manager');
        }

        return null;
    }

    /**
     * @return string The new event type's post_type key.
     */
    public function addEventType(string $labelSingular, string $labelPlural): string
    {
        $postType = self::derivePostTypeKey($labelSingular);

        $this->values['event_types'][$postType] = [
            'post_type' => $postType,
            'slug' => self::deriveSlug($labelPlural),
            'label_singular' => $labelSingular,
            'label_plural' => $labelPlural,
        ];

        \update_option(self::OPTION_KEY, $this->values);

        return $postType;
    }

    /**
     * Safe to call any time — only the display labels change; see
     * class docblock for why post_type/slug are never touched again.
     */
    public function relabelEventType(string $postType, string $labelSingular, string $labelPlural): void
    {
        if (! isset($this->values['event_types'][$postType])) {
            return;
        }

        $this->values['event_types'][$postType]['label_singular'] = $labelSingular;
        $this->values['event_types'][$postType]['label_plural'] = $labelPlural;

        \update_option(self::OPTION_KEY, $this->values);
    }

    /**
     * Removes this event type from settings only — it does not touch
     * the type's posts or taxonomies. Callers (Admin\EventTypesPage::
     * handleDelete()) are expected to trash the posts and delete the
     * taxonomies first, since both need the type to still be a real,
     * registered post type to do their own work correctly.
     */
    public function deleteEventType(string $postType): void
    {
        unset($this->values['event_types'][$postType]);

        \update_option(self::OPTION_KEY, $this->values);
    }

    /**
     * @return array<string, array{taxonomy: string, post_type: string, label_singular: string, label_plural: string, hierarchical: bool}>
     */
    public function allTaxonomies(): array
    {
        return $this->values['taxonomies'];
    }

    /**
     * @return array<string, array{taxonomy: string, post_type: string, label_singular: string, label_plural: string, hierarchical: bool}>
     */
    public function taxonomiesForEventType(string $postType): array
    {
        return \array_filter(
            $this->values['taxonomies'],
            static fn (array $taxonomy): bool => $taxonomy['post_type'] === $postType
        );
    }

    public function getTaxonomy(string $taxonomy): ?array
    {
        return $this->values['taxonomies'][$taxonomy] ?? null;
    }

    /**
     * Filterable so a site can raise the cap later — e.g.
     * add_filter('scem_max_taxonomies_per_event_type', fn () => 4).
     * Starts at 1: each event type gets a single custom taxonomy for
     * now.
     */
    public function maxTaxonomiesPerEventType(): int
    {
        return (int) \apply_filters('scem_max_taxonomies_per_event_type', 1);
    }

    public function canAddTaxonomyForEventType(string $postType): bool
    {
        return \count($this->taxonomiesForEventType($postType)) < $this->maxTaxonomiesPerEventType();
    }

    /**
     * A WordPress taxonomy name is capped at 32 characters and must be
     * lowercase alphanumeric/underscore/dash.
     */
    public static function deriveTaxonomyKey(string $label): string
    {
        return \substr(\sanitize_key($label), 0, 32);
    }

    /**
     * Checked before addTaxonomy() so the admin screen can show one
     * error message covering every failure mode.
     */
    public function validateNewTaxonomy(string $postType, string $labelSingular, string $labelPlural): ?string
    {
        if (! isset($this->values['event_types'][$postType])) {
            return \__('Please choose which event type this taxonomy belongs to.', 'sc-events-manager');
        }

        if ($labelSingular === '' || $labelPlural === '') {
            return \__('Please enter both a singular and plural name.', 'sc-events-manager');
        }

        if (! $this->canAddTaxonomyForEventType($postType)) {
            return \sprintf(
                \__('This event type already has the maximum of %d custom taxonomies.', 'sc-events-manager'),
                $this->maxTaxonomiesPerEventType()
            );
        }

        $taxonomy = self::deriveTaxonomyKey($labelSingular);

        if ($taxonomy === '') {
            return \__('That singular name can\'t be turned into a valid taxonomy — please use letters or numbers.', 'sc-events-manager');
        }

        if (\taxonomy_exists($taxonomy) || isset($this->values['taxonomies'][$taxonomy])) {
            return \__('That singular name is already in use — please try a different one.', 'sc-events-manager');
        }

        return null;
    }

    /**
     * @return string The new taxonomy's key.
     */
    public function addTaxonomy(string $postType, string $labelSingular, string $labelPlural, bool $hierarchical): string
    {
        $taxonomy = self::deriveTaxonomyKey($labelSingular);

        $this->values['taxonomies'][$taxonomy] = [
            'taxonomy' => $taxonomy,
            'post_type' => $postType,
            'label_singular' => $labelSingular,
            'label_plural' => $labelPlural,
            'hierarchical' => $hierarchical,
        ];

        \update_option(self::OPTION_KEY, $this->values);

        return $taxonomy;
    }

    /**
     * Unlike event types, a taxonomy can be freely deleted — its
     * term relationships just stop being read once it's no longer
     * registered, which doesn't touch the posts themselves.
     */
    public function deleteTaxonomy(string $taxonomy): void
    {
        unset($this->values['taxonomies'][$taxonomy]);

        \update_option(self::OPTION_KEY, $this->values);
    }

    /**
     * Which fields a front-end listing shows, in display order — see
     * Frontend\ListingFields for the available set and why this is an
     * ordered list rather than independent booleans.
     *
     * @return string[]
     */
    public function listingFields(): array
    {
        return $this->values['listing_fields'] ?? ListingFields::DEFAULT_ENABLED;
    }

    /**
     * @param string[] $fields
     */
    public function saveListingFields(array $fields): void
    {
        $this->values['listing_fields'] = ListingFields::sanitize($fields);

        \update_option(self::OPTION_KEY, $this->values);
    }
}
