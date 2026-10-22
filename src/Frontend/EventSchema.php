<?php

namespace SCEventsManager\Frontend;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\EventMeta;

/**
 * Schema.org "Event" structured data (JSON-LD) in the <head> of a
 * single event's page — what lets Google show an event rich result
 * (dates, venue, price, ticket link) for it.
 *
 * Everything is derived from the event's saved fields, so there is
 * nothing for an editor to fill in separately: scem_get_event()'s
 * schedule supplies the dates, status, venue and price rows, and
 * EventMeta the organiser. A multi-day or recurring event emits one
 * Event per upcoming date, which is what Google asks for.
 *
 * Hooks for themes and other plugins:
 *   scem_output_schema (bool, WP_Post)   return false to switch it off,
 *                                        e.g. when an SEO plugin owns schema.
 *   scem_schema_event (array, array, WP_Post)
 *                                        adjust one Event before output
 *                                        (second arg is the occurrence row).
 *   scem_schema_currency_code (string, string $symbol)
 *                                        ISO 4217 code for a custom currency symbol.
 */
final class EventSchema implements Hookable
{
    /** Google ignores more than a handful of dates per page in practice. */
    private const MAX_EVENTS = 25;

    private const CURRENCY_CODES = ['£' => 'GBP', '€' => 'EUR', '$' => 'USD'];

    private const STATUS_URLS = [
        'scheduled' => 'https://schema.org/EventScheduled',
        'sold_out' => 'https://schema.org/EventScheduled',
        'cancelled' => 'https://schema.org/EventCancelled',
        'postponed' => 'https://schema.org/EventPostponed',
    ];

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('wp_head', [$this, 'output']);
    }

    public function output(): void
    {
        if (! \is_singular() || ! \function_exists('scem_get_event')) {
            return;
        }

        $post = \get_queried_object();

        if (! $post instanceof \WP_Post || $this->settings->getEventType($post->post_type) === null) {
            return;
        }

        if (! \apply_filters('scem_output_schema', true, $post)) {
            return;
        }

        $occurrences = \scem_get_event($post->ID);

        if ($occurrences === []) {
            return;
        }

        $today = \wp_date('Y-m-d');
        $upcoming = \array_values(\array_filter($occurrences, static fn (array $o): bool => $o['date'] >= $today));
        // A finished event's page is still valid, so describe it as it was.
        $occurrences = \array_slice($upcoming ?: $occurrences, 0, self::MAX_EVENTS);

        $organiser = EventMeta::read($post->ID);
        $events = [];

        foreach ($occurrences as $occurrence) {
            $event = \apply_filters('scem_schema_event', $this->buildEvent($post, $occurrence, $organiser), $occurrence, $post);

            if (\is_array($event) && $event !== []) {
                $events[] = $event;
            }
        }

        if ($events === []) {
            return;
        }

        $data = \count($events) === 1
            ? ['@context' => 'https://schema.org'] + $events[0]
            : ['@context' => 'https://schema.org', '@graph' => $events];

        echo '<script type="application/ld+json">'
            .\wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)
            ."</script>\n";
    }

    /**
     * @param array<string, mixed> $o    One scem_get_event() row
     * @param array<string, mixed> $meta EventMeta::read()
     *
     * @return array<string, mixed>
     */
    private function buildEvent(\WP_Post $post, array $o, array $meta): array
    {
        $event = [
            '@type' => 'Event',
            'name' => \wp_strip_all_tags((string) $o['title']),
            'url' => (string) $o['view_url'],
            'startDate' => $this->dateTime($o['date'], $o['start_time']),
            'eventStatus' => self::STATUS_URLS[$o['status']] ?? self::STATUS_URLS['scheduled'],
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
        ];

        if ($o['end_time'] !== '') {
            $event['endDate'] = $this->dateTime($o['date'], $o['end_time']);
        }

        $description = \trim(\wp_strip_all_tags((string) $o['excerpt']));

        if ($description !== '') {
            $event['description'] = $description;
        }

        $image = \get_the_post_thumbnail_url($post, 'full');

        if ($image) {
            $event['image'] = [$image];
        }

        $location = $this->location($o);

        if ($location !== []) {
            $event['location'] = $location;
        }

        $offers = $this->offers($o);

        if ($offers !== []) {
            $event['offers'] = $offers;
        }

        if ($meta['organiser_name'] !== '') {
            $event['organizer'] = \array_filter([
                '@type' => 'Organization',
                'name' => $meta['organiser_name'],
                'email' => $meta['organiser_email'],
                'telephone' => $meta['organiser_phone'],
            ], static fn ($v): bool => $v !== '');
        }

        return $event;
    }

    /**
     * ISO 8601 in the site's own timezone, so "7pm" stays 7pm in
     * BST. A date with no time set is emitted as a bare date.
     */
    private function dateTime(string $date, string $time): string
    {
        if ($time === '') {
            return $date;
        }

        return (new \DateTimeImmutable("{$date} {$time}", \wp_timezone()))->format('c');
    }

    /**
     * @param array<string, mixed> $o
     *
     * @return array<string, mixed>
     */
    private function location(array $o): array
    {
        if ($o['venue_name'] === '' && $o['venue_address'] === '') {
            return [];
        }

        $address = \array_filter([
            '@type' => 'PostalAddress',
            'streetAddress' => $o['venue_address'],
            'addressLocality' => $o['venue_town'],
            'postalCode' => $o['venue_postcode'],
            'addressCountry' => \wp_timezone_string() === 'Europe/London' ? 'GB' : '',
        ], static fn ($v): bool => $v !== '');

        $place = ['@type' => 'Place', 'name' => $o['venue_name'] ?: $o['venue_address']];

        if (\count($address) > 1) {
            $place['address'] = $address;
        }

        if ($o['venue_lat'] !== '' && $o['venue_lng'] !== '') {
            $place['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => (float) $o['venue_lat'],
                'longitude' => (float) $o['venue_lng'],
            ];
        }

        return $place;
    }

    /**
     * One Offer per price row. Rows without a currency we can name
     * are skipped rather than guessed — a wrong currency is worse
     * than no price.
     *
     * @param array<string, mixed> $o
     *
     * @return array<int, array<string, mixed>>
     */
    private function offers(array $o): array
    {
        $symbol = $this->settings->currency();
        $currency = self::CURRENCY_CODES[$symbol] ?? (\preg_match('/^[A-Z]{3}$/', $symbol) ? $symbol : '');
        $currency = (string) \apply_filters('scem_schema_currency_code', $currency, $symbol);

        if ($currency === '' || $o['price_rows'] === []) {
            return [];
        }

        $availability = $o['status'] === 'sold_out' ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock';
        $offers = [];

        foreach ($o['price_rows'] as $row) {
            if (! $row['free'] && $row['amount'] === '') {
                continue;
            }

            $offer = [
                '@type' => 'Offer',
                'name' => $row['label'],
                'price' => $row['free'] ? '0' : (string) $row['amount'],
                'priceCurrency' => $currency,
                'availability' => $availability,
            ];

            if ($o['ticket_url'] !== '') {
                $offer['url'] = $o['ticket_url'];
            }

            $offers[] = $offer;
        }

        return $offers;
    }
}
