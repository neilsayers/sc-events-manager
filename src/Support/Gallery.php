<?php

namespace SCEventsManager\Support;

/**
 * An event's image gallery: an ordered list of Media Library
 * attachment IDs, capped at MAX_IMAGES.
 *
 * Only IDs are stored (not URLs or sizes) so images keep working
 * when the library file is replaced, and a theme can ask WordPress
 * for whichever of the site's registered image sizes suits its
 * layout — see read().
 */
final class Gallery
{
    public const META_KEY = '_scem_gallery';

    public const MAX_IMAGES = 20;

    /**
     * The attachment IDs that are still real images, in saved order.
     *
     * @return array<int, int>
     */
    public static function read(int $postId): array
    {
        $ids = \get_post_meta($postId, self::META_KEY, true);

        return \is_array($ids) ? self::sanitize($ids) : [];
    }

    /**
     * @param array<int, mixed> $ids
     */
    public static function save(int $postId, array $ids): void
    {
        $ids = self::sanitize($ids);

        if ($ids === []) {
            \delete_post_meta($postId, self::META_KEY);

            return;
        }

        \update_post_meta($postId, self::META_KEY, $ids);
    }

    /**
     * Drops anything that isn't an existing image attachment,
     * de-duplicates, and trims to the limit.
     *
     * @param array<int, mixed> $ids
     *
     * @return array<int, int>
     */
    public static function sanitize(array $ids): array
    {
        $clean = [];

        foreach ($ids as $id) {
            $id = (int) $id;

            if ($id > 0 && ! \in_array($id, $clean, true) && \wp_attachment_is_image($id)) {
                $clean[] = $id;
            }
        }

        return \array_slice($clean, 0, self::limit());
    }

    public static function limit(): int
    {
        return \max(1, (int) \apply_filters('scem_gallery_max_images', self::MAX_IMAGES));
    }
}
