<?php

namespace SCEventsManager\Taxonomies;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\PostTypes\EventPostTypes;
use SCEventsManager\Settings\Settings;

/**
 * Registers every configured custom taxonomy against its event type.
 * Nothing is registered until at least one has been created — see
 * Admin\TaxonomiesPage.
 */
final class EventTaxonomies implements Hookable
{
    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('init', [$this, 'registerTaxonomies'], 20);
    }

    /**
     * Priority 20 so it runs after EventPostTypes registers the post
     * types these taxonomies attach to (both hook 'init', default 10).
     */
    public function registerTaxonomies(): void
    {
        foreach ($this->settings->allTaxonomies() as $taxonomy) {
            \register_taxonomy($taxonomy['taxonomy'], $taxonomy['post_type'], [
                'labels' => $this->buildLabels($taxonomy['label_singular'], $taxonomy['label_plural']),
                'public' => true,
                'hierarchical' => $taxonomy['hierarchical'],
                'show_in_rest' => false,
                'show_admin_column' => true,
            ]);
        }

        if (\get_transient(EventPostTypes::FLUSH_TRANSIENT)) {
            \delete_transient(EventPostTypes::FLUSH_TRANSIENT);
            \flush_rewrite_rules();
        }
    }

    private function buildLabels(string $singular, string $plural): array
    {
        return [
            'name' => $plural,
            'singular_name' => $singular,
            'search_items' => \sprintf('Search %s', $plural),
            'all_items' => \sprintf('All %s', $plural),
            'edit_item' => \sprintf('Edit %s', $singular),
            'update_item' => \sprintf('Update %s', $singular),
            'add_new_item' => \sprintf('Add New %s', $singular),
            'new_item_name' => \sprintf('New %s Name', $singular),
            'menu_name' => $plural,
        ];
    }
}
