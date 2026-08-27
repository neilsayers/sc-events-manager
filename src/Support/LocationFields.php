<?php

namespace SCEventsManager\Support;

/**
 * The venue/location field set — name, address, map, indoor/outdoor,
 * disabled access — shared between the event meta box's "manual
 * venue" mode and the Venue post type's own edit screen. Same
 * _scem_venue_* meta keys, same markup, rendered in both places so
 * there's exactly one definition of what a "venue" is.
 */
final class LocationFields
{
    /**
     * @param bool $includeVenueName Skipped on the Venue post type's own screen, where the post title already is the name.
     */
    public static function render(array $values, bool $includeVenueName = true): void
    {
        ?>
        <table class="form-table scem-location-fields" role="presentation">
            <?php if ($includeVenueName) : ?>
                <tr>
                    <th scope="row"><label for="scem_venue_name">Venue name</label></th>
                    <td><input type="text" id="scem_venue_name" name="scem[venue_name]" class="regular-text" value="<?php echo \esc_attr($values['venue_name']); ?>"></td>
                </tr>
            <?php endif; ?>
            <tr>
                <th scope="row"><label for="scem_venue_address">Venue address</label></th>
                <td><input type="text" id="scem_venue_address" name="scem[venue_address]" class="regular-text" value="<?php echo \esc_attr($values['venue_address']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="scem_venue_town">Town/city</label></th>
                <td><input type="text" id="scem_venue_town" name="scem[venue_town]" class="regular-text" value="<?php echo \esc_attr($values['venue_town']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="scem_venue_postcode">Postcode</label></th>
                <td><input type="text" id="scem_venue_postcode" name="scem[venue_postcode]" class="regular-text" value="<?php echo \esc_attr($values['venue_postcode']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">Venue type</th>
                <td>
                    <label style="margin-right: 16px; display: inline-block;">
                        <input type="checkbox" name="scem[venue_indoor]" value="1" <?php \checked($values['venue_indoor']); ?>>
                        Indoor
                    </label>
                    <label style="display: inline-block;">
                        <input type="checkbox" name="scem[venue_outdoor]" value="1" <?php \checked($values['venue_outdoor']); ?>>
                        Outdoor
                    </label>
                    <p class="description">Both can be ticked for a venue with indoor and outdoor areas.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Disabled access</th>
                <td>
                    <label>
                        <input type="checkbox" name="scem[venue_disabled_access]" value="1" <?php \checked($values['venue_disabled_access']); ?>>
                        This venue has disabled access
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Map location</th>
                <td>
                    <div id="scem-map" class="scem-map" data-lat="<?php echo \esc_attr($values['venue_lat']); ?>" data-lng="<?php echo \esc_attr($values['venue_lng']); ?>"></div>
                    <p class="description">Search for the venue above the map, or click the map to drop/move the pin.</p>
                    <p>
                        <label>Lat <input type="text" inputmode="decimal" id="scem_venue_lat" name="scem[venue_lat]" value="<?php echo \esc_attr($values['venue_lat']); ?>" class="small-text"></label>
                        <label>Lng <input type="text" inputmode="decimal" id="scem_venue_lng" name="scem[venue_lng]" value="<?php echo \esc_attr($values['venue_lng']); ?>" class="small-text"></label>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * @return array<string, string|bool>
     */
    public static function readMeta(int $postId): array
    {
        return [
            'venue_name' => (string) \get_post_meta($postId, '_scem_venue_name', true),
            'venue_address' => (string) \get_post_meta($postId, '_scem_venue_address', true),
            'venue_town' => (string) \get_post_meta($postId, '_scem_venue_town', true),
            'venue_postcode' => (string) \get_post_meta($postId, '_scem_venue_postcode', true),
            'venue_lat' => (string) \get_post_meta($postId, '_scem_venue_lat', true),
            'venue_lng' => (string) \get_post_meta($postId, '_scem_venue_lng', true),
            'venue_indoor' => (bool) \get_post_meta($postId, '_scem_venue_indoor', true),
            'venue_outdoor' => (bool) \get_post_meta($postId, '_scem_venue_outdoor', true),
            'venue_disabled_access' => (bool) \get_post_meta($postId, '_scem_venue_disabled_access', true),
        ];
    }

    /**
     * @param array<string, mixed> $data The "scem" POST sub-array.
     */
    public static function save(int $postId, array $data): void
    {
        MetaField::saveText($postId, '_scem_venue_name', $data['venue_name'] ?? '');
        MetaField::saveText($postId, '_scem_venue_address', $data['venue_address'] ?? '');
        MetaField::saveText($postId, '_scem_venue_town', $data['venue_town'] ?? '');
        MetaField::saveText($postId, '_scem_venue_postcode', $data['venue_postcode'] ?? '');
        MetaField::saveValue($postId, '_scem_venue_lat', self::sanitizeCoordinate($data['venue_lat'] ?? ''));
        MetaField::saveValue($postId, '_scem_venue_lng', self::sanitizeCoordinate($data['venue_lng'] ?? ''));
        MetaField::saveValue($postId, '_scem_venue_indoor', ! empty($data['venue_indoor']) ? '1' : '');
        MetaField::saveValue($postId, '_scem_venue_outdoor', ! empty($data['venue_outdoor']) ? '1' : '');
        MetaField::saveValue($postId, '_scem_venue_disabled_access', ! empty($data['venue_disabled_access']) ? '1' : '');
    }

    /**
     * Deletes every _scem_venue_* meta key without requiring any
     * posted data — used when a saved venue reference takes over
     * from an event's own manual fields.
     */
    public static function clear(int $postId): void
    {
        foreach (['_scem_venue_name', '_scem_venue_address', '_scem_venue_town', '_scem_venue_postcode', '_scem_venue_lat', '_scem_venue_lng', '_scem_venue_indoor', '_scem_venue_outdoor', '_scem_venue_disabled_access'] as $key) {
            \delete_post_meta($postId, $key);
        }
    }

    public static function sanitizeCoordinate(string $value): string
    {
        if ($value === '' || ! \is_numeric($value)) {
            return '';
        }

        return (string) \round((float) $value, 6);
    }

    public static function enqueueMapAssets(): void
    {
        \wp_enqueue_style('scem-leaflet', SCEM_URL.'assets/vendor/leaflet/leaflet.css', [], '1.9.4');
        \wp_enqueue_style('scem-leaflet-geocoder', SCEM_URL.'assets/vendor/leaflet-control-geocoder/Control.Geocoder.css', [], '2.4.0');
        \wp_enqueue_style('scem-admin', SCEM_URL.'assets/css/admin.css', [], SCEM_VERSION);

        \wp_enqueue_script('scem-leaflet', SCEM_URL.'assets/vendor/leaflet/leaflet.js', [], '1.9.4', true);
        \wp_enqueue_script('scem-leaflet-geocoder', SCEM_URL.'assets/vendor/leaflet-control-geocoder/Control.Geocoder.js', ['scem-leaflet'], '2.4.0', true);
        \wp_enqueue_script('scem-event-meta-box', SCEM_URL.'assets/js/event-meta-box.js', ['scem-leaflet-geocoder'], SCEM_VERSION, true);
    }
}
