<?php

namespace SCEventsManager\Admin;

use SCEventsManager\Calendar\EventOccurrences;
use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Frontend\EventListingShortcode;
use SCEventsManager\Frontend\ListingFields;
use SCEventsManager\Settings\Settings;

/**
 * "Documentation" — a plain reference for every way this plugin's
 * event data can be consumed: the PHP function API, the REST
 * endpoint, and the [sc_events] shortcode, plus the occurrence data
 * field reference (pulled live from EventOccurrences::FIELD_DESCRIPTIONS
 * so it can't drift from what the code actually returns).
 *
 * No settings live here — it's read-only, so there's nothing to
 * register_setting() or admin_post_ handle.
 */
final class DocumentationPage implements Hookable
{
    private const PAGE_SLUG = 'scem-documentation';

    /**
     * Attributes shared by the shortcode, scem_get_events(), and the
     * REST endpoint's query params — described once here rather than
     * three times, since all three accept exactly the same set (the
     * REST endpoint's registered args carry the same text, kept in
     * sync by hand since \WP_REST_Server needs its own array shape).
     *
     * @var array<string, string>
     */
    private const SHARED_ATTRIBUTES = [
        'type' => 'Comma-separated event type post_type keys, e.g. "gig,show". Empty (the default) means every configured type.',
        'range' => '"future" (default), "past", "month", or "week".',
        'limit' => 'Maximum occurrences to return. Default 10; 0 means no limit.',
        'venue' => 'A Venue post ID to filter by. Default: none.',
        'taxonomy' => 'A custom taxonomy slug to filter by, paired with term. Default: none.',
        'term' => 'The term slug within taxonomy to filter by. Default: none.',
        'show_cancelled' => '"true" to include events marked Cancelled. Default "false".',
        'combine_multidate_events' => '"true" to collapse a multi-day event into one row with a date range instead of one row per date. Default "false".',
    ];

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'registerMenu']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(): void
    {
        if (($_GET['page'] ?? '') !== self::PAGE_SLUG) {
            return;
        }

        \wp_enqueue_style('scem-admin', SCEM_URL.'assets/css/admin.css', [], SCEM_VERSION);
    }

    public function registerMenu(): void
    {
        \add_submenu_page(
            'scem-settings',
            'Documentation',
            'Documentation',
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
        ?>
        <div class="wrap scem-docs">
            <h1>SC Events Manager — Documentation</h1>

            <p>
                Three ways to pull event data into a theme or an external tool, all backed by the same query
                (<code>EventListingShortcode::query()</code>) — filtering, sorting and limiting behave identically
                whichever one you use.
            </p>

            <?php $this->renderFunctionSection(); ?>
            <?php $this->renderRestSection(); ?>
            <?php $this->renderShortcodeSection(); ?>
            <?php $this->renderFieldReferenceSection(); ?>
        </div>
        <?php
    }

    private function renderFunctionSection(): void
    {
        ?>
        <h2>PHP: <code>scem_get_events()</code></h2>
        <p>
            For theme code on this same site — no HTTP round trip, and you render your own markup instead of the
            plugin's. This is the plugin's stable PHP contract: the classes underneath it
            (<code>EventListingQuery</code>, <code>EventOccurrences</code>) can change between versions, but this
            function's parameters and return shape won't, within a major version.
        </p>
        <pre class="scem-docs-code">$events = scem_get_events([
    'type'  =&gt; 'gig',
    'range' =&gt; 'future',
    'limit' =&gt; 3,
]);

foreach ($events as $event) {
    echo esc_html($event['title']) . ' — ' . esc_html($event['date']);
}</pre>
        <p>Also available: <code>scem_events_listing($atts)</code> (returns the plugin's own rendered HTML as a string) and <code>scem_the_events_listing($atts)</code> (echoes it) — use these instead if you want the plugin's default card markup rather than building your own.</p>

        <?php $this->renderAttributesTable(); ?>
        <?php
    }

    private function renderRestSection(): void
    {
        ?>
        <h2>REST: <code>GET /wp-json/scem/v1/events</code></h2>
        <p>
            For anything outside this site's own PHP — a decoupled front end, another site, a build step. Public
            and read-only; no authentication needed, since every field it returns is already visible on the event's
            own front-end page.
        </p>
        <p>
            <code><?php echo \esc_html(\rest_url('scem/v1/events')); ?>?type=gig&amp;limit=3</code>
        </p>
        <p>
            <strong>v1 is a promise:</strong> existing fields won't be renamed or removed within it. A field can be
            added; a genuinely breaking change gets its own <code>scem/v2</code> route instead, so anything already
            built against v1 keeps working.
        </p>

        <?php $this->renderAttributesTable(); ?>

        <p>Response shape:</p>
        <pre class="scem-docs-code">{
  "events": [ { "title": "…", "date": "2026-09-12", … } ],
  "total": 1
}</pre>
        <p class="description">Identical to <code>scem_get_events()</code>'s field set, minus <code>edit_url</code> — a wp-admin link has no business in a public response. See the data reference below for what each field contains.</p>
        <?php
    }

    private function renderShortcodeSection(): void
    {
        ?>
        <h2>Shortcode: <code>[<?php echo \esc_html(EventListingShortcode::SHORTCODE_TAG); ?>]</code></h2>
        <p>For post/page content — renders the plugin's own card markup directly, e.g. <code>[sc_events type="gig" limit="3"]</code>.</p>

        <?php $this->renderAttributesTable(); ?>
        <?php
    }

    private function renderAttributesTable(): void
    {
        ?>
        <table class="widefat striped" style="max-width: 900px;">
            <thead>
                <tr>
                    <th>Attribute</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (self::SHARED_ATTRIBUTES as $attribute => $description) : ?>
                    <tr>
                        <td><code><?php echo \esc_html($attribute); ?></code></td>
                        <td><?php echo \esc_html($description); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderFieldReferenceSection(): void
    {
        $enabledFields = $this->settings->listingFields();
        ?>
        <h2>Data reference — occurrence fields</h2>
        <p>
            Every field <code>scem_get_events()</code>, the REST endpoint, and the shortcode's own card markup can
            draw on. Pulled directly from <code>EventOccurrences::FIELD_DESCRIPTIONS</code>, so this list can't go
            out of date with the code.
        </p>

        <table class="widefat striped" style="max-width: 900px;">
            <thead>
                <tr>
                    <th>Field</th>
                    <th>Description</th>
                    <th>Shown by default in <code>[sc_events]</code>'s own card?</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (EventOccurrences::FIELD_DESCRIPTIONS as $field => $description) : ?>
                    <tr>
                        <td><code><?php echo \esc_html($field); ?></code></td>
                        <td><?php echo \esc_html($description); ?></td>
                        <td><?php echo \esc_html($this->cardVisibility($field, $enabledFields)); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr>
                    <td><code>end_date</code></td>
                    <td>Only present when called with <code>combine_multidate_events=true</code> — the last date in a multi-day event's range.</td>
                    <td>—</td>
                </tr>
            </tbody>
        </table>

        <p class="description">
            "Shown by default" reflects the current
            <a href="<?php echo \esc_url(\admin_url('admin.php?page=scem-listing-settings')); ?>">Listing Display</a>
            settings on this site — <code>title</code> is always shown regardless, and every field remains available
            to <code>scem_get_events()</code>/the REST endpoint whether or not it's enabled there.
        </p>
        <?php
    }

    /**
     * @param string[] $enabledFields
     */
    private function cardVisibility(string $field, array $enabledFields): string
    {
        if ($field === 'title') {
            return 'Always';
        }

        if (! isset(ListingFields::AVAILABLE[$field])) {
            return '—'; // Not one of the toggleable card fields (e.g. event_id, view_url) — always available to data, never a card option.
        }

        return \in_array($field, $enabledFields, true) ? 'Yes' : 'No (not enabled — see Listing Display)';
    }
}
