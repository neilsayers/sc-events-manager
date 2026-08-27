<?php

namespace SCEventsManager\Frontend;

use SCEventsManager\Calendar\EventOccurrences;

/**
 * Turns [sc_events] shortcode attributes into a filtered, sorted,
 * limited list of occurrences. Recurring events are expanded from
 * their stored rule on the fly (see EventOccurrences), which has no
 * natural "next N regardless of frequency" query — so "future"/"past"
 * resolve to a generous but bounded window (18 months) and limit=""
 * truncates the sorted result afterwards, rather than trying to
 * stream exactly N occurrences from a rule that might repeat forever.
 */
final class EventListingQuery
{
    private const WINDOW_MONTHS = 18;

    public function __construct(private EventOccurrences $occurrences)
    {
    }

    /**
     * @param array<string, mixed> $atts Already-merged shortcode_atts() output.
     * @return array<int, array<string, mixed>>
     */
    public function run(array $atts): array
    {
        [$rangeStart, $rangeEnd, $descending] = $this->resolveRange((string) $atts['range']);

        $postTypes = $atts['type'] !== ''
            ? \array_filter(\array_map('trim', \explode(',', (string) $atts['type'])))
            : null;

        $occurrences = $this->occurrences->forRange($rangeStart, $rangeEnd, [
            'post_types' => $postTypes === null ? null : \array_values($postTypes),
            'post_status' => 'publish',
            'venue_id' => (int) $atts['venue'] ?: null,
            'taxonomy' => (string) $atts['taxonomy'] ?: null,
            'term' => (string) $atts['term'] ?: null,
            'include_cancelled' => \filter_var($atts['show_cancelled'], \FILTER_VALIDATE_BOOLEAN),
        ]);

        if (\filter_var($atts['combine_multidate_events'] ?? false, \FILTER_VALIDATE_BOOLEAN)) {
            $occurrences = $this->combineMultidateEvents($occurrences);
        }

        if ($descending) {
            $occurrences = \array_reverse($occurrences);
        }

        $limit = (int) $atts['limit'];

        if ($limit > 0) {
            $occurrences = \array_slice($occurrences, 0, $limit);
        }

        return $occurrences;
    }

    /**
     * A multi-day event expands into one occurrence per date it runs
     * (see EventOccurrences) — that's right for a calendar, but wrong
     * for a listing, which should show it once on its first day with
     * a date range. Groups by event_id (occurrences for the same
     * event aren't necessarily adjacent in the input, since another
     * event's occurrence can fall on one of its dates), keeps the
     * first occurrence per group, and stamps it with 'end_date' when
     * the group has more than one date so the renderer knows to show
     * a range instead of a single day.
     *
     * @param array<int, array<string, mixed>> $occurrences Sorted by date then start time.
     * @return array<int, array<string, mixed>> Re-sorted the same way, one row per event.
     */
    private function combineMultidateEvents(array $occurrences): array
    {
        $byEvent = [];

        foreach ($occurrences as $occurrence) {
            $byEvent[$occurrence['event_id']][] = $occurrence;
        }

        $combined = [];

        foreach ($byEvent as $group) {
            $first = $group[0];

            // Only a genuine contiguous multi-day date range collapses to
            // one row — a recurring event (weekly/monthly/daily) legitimately
            // occurs more than once and each occurrence stays its own row.
            if (\count($group) > 1 && $first['is_multi_day_range']) {
                $first['end_date'] = $group[\count($group) - 1]['date'];
            }

            $combined[] = $first;
        }

        \usort(
            $combined,
            static fn (array $a, array $b): int => [$a['date'], $a['start_time']] <=> [$b['date'], $b['start_time']]
        );

        return $combined;
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: bool} [start, end, descending]
     */
    private function resolveRange(string $range): array
    {
        $today = new \DateTimeImmutable('today');

        return match ($range) {
            'past' => [$today->modify('-'.self::WINDOW_MONTHS.' months'), $today->modify('-1 day'), true],
            'month' => [$today->modify('first day of this month'), $today->modify('last day of this month'), false],
            'week' => [$today->modify('monday this week'), $today->modify('sunday this week'), false],
            default => [$today, $today->modify('+'.self::WINDOW_MONTHS.' months'), false], // 'future'
        };
    }
}
