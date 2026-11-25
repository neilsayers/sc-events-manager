<?php

namespace SCEventsManager\Frontend;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Support\Gallery;

/**
 * [sc_event_gallery] — places an event's gallery (the images picked in
 * its Gallery meta box) at that spot in the event's content, instead of
 * wherever the theme would otherwise put it (typically the end of the
 * page).
 *
 * A theme that renders the gallery itself asks wasPlaced() after the
 * content has rendered, and only appends its own copy when the editor
 * did NOT use the shortcode — so the gallery never appears twice:
 *
 *   $content = apply_filters('the_content', get_the_content());
 *   if (! scem_gallery_was_placed() ) { // render the gallery here }
 *
 * The default markup is a plain linked grid (columns="3" by default,
 * square-cropped). Themes with their own lightbox/grid replace it
 * entirely via the scem_gallery_html filter.
 */
final class GalleryShortcode implements Hookable
{
    public const SHORTCODE_TAG = 'sc_event_gallery';

    /** @var array<int, true> Post IDs whose gallery has been placed this request. */
    private static array $placed = [];

    public function register(): void
    {
        \add_shortcode(self::SHORTCODE_TAG, [$this, 'renderShortcode']);
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function renderShortcode($atts): string
    {
        $atts = \shortcode_atts(['columns' => '3', 'post_id' => ''], (array) $atts, self::SHORTCODE_TAG);

        $postId = (int) $atts['post_id'] ?: (int) \get_the_ID();
        $ids = $postId ? Gallery::read($postId) : [];

        // One placement per event per page — a second shortcode is a no-op.
        if ($ids === [] || isset(self::$placed[$postId])) {
            return '';
        }

        self::$placed[$postId] = true;

        $columns = \max(1, \min(8, (int) $atts['columns']));
        $html = self::defaultHtml($ids, $postId, $columns);

        /**
         * Replace the gallery markup (e.g. to use the theme's own grid
         * and lightbox).
         *
         * @param string $html     The default markup.
         * @param int[]  $ids      Attachment IDs, in saved order.
         * @param int    $postId   The event the gallery belongs to.
         * @param array  $atts     The shortcode attributes (columns, post_id).
         */
        return (string) \apply_filters('scem_gallery_html', $html, $ids, $postId, $atts);
    }

    public static function wasPlaced(?int $postId = null): bool
    {
        $postId = $postId ?: (int) \get_the_ID();

        return isset(self::$placed[$postId]);
    }

    /**
     * @param int[] $ids
     */
    private static function defaultHtml(array $ids, int $postId, int $columns): string
    {
        $items = '';

        foreach ($ids as $id) {
            $alt = (string) \get_post_meta($id, '_wp_attachment_image_alt', true);
            $full = \wp_get_attachment_image_url($id, 'full');
            $img = \wp_get_attachment_image($id, 'large', false, ['alt' => $alt, 'loading' => 'lazy']);

            if (! $img || ! $full) {
                continue;
            }

            $items .= '<li class="scem-gallery-item"><a href="'.\esc_url($full).'">'.$img.'</a></li>';
        }

        if ($items === '') {
            return '';
        }

        return '<ul class="scem-gallery" style="--scem-gallery-columns:'.$columns.'">'.$items.'</ul>';
    }
}
