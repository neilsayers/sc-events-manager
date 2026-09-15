<?php

namespace SCEventsManager\Calendar;

use SCEventsManager\MetaBoxes\StatusMetaBox;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\LocationFields;
use SCEventsManager\Support\TicketPrices;

/**
 * Expands stored event postmeta into individual calendar occurrences
 * for a date range — a single-day event is one occurrence, a
 * multi-day event is one occurrence per date in its range, and a
 * recurring event is expanded from its rule (daily/weekly/monthly)
 * up to its recurrence_until date. This is deliberately its own raw
 * postmeta reader rather than reusing Support\EventMeta: that class
 * shapes hour/minute pairs for <select> rendering, which is exactly
 * the wrong shape for date arithmetic here.
 */
final class EventOccurrences
{
    /**
     * One entry per key in the occurrence array built by forRange()
     * below — kept next to it deliberately, so adding/renaming a field
     * there is a one-line reminder to update its description here too.
     * This is what Admin\DocumentationPage renders as the "Data
     * reference" table: the single source of truth for what
     * scem_get_events()/the REST endpoint/OccurrenceCard all consume,
     * rather than a hand-maintained copy that can drift from the code.
     *
     * end_date isn't listed: it only appears when
     * EventListingQuery::run() is called with
     * combine_multidate_events=true, which adds it after the fact —
     * see EventListingQuery::combineMultidateEvents().
     *
     * @var array<string, string>
     */
    public const FIELD_DESCRIPTIONS = [
        'event_id' => 'The event\'s post ID. Stable across its occurrences — a recurring/multi-day event repeats this ID once per row.',
        'post_type' => 'The event type\'s post_type key (e.g. "gig").',
        'type_label' => 'The event type\'s singular label (e.g. "Gig") — see Settings::allEventTypes().',
        'title' => 'The event\'s title.',
        'excerpt' => 'The event\'s excerpt (falls back to an auto-generated one from its content if none was set).',
        'featured_image_url' => 'Medium-size featured image URL, or "" if it has none.',
        'date' => 'This occurrence\'s date, Y-m-d.',
        'start_time' => 'Start time, H:i (24-hour), or "" if none was set.',
        'end_time' => 'End time, H:i (24-hour), or "" if none was set.',
        'is_multi_day_range' => 'True if this occurrence is one date within a multi-day event\'s range.',
        'is_recurring' => 'True if this occurrence was expanded from a recurrence rule rather than being the event\'s own single stored date.',
        'status' => 'The raw event status key (e.g. "scheduled", "cancelled") — see MetaBoxes\StatusMetaBox::CHOICES for the full set.',
        'status_label' => 'The human-readable label for status.',
        'venue_name' => 'The event\'s venue name, or "" if none is set.',
        'venue_address' => 'The venue\'s street address, or "". Meant for the event\'s own page, not for cards.',
        'venue_town' => 'The venue\'s town/city, or "".',
        'venue_postcode' => 'The venue\'s postcode, or "".',
        'venue_lat' => 'The venue\'s map latitude, or "" if it has never been pinned on the map.',
        'venue_lng' => 'The venue\'s map longitude, or "" if it has never been pinned on the map.',
        'venue_indoor' => 'True if the venue is (or includes) an indoor space.',
        'venue_outdoor' => 'True if the venue is (or includes) an outdoor space.',
        'venue_disabled_access' => 'True if the venue has disabled access.',
        'price' => 'Short display price derived from price_rows — "" (unpriced), "Free", "£20", or "From £5". Safe to print in a fixed-width card.',
        'price_rows' => 'array<int, array{type, type_label, label, age_from, age_under, free, amount, display}> — every ticket tier, in canonical order (Adult first). label is the display name with its age qualifier, e.g. "Child (under 5)"; display is the formatted price or "Free".',
        'price_from' => 'float|null — the cheapest paid amount, for sorting and price filters. null when the event is free or unpriced.',
        'price_note' => 'One short "... free" line to sit under price on a card (e.g. "Under 5s free"), or "" when there isn\'t one. Never more than one.',
        'ticket_notes' => 'Free-text conditions attached to the prices (e.g. "... free if accompanied by an adult"), or "". Meant for the event\'s own page, not for cards.',
        'ticket_url' => 'The ticket/booking link, or "" if none was set.',
        'terms_by_taxonomy' => 'array<string, string[]> — term slugs the event is assigned to, keyed by taxonomy.',
        'edit_url' => 'The wp-admin edit-post link. PHP-only (scem_get_events()) — stripped from the public REST response.',
        'view_url' => 'The event\'s own front-end permalink.',
    ];

