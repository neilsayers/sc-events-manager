<?php

namespace SCEventsManager\Frontend;

use SCEventsManager\Calendar\EventOccurrences;
use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;

/**
 * [sc_events] — a front-end listing across every configured event
 * type (narrow with type="gig,show"), also callable directly from a
 * theme template via the static render() method or the
 * scem_events_listing()/scem_the_events_listing() functions in
 * template-functions.php.
 */
final class EventListingShortcode implements Hookable
{
    public const SHORTCODE_TAG = 'sc_events';

    private const DEFAULT_ATTS = [
        'type' => '',
        'range' => 'future',
        'limit' => '10',
        'venue' => '',
        'taxonomy' => '',
        'term' => '',
        'show_cancelled' => 'false',
        'combine_multidate_events' => 'false',
    ];

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_shortcode(self::SHORTCODE_TAG, [$this, 'renderShortcode']);
        \add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function enqueueAssets(): void
    {
        \wp_enqueue_style('scem-frontend', SCEM_URL.'assets/css/frontend.css', [], SCEM_VERSION);
    }

    /**
     * @param array<string, mixed>|string $atts
     */
    public function renderShortcode($atts): string
    {
        $atts = \shortcode_atts(self::DEFAULT_ATTS, (array) $atts, self::SHORTCODE_TAG);

        return self::renderWith($atts, $this->settings);
    }

    /**
     * Callable directly from a theme template, e.g.:
     *   echo EventListingShortcode::render(['type' => 'gig', 'limit' => 5]);
     *
     * @param array<string, mixed> $atts
     */
    public static function render(array $atts): string
    {
        return self::renderWith($atts + self::DEFAULT_ATTS, new Settings());
    }

    /**
     * Raw occurrence data for the same attributes render()/the [sc_events]
     * shortcode accept, without the HTML step — the shared query behind
     * scem_get_events() and the REST endpoint (Frontend\EventsRestController),
     * so both stay in lockstep with the shortcode's own filtering/sorting/
     * limiting instead of re-implementing it.
     *
     * @param array<string, mixed> $atts
     * @return array<int, array<string, mixed>>
     */
    public static function query(array $atts, ?Settings $settings = null): array
    {
        $settings ??= new Settings();
        $merged = $atts + self::DEFAULT_ATTS;

        return (new EventListingQuery(new EventOccurrences($settings)))->run($merged);
    }

    /**
     * @param array<string, mixed> $atts
     */
    private static function renderWith(array $atts, Settings $settings): string
    {
        $occurrences = self::query($atts, $settings);

        if ($occurrences === []) {
            return '<p class="scem-listing-empty">No events found.</p>';
        }

        $fields = $settings->listingFields();
        $total = \count($occurrences);

        \ob_start();
        ?>
        <div class="scem-listing">
            <?php foreach ($occurrences as $index => $occurrence) : ?>
                <?php OccurrenceCard::render($occurrence, $fields, $index, $total); ?>
            <?php endforeach; ?>
        </div>
        <?php

        return (string) \ob_get_clean();
    }
}
