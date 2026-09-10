<?php

namespace SCEventsManager\MetaBoxes;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\EventMeta;
use SCEventsManager\Support\TicketPrices;

/**
 * The ticket link, the price rows, and the conditions note.
 *
 * Unlike the other secondary boxes this one sits in the "normal"
 * context rather than the sidebar: a price row is four controls
 * wide, which a ~280px sidebar column can't lay out without
 * wrapping every one of them onto its own line.
 *
 * Saving is still handled entirely by
 * EventDetailsMetaBox::saveMetaBox(), see that class's docblock.
 */
final class TicketsMetaBox implements Hookable
{
    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('add_meta_boxes', [$this, 'addMetaBox']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    private function isEventPostType(string $postType): bool
    {
        return $this->settings->getEventType($postType) !== null;
    }

    public function addMetaBox(): void
    {
        $eventPostTypes = \array_keys($this->settings->allEventTypes());

        // add_meta_box() treats an empty $screen array as "use the
        // current screen" rather than "no screens" — without this
        // guard, zero configured event types means this box would
        // register itself on whatever post type is being edited.
        if ($eventPostTypes === []) {
            return;
        }

        \add_meta_box(
            'scem_tickets',
            'Tickets & Prices',
            [$this, 'render'],
            $eventPostTypes,
            'normal',
            'default'
        );
    }

    public function enqueueAssets(string $hook): void
    {
        if (! \in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        if (! $this->isEventPostType(\get_current_screen()->post_type ?? '')) {
            return;
        }

        \wp_enqueue_style('scem-admin', SCEM_URL.'assets/css/admin.css', [], SCEM_VERSION);
        \wp_enqueue_script('scem-ticket-prices', SCEM_URL.'assets/js/ticket-prices.js', [], SCEM_VERSION, true);
    }

    public function render(\WP_Post $post): void
    {
        $meta = EventMeta::read($post->ID);
        $rows = $meta['ticket_prices'];
        $currency = $this->settings->currency();
        ?>
        <div class="scem-tickets">
            <p class="scem-tickets-field">
                <label for="scem_ticket_url"><strong>Ticket/booking link</strong></label><br>
                <input type="url" id="scem_ticket_url" name="scem[ticket_url]" class="large-text" value="<?php echo \esc_attr($meta['ticket_url']); ?>" placeholder="https://...">
            </p>

            <p class="scem-tickets-field"><strong>Prices</strong></p>

            <table class="scem-price-table widefat" id="scem_price_table">
                <thead>
                    <tr>
                        <th scope="col" class="scem-price-col-type">Ticket type</th>
                        <th scope="col" class="scem-price-col-age">Ages from</th>
                        <th scope="col" class="scem-price-col-age">Under</th>
                        <th scope="col" class="scem-price-col-amount">Price (<?php echo \esc_html($currency); ?>)</th>
                        <th scope="col" class="scem-price-col-free">Free</th>
                        <th scope="col" class="scem-price-col-remove"><span class="screen-reader-text">Remove</span></th>
                    </tr>
                </thead>
                <tbody id="scem_price_rows">
                    <?php
                    foreach ($rows as $index => $row) {
                        $this->renderRow($index, $row);
                    }
                    ?>
                </tbody>
            </table>

            <p class="scem-price-empty" id="scem_price_empty"<?php echo $rows === [] ? '' : ' hidden'; ?>>
                No prices set — this event will show without a price. Add a row, or add one marked Free, to say so explicitly.
            </p>

            <p>
                <button type="button" class="button" id="scem_add_price_row">Add price</button>
            </p>

            <p class="scem-tickets-field">
                <label for="scem_ticket_notes"><strong>Ticket conditions</strong> (optional)</label><br>
                <textarea id="scem_ticket_notes" name="scem[ticket_notes]" class="large-text" rows="2" placeholder="e.g. Up to two children admitted free when accompanied by an adult."><?php echo \esc_textarea($meta['ticket_notes']); ?></textarea>
                <span class="description">Shown under the price table on the event's own page. Not shown on listing cards.</span>
            </p>

            <?php $this->renderRowTemplate(); ?>
        </div>
        <?php
    }

    /**
     * @param array<string, mixed> $row
     */
    private function renderRow(int $index, array $row): void
    {
        $name = 'scem[ticket_prices]['.$index.']';
        ?>
        <tr class="scem-price-row">
            <td>
                <select name="<?php echo \esc_attr($name); ?>[type]" class="scem-price-type">
                    <?php foreach (TicketPrices::TYPES as $key => $label) { ?>
                        <option value="<?php echo \esc_attr($key); ?>"<?php \selected($row['type'], $key); ?>><?php echo \esc_html($label); ?></option>
                    <?php } ?>
                </select>
            </td>
            <td><input type="number" min="0" max="120" step="1" name="<?php echo \esc_attr($name); ?>[age_from]" value="<?php echo \esc_attr($row['age_from']); ?>" class="small-text" placeholder="—"></td>
            <td><input type="number" min="0" max="120" step="1" name="<?php echo \esc_attr($name); ?>[age_under]" value="<?php echo \esc_attr($row['age_under']); ?>" class="small-text" placeholder="—"></td>
            <td><input type="number" min="0" step="0.01" name="<?php echo \esc_attr($name); ?>[amount]" value="<?php echo \esc_attr($row['amount']); ?>" class="small-text scem-price-amount"<?php echo $row['free'] ? ' disabled' : ''; ?>></td>
            <td class="scem-price-col-free"><input type="checkbox" name="<?php echo \esc_attr($name); ?>[free]" value="1" class="scem-price-free"<?php \checked($row['free']); ?>></td>
            <td class="scem-price-col-remove"><button type="button" class="button-link scem-price-remove" aria-label="Remove this price">&times;</button></td>
        </tr>
        <?php
    }

    /**
     * The blank row the Add button clones. Kept in a <template> and
     * rendered by the same method as the real rows so the two can't
     * drift apart — __INDEX__ is swapped for a real index in JS.
     */
    private function renderRowTemplate(): void
    {
        \ob_start();
        $this->renderRow(0, ['type' => 'adult', 'age_from' => '', 'age_under' => '', 'free' => false, 'amount' => '']);
        $markup = (string) \ob_get_clean();

        echo '<template id="scem_price_row_template">'
            .\str_replace('scem[ticket_prices][0]', 'scem[ticket_prices][__INDEX__]', $markup)
            .'</template>';
    }
}