    private const WEEKDAY_MAP = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    /**
     * Defensive cap on how many dates a single event can expand into
     * for one request — guards against a mistyped recurrence_until
     * decades in the future generating an enormous result set.
     */
    private const MAX_OCCURRENCES_PER_EVENT = 500;

    public function __construct(private Settings $settings)
    {
    }

    /**
     * @param array{post_types?: string[]|null, post_status?: string, venue_id?: int|null, taxonomy?: string|null, term?: string|null, include_cancelled?: bool} $args
     * @return array<int, array<string, mixed>> Occurrences sorted by date then start time.
     */
    public function forRange(\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, array $args = []): array
    {
        $args += [
            'post_types' => null,
            'post_status' => 'any',
            'venue_id' => null,
            'taxonomy' => null,
            'term' => null,
            'include_cancelled' => true,
        ];

        $eventTypes = $this->settings->allEventTypes();

        if ($eventTypes === []) {
            return [];
        }

        $postTypes = $args['post_types'] === null
            ? \array_keys($eventTypes)
            : \array_values(\array_intersect($args['post_types'], \array_keys($eventTypes)));

        if ($postTypes === []) {
            return [];
        }

        $queryArgs = [
            'post_type' => $postTypes,
            'post_status' => $args['post_status'],
            'posts_per_page' => -1,
            'meta_query' => [[
                'key' => '_scem_start_date',
                'value' => $rangeEnd->format('Y-m-d'),
                'compare' => '<=',
                'type' => 'DATE',
            ]],
        ];

        if (! empty($args['venue_id'])) {
            $queryArgs['meta_query'][] = [
                'key' => '_scem_venue_id',
                'value' => (int) $args['venue_id'],
            ];
        }

        if (! empty($args['taxonomy']) && ! empty($args['term'])) {
            $queryArgs['tax_query'] = [[
                'taxonomy' => $args['taxonomy'],
                'field' => 'slug',
                'terms' => $args['term'],
            ]];
        }

        $posts = \get_posts($queryArgs);

        $occurrences = [];

        foreach ($posts as $post) {
            $eventType = $eventTypes[$post->post_type] ?? null;
            $typeLabel = $eventType['label_singular'] ?? $post->post_type;

            foreach ($this->occurrencesForPost($post, $typeLabel, $rangeStart, $rangeEnd, $args['include_cancelled']) as $occurrence) {
                $occurrences[] = $occurrence;
            }
        }

        \usort(
            $occurrences,
            static fn (array $a, array $b): int => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]
        );

