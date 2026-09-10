<?php

namespace SCEventsManager\Admin;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Frontend\ListingFields;
use SCEventsManager\Settings\Settings;

/**
 * Checkbox UI for which fields the [sc_events] listing shortcode
 * shows, and in what order — see Frontend\ListingFields for why this
 * is stored as an ordered list rather than independent booleans. A
 * future drag-and-drop reorder tool would replace only this screen's
 * markup, not the data it reads/writes.
 */
final class ListingSettingsPage implements Hookable
{
    private const PAGE_SLUG = 'scem-listing-settings';
    private const SAVE_ACTION = 'scem_save_listing_fields';

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'registerMenu']);
        \add_action('admin_post_'.self::SAVE_ACTION, [$this, 'handleSave']);
    }

    public function registerMenu(): void
    {
        \add_submenu_page(
            'scem-settings',
            'Listing Display',
            'Listing Display',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (! \current_user_can('manage_options')) {
            return;
        }

        $enabled = $this->settings->listingFields();
        ?>
        <div class="wrap">
            <h1>Listing Display</h1>
            <p>
                Choose which details show on each event in the <code>[sc_events]</code> front-end listing shortcode,
                and the currency ticket prices are shown in. The title is always shown.
            </p>

            <?php \settings_errors('scem_listing_fields'); ?>

            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                <?php \wp_nonce_field(self::SAVE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo \esc_attr(self::SAVE_ACTION); ?>">

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="scem_currency">Currency symbol</label></th>
                        <td>
                            <input type="text" id="scem_currency" name="currency" class="small-text" maxlength="3" value="<?php echo \esc_attr($this->settings->currency()); ?>">
                            <p class="description">Prefixed to every ticket price, e.g. <code>£10</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Fields</th>
                        <td>
                            <?php foreach (ListingFields::AVAILABLE as $key => $label) : ?>
                                <label style="display: block; margin-bottom: 6px;">
                                    <input type="checkbox" name="fields[]" value="<?php echo \esc_attr($key); ?>" <?php \checked(\in_array($key, $enabled, true)); ?>>
                                    <?php echo \esc_html($label); ?>
                                </label>
                            <?php endforeach; ?>
                            <p class="description">
                                Shown in this fixed order for now — a drag-and-drop way to reorder them is planned.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php \submit_button('Save changes'); ?>
            </form>
        </div>
        <?php
    }

    public function handleSave(): void
    {
        if (! \current_user_can('manage_options')) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'sc-events-manager'));
        }

        \check_admin_referer(self::SAVE_ACTION);

        $fields = \array_map('sanitize_key', (array) ($_POST['fields'] ?? []));
        $this->settings->saveListingFields($fields);
        $this->settings->saveCurrency(\sanitize_text_field(\wp_unslash((string) ($_POST['currency'] ?? '£'))));

        \add_settings_error('scem_listing_fields', 'scem_saved', \__('Settings saved.', 'sc-events-manager'), 'success');
        \set_transient('settings_errors', \get_settings_errors(), 30);

        \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG.'&settings-updated=true'));
        exit;
    }
}
