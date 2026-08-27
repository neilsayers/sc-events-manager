<?php

namespace SCEventsManager\Calendar;

/**
 * Builds the week/day grid for a single month, including the
 * leading/trailing days from adjacent months needed to fill whole
 * weeks — and clamps navigation to 6 months either side of today, per
 * spec.
 */
final class CalendarMonth
{
    public const NAV_RANGE_MONTHS = 6;

    public function __construct(private EventOccurrences $occurrences)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $year, int $month): array
    {
        [$year, $month] = $this->clamp($year, $month);

        $firstOfMonth = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        $gridStart = $firstOfMonth->modify('monday this week');
        $gridEnd = $lastOfMonth->modify('sunday this week');

        $occurrencesByDate = [];

        foreach ($this->occurrences->forRange($gridStart, $gridEnd) as $occurrence) {
            $occurrencesByDate[$occurrence['date']][] = $occurrence;
        }

        $today = new \DateTimeImmutable('today');
        $todayKey = $today->format('Y-m-d');
        $currentMonthNumber = (int) $firstOfMonth->format('n');

        $weeks = [];
        $week = [];

        for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
            $dateKey = $cursor->format('Y-m-d');

            $week[] = [
                'date' => $dateKey,
                'day' => (int) $cursor->format('j'),
                'inMonth' => (int) $cursor->format('n') === $currentMonthNumber,
                'isToday' => $dateKey === $todayKey,
                'occurrences' => $occurrencesByDate[$dateKey] ?? [],
            ];

            if (\count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }
        }

        $currentTotal = $this->toTotal($year, $month);
        $todayTotal = $this->toTotal((int) $today->format('Y'), (int) $today->format('n'));

        return [
            'year' => $year,
            'month' => $month,
            'label' => $firstOfMonth->format('F Y'),
            'weeks' => $weeks,
            'canGoPrev' => $currentTotal > $todayTotal - self::NAV_RANGE_MONTHS,
            'canGoNext' => $currentTotal < $todayTotal + self::NAV_RANGE_MONTHS,
        ];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function clamp(int $year, int $month): array
    {
        $today = new \DateTimeImmutable('today');
        $todayTotal = $this->toTotal((int) $today->format('Y'), (int) $today->format('n'));

        $total = $this->toTotal($year, $month);
        $total = \max($todayTotal - self::NAV_RANGE_MONTHS, \min($todayTotal + self::NAV_RANGE_MONTHS, $total));

        return $this->fromTotal($total);
    }

    private function toTotal(int $year, int $month): int
    {
        return $year * 12 + ($month - 1);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function fromTotal(int $total): array
    {
        return [(int) \floor($total / 12), ($total % 12) + 1];
    }
}