        return $occurrences;
    }

    /**
     * All of one event's own occurrences within a date range — the
     * same per-occurrence shape forRange() returns, but scoped to a
     * single known post instead of a get_posts() query across every
     * configured event type. Used by scem_get_event() for a
     * single-event template, which needs one event's whole schedule
     * (every date of a multi-day range, every future hit of a
     * recurring rule) rather than a cross-event listing.
     *
     * @return array<int, array<string, mixed>> Occurrences sorted by date then start time.
     */
    public function forPost(int $postId, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, bool $includeCancelled = true): array
    {
        $post = \get_post($postId);

        if (! $post instanceof \WP_Post) {
            return [];
        }

        $eventType = $this->settings->allEventTypes()[$post->post_type] ?? null;
        $typeLabel = $eventType['label_singular'] ?? $post->post_type;

        $occurrences = $this->occurrencesForPost($post, $typeLabel, $rangeStart, $rangeEnd, $includeCancelled);

        \usort(
            $occurrences,
            static fn (array $a, array $b): int => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]
        );

        return $occurrences;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function occurrencesForPost(\WP_Post $post, string $typeLabel, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, bool $includeCancelled): array
    {
        $raw = $this->readRaw($post->ID);

        if (! $includeCancelled && $raw['status'] === 'cancelled') {
            return [];
        }

        $termSlugsByTaxonomy = $this->resolveTerms($post->ID, $post->post_type);
        $venueDetails = $this->resolveVenueDetails($raw, $post->ID);
        $occurrences = [];

        foreach ($this->occurrenceDates($raw, $rangeStart, $rangeEnd) as $occurrenceDate) {
            $occurrences[] = [
                'event_id' => $post->ID,
                'post_type' => $post->post_type,
                'type_label' => $typeLabel,
                'title' => \get_the_title($post),
                'excerpt' => \get_the_excerpt($post),
                'featured_image_url' => \get_the_post_thumbnail_url($post, 'medium') ?: '',
                'date' => $occurrenceDate['date'],
                'start_time' => $occurrenceDate['start_time'],
                'end_time' => $occurrenceDate['end_time'],
                'is_multi_day_range' => $occurrenceDate['is_multi_day_range'],
                'is_recurring' => $occurrenceDate['is_recurring'],
                'status' => $raw['status'],
                'status_label' => StatusMetaBox::CHOICES[$raw['status']] ?? \ucfirst($raw['status']),
                'venue_name' => $this->resolveVenueName($raw),
                'venue_address' => $venueDetails['venue_address'],
                'venue_town' => $venueDetails['venue_town'],
                'venue_postcode' => $venueDetails['venue_postcode'],
                'venue_lat' => $venueDetails['venue_lat'],
                'venue_lng' => $venueDetails['venue_lng'],
                'venue_indoor' => $venueDetails['venue_indoor'],
                'venue_outdoor' => $venueDetails['venue_outdoor'],
                'venue_disabled_access' => $venueDetails['venue_disabled_access'],
                'price' => $raw['price'],
                'price_rows' => $raw['price_rows'],
                'price_from' => $raw['price_from'],
                'price_note' => $raw['price_note'],
                'ticket_notes' => $raw['ticket_notes'],
                'ticket_url' => $raw['ticket_url'],
                'terms_by_taxonomy' => $termSlugsByTaxonomy,
                'edit_url' => (string) \get_edit_post_link($post->ID, 'raw'),
                'view_url' => (string) \get_permalink($post->ID),
            ];
        }

        return $occurrences;
    }

    /**
     * @return array<int, array{date: string, start_time: string, end_time: string}>
     */
    private function occurrenceDates(array $raw, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): array
    {
        $startDate = $this->toDate($raw['start_date']);

        if ($startDate === null) {
            return [];
        }

        $defaultTimes = ['start_time' => $raw['start_time'], 'end_time' => $raw['end_time']];

        if ($raw['is_one_day']) {
            if ($raw['recurrence_frequency'] !== '') {
                return $this->expandRecurrence($startDate, $raw, $rangeStart, $rangeEnd, $defaultTimes);
            }

            if ($startDate < $rangeStart || $startDate > $rangeEnd) {
                return [];
            }

            return [['date' => $startDate->format('Y-m-d'), 'is_multi_day_range' => false, 'is_recurring' => false] + $defaultTimes];
        }

        return $this->expandDateRange($startDate, $raw, $rangeStart, $rangeEnd, $defaultTimes);
    }

    /**
     * @return array<int, array{date: string, start_time: string, end_time: string}>
     */
    private function expandDateRange(\DateTimeImmutable $startDate, array $raw, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, array $defaultTimes): array
    {
        $endDate = $this->toDate($raw['end_date']) ?? $startDate;

        if ($endDate < $rangeStart || $startDate > $rangeEnd) {
            return [];
        }

        $cursor = $startDate > $rangeStart ? $startDate : $rangeStart;
        $last = $endDate < $rangeEnd ? $endDate : $rangeEnd;
        $occurrences = [];

        while ($cursor <= $last) {
            $dateKey = $cursor->format('Y-m-d');
            $override = $raw['different_times_per_date'] ? ($raw['date_times'][$dateKey] ?? null) : null;

            $occurrences[] = [
                'date' => $dateKey,
                'start_time' => $override['start'] ?? $defaultTimes['start_time'],
                'end_time' => $override['end'] ?? $defaultTimes['end_time'],
                'is_multi_day_range' => true,
                'is_recurring' => false,
            ];

            $cursor = $cursor->modify('+1 day');
        }

        return $occurrences;
    }

    /**
     * @return array<int, array{date: string, start_time: string, end_time: string}>
     */
    private function expandRecurrence(\DateTimeImmutable $startDate, array $raw, \DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd, array $defaultTimes): array
    {
        $until = $this->toDate($raw['recurrence_until']) ?? $startDate;
        $effectiveEnd = $until < $rangeEnd ? $until : $rangeEnd;

        if ($effectiveEnd < $rangeStart || $effectiveEnd < $startDate) {
            return [];
        }

        $dates = match ($raw['recurrence_frequency']) {
            'daily' => $this->expandDaily($startDate, $effectiveEnd),
            'weekly' => $this->expandWeekly($startDate, $effectiveEnd, $raw['recurrence_days']),
            'monthly' => $this->expandMonthly($startDate, $effectiveEnd, $raw['recurrence_monthly_type']),
            default => [],
        };

        $occurrences = [];

        foreach ($dates as $date) {
            if ($date < $rangeStart || $date > $rangeEnd) {
                continue;
            }

            $occurrences[] = ['date' => $date->format('Y-m-d'), 'is_multi_day_range' => false, 'is_recurring' => true] + $defaultTimes;
        }

        return $occurrences;
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function expandDaily(\DateTimeImmutable $start, \DateTimeImmutable $until): array
    {
        $dates = [];

        for ($cursor = $start; $cursor <= $until; $cursor = $cursor->modify('+1 day')) {
            $dates[] = $cursor;

            if (\count($dates) >= self::MAX_OCCURRENCES_PER_EVENT) {
                break;
            }
        }

        return $dates;
    }

    /**
     * @param string[] $days
     * @return \DateTimeImmutable[]
     */
    private function expandWeekly(\DateTimeImmutable $start, \DateTimeImmutable $until, array $days): array
    {
        $weekdayNumbers = [];

        foreach ($days as $day) {
            if (isset(self::WEEKDAY_MAP[$day])) {
                $weekdayNumbers[] = self::WEEKDAY_MAP[$day];
            }
        }

        if ($weekdayNumbers === []) {
            return [];
        }

        $dates = [];

        for ($cursor = $start; $cursor <= $until; $cursor = $cursor->modify('+1 day')) {
            if (\in_array((int) $cursor->format('N'), $weekdayNumbers, true)) {
                $dates[] = $cursor;

                if (\count($dates) >= self::MAX_OCCURRENCES_PER_EVENT) {
                    break;
                }
            }
        }

        return $dates;
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function expandMonthly(\DateTimeImmutable $start, \DateTimeImmutable $until, string $monthlyType): array
    {
        return $monthlyType === 'weekday_of_month'
            ? $this->expandMonthlyByWeekday($start, $until)
            : $this->expandMonthlyByDayOfMonth($start, $until);
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function expandMonthlyByDayOfMonth(\DateTimeImmutable $start, \DateTimeImmutable $until): array
    {
        $day = (int) $start->format('j');
        $dates = [];

        for ($monthCursor = $start->modify('first day of this month'); $monthCursor <= $until; $monthCursor = $monthCursor->modify('first day of next month')) {
            $daysInMonth = (int) $monthCursor->format('t');

            if ($day > $daysInMonth) {
                continue;
            }

            $candidate = $monthCursor->setDate((int) $monthCursor->format('Y'), (int) $monthCursor->format('n'), $day);

            if ($candidate >= $start && $candidate <= $until) {
                $dates[] = $candidate;
            }

            if (\count($dates) >= self::MAX_OCCURRENCES_PER_EVENT) {
                break;
            }
        }

        return $dates;
    }

    /**
     * @return \DateTimeImmutable[]
     */
    private function expandMonthlyByWeekday(\DateTimeImmutable $start, \DateTimeImmutable $until): array
    {
        $weekday = (int) $start->format('N');
        $nth = (int) \ceil(((int) $start->format('j')) / 7);
        $dates = [];

        for ($monthCursor = $start->modify('first day of this month'); $monthCursor <= $until; $monthCursor = $monthCursor->modify('first day of next month')) {
            $candidate = $this->nthWeekdayOfMonth($monthCursor, $weekday, $nth);

            if ($candidate !== null && $candidate >= $start && $candidate <= $until) {
                $dates[] = $candidate;
            }

            if (\count($dates) >= self::MAX_OCCURRENCES_PER_EVENT) {
                break;
            }
        }

        return $dates;
    }

    private function nthWeekdayOfMonth(\DateTimeImmutable $firstOfMonth, int $weekday, int $nth): ?\DateTimeImmutable
    {
        $firstWeekday = (int) $firstOfMonth->format('N');
        $offset = ($weekday - $firstWeekday + 7) % 7;
        $day = 1 + $offset + ($nth - 1) * 7;

        if ($day > (int) $firstOfMonth->format('t')) {
            return null;
        }

        return $firstOfMonth->setDate((int) $firstOfMonth->format('Y'), (int) $firstOfMonth->format('n'), $day);
    }

    /**
     * @return array<string, string[]> Term slugs keyed by taxonomy, for
     *                                  whichever custom taxonomies are
     *                                  configured against this event type.
     */
    private function resolveTerms(int $postId, string $postType): array
    {
        $taxonomies = \array_keys($this->settings->taxonomiesForEventType($postType));

        if ($taxonomies === []) {
            return [];
        }

        $termsByTaxonomy = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = \wp_get_object_terms($postId, $taxonomy, ['fields' => 'slugs']);
            $termsByTaxonomy[$taxonomy] = \is_array($terms) ? $terms : [];
        }

        return $termsByTaxonomy;
    }

    private function resolveVenueName(array $raw): string
    {
        if ($raw['venue_id'] > 0) {
            $title = \get_the_title($raw['venue_id']);

            if ($title !== '') {
                return $title;
            }
        }

        return $raw['venue_name'];
    }

    /**
     * A saved Venue post stores its own address/map/access fields
     * under the same _scem_venue_* keys as an event's own "manual
     * venue" mode (see LocationFields) — so a linked venue's details
     * come from reading that Venue post directly, the same call
     * resolveVenueName() makes for its title, rather than the event's
     * own (unset) manual fields.
     *
     * @return array<string, string|bool>
     */
    private function resolveVenueDetails(array $raw, int $postId): array
    {
        return LocationFields::readMeta($raw['venue_id'] > 0 ? (int) $raw['venue_id'] : $postId);
    }

    private function toDate(string $date): ?\DateTimeImmutable
    {
        if (! \preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }

        try {
            return new \DateTimeImmutable($date);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readRaw(int $postId): array
    {
        $isOneDayMeta = \get_post_meta($postId, '_scem_is_one_day', true);
        $rawDateTimes = \get_post_meta($postId, '_scem_date_times', true);
        $recurrenceDays = \get_post_meta($postId, '_scem_recurrence_days', true);
        $currency = $this->settings->currency();
        $priceRows = TicketPrices::read($postId);

        // Prefer the stored string only when there are no rows to
        // derive from — an event last saved before ticket rows
        // existed, whose price was too wordy to migrate cleanly.
        $summary = $priceRows === []
            ? (string) \get_post_meta($postId, TicketPrices::LEGACY_META_KEY, true)
            : TicketPrices::summary($priceRows, $currency);

        return [
            'is_one_day' => $isOneDayMeta === '' ? true : (bool) $isOneDayMeta,
            'start_date' => (string) \get_post_meta($postId, '_scem_start_date', true),
            'end_date' => (string) \get_post_meta($postId, '_scem_end_date', true),
            'start_time' => (string) \get_post_meta($postId, '_scem_start_time', true),
            'end_time' => (string) \get_post_meta($postId, '_scem_end_time', true),
            'different_times_per_date' => (bool) \get_post_meta($postId, '_scem_different_times_per_date', true),
            'date_times' => \is_array($rawDateTimes) ? $rawDateTimes : [],
            'recurrence_frequency' => (string) \get_post_meta($postId, '_scem_recurrence_frequency', true),
            'recurrence_days' => \is_array($recurrenceDays) ? $recurrenceDays : [],
            'recurrence_monthly_type' => (string) \get_post_meta($postId, '_scem_recurrence_monthly_type', true) ?: 'day_of_month',
            'recurrence_until' => (string) \get_post_meta($postId, '_scem_recurrence_until', true),
            'status' => (string) \get_post_meta($postId, '_scem_status', true) ?: 'scheduled',
            'venue_id' => (int) \get_post_meta($postId, '_scem_venue_id', true),
            'venue_name' => (string) \get_post_meta($postId, '_scem_venue_name', true),
            'price' => $summary,
            'price_rows' => \array_map(
                static fn (array $row): array => $row + [
                    'type_label' => TicketPrices::TYPES[$row['type']],
                    'label' => TicketPrices::rowLabel($row),
                    'display' => TicketPrices::rowPrice($row, $currency),
                ],
                $priceRows
            ),
            'price_from' => TicketPrices::lowestAmount($priceRows),
            'price_note' => TicketPrices::freeNote($priceRows),
            'ticket_notes' => TicketPrices::readNotes($postId),
            'ticket_url' => (string) \get_post_meta($postId, '_scem_ticket_url', true),
        ];
    }
}
