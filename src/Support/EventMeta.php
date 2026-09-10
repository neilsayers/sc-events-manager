<?php

namespace SCEventsManager\Support;

/**
 * Reads every _scem_* field for a single event post. Shared by the
 * main "Event Details" box (Location, Date & Time, Recurrence) and
 * the side boxes it doesn't render (Tickets, Status, Organiser,
 * Restrictions), so there's one definition of what an event's
 * metadata looks like no matter which visual box a field lives in.
 */
final class EventMeta
{
    /**
     * @return array<string, mixed>
     */
    public static function read(int $postId): array
    {
        $isOneDayMeta = \get_post_meta($postId, '_scem_is_one_day', true);
        $rawDateTimes = \get_post_meta($postId, '_scem_date_times', true);
        $rawDateTimes = \is_array($rawDateTimes) ? $rawDateTimes : [];
        $dateTimes = [];

        foreach ($rawDateTimes as $date => $pair) {
            [$startHour, $startMinute] = self::splitTime($pair['start'] ?? '');
            [$endHour, $endMinute] = self::splitTime($pair['end'] ?? '');
            $dateTimes[$date] = ['start_hour' => $startHour, 'start_minute' => $startMinute, 'end_hour' => $endHour, 'end_minute' => $endMinute];
        }

        [$startTimeHour, $startTimeMinute] = self::splitTime((string) \get_post_meta($postId, '_scem_start_time', true));
        [$endTimeHour, $endTimeMinute] = self::splitTime((string) \get_post_meta($postId, '_scem_end_time', true));

        $recurrenceDays = \get_post_meta($postId, '_scem_recurrence_days', true);

        return [
            ...LocationFields::readMeta($postId),
            'venue_id' => (string) \get_post_meta($postId, '_scem_venue_id', true),
            // New posts default to a single-day event — the common case.
            'is_one_day' => $isOneDayMeta === '' ? true : (bool) $isOneDayMeta,
            'start_date' => (string) \get_post_meta($postId, '_scem_start_date', true),
            'end_date' => (string) \get_post_meta($postId, '_scem_end_date', true),
            'start_time_hour' => $startTimeHour,
            'start_time_minute' => $startTimeMinute,
            'end_time_hour' => $endTimeHour,
            'end_time_minute' => $endTimeMinute,
            'different_times_per_date' => (bool) \get_post_meta($postId, '_scem_different_times_per_date', true),
            'date_times' => $dateTimes,
            'recurrence_frequency' => (string) \get_post_meta($postId, '_scem_recurrence_frequency', true),
            'recurrence_days' => \is_array($recurrenceDays) ? $recurrenceDays : [],
            'recurrence_monthly_type' => (string) \get_post_meta($postId, '_scem_recurrence_monthly_type', true) ?: 'day_of_month',
            'recurrence_until' => (string) \get_post_meta($postId, '_scem_recurrence_until', true),
            'age_restricted' => (bool) \get_post_meta($postId, '_scem_age_restricted', true),
            'age_over' => (string) \get_post_meta($postId, '_scem_age_over', true),
            'age_under' => (string) \get_post_meta($postId, '_scem_age_under', true),
            'responsible_adult_required' => (bool) \get_post_meta($postId, '_scem_responsible_adult_required', true),
            'dogs_allowed' => (bool) \get_post_meta($postId, '_scem_dogs_allowed', true),
            'ticket_url' => (string) \get_post_meta($postId, '_scem_ticket_url', true),
            'ticket_prices' => TicketPrices::read($postId),
            'ticket_notes' => TicketPrices::readNotes($postId),
            // Derived from ticket_prices — see TicketPrices for why
            // the legacy string is still kept in step.
            'price' => (string) \get_post_meta($postId, '_scem_price', true),
            'status' => (string) \get_post_meta($postId, '_scem_status', true) ?: 'scheduled',
            'organiser_name' => (string) \get_post_meta($postId, '_scem_organiser_name', true),
            'organiser_email' => (string) \get_post_meta($postId, '_scem_organiser_email', true),
            'organiser_phone' => (string) \get_post_meta($postId, '_scem_organiser_phone', true),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function splitTime(string $time): array
    {
        if (\preg_match('/^(\d{2}):(\d{2})$/', $time, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return ['', ''];
    }
}
