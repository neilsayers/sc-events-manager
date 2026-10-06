<?php

namespace SCEventsManager\MetaBoxes;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\PostTypes\VenuePostType;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Support\EventMeta;
use SCEventsManager\Support\Gallery;
use SCEventsManager\Support\LocationFields;
use SCEventsManager\Support\MetaField;
use SCEventsManager\Support\TicketPrices;

/**
 * Hand-written "Event details" box rendered above the
 * content editor via edit_form_after_title — add_meta_box() can only
 * place things in the columns below the editor, not above it.
 *
 * Covers Location, Date & Time, and Recurrence — the sections that
 * benefit from the main column's extra width (the map, the per-date
 * times table). Tickets, Status, Organiser, and Restrictions live in
 * their own side meta boxes (see the other classes in this
 * namespace), but this class still owns saveMetaBox() for all of it:
 * a meta box is just a visual container, every field posts through
 * the same <form> regardless of which box draws it, so one save
 * handler for the whole "scem" field set is simpler than splitting it
 * up to match the boxes.
 */
final class EventDetailsMetaBox implements Hookable
{
    private const NONCE_ACTION = 'scem_save_event_details';
    private const NONCE_NAME = 'scem_event_details_nonce';
    private const WEEKDAYS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat', 'sun' => 'Sun'];
    private const MAX_DATE_RANGE_DAYS = 366;

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('edit_form_after_title', [$this, 'renderMetaBox']);
        \add_action('save_post', [$this, 'saveMetaBox']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        \add_action('admin_notices', [$this, 'maybeShowVenueSavedNotice']);
    }

    private function isEventPostType(string $postType): bool
    {
        return $this->settings->getEventType($postType) !== null;
    }

    public function enqueueAssets(string $hook): void
    {
        if (! \in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $postType = \get_current_screen()->post_type ?? '';

        if (! $this->isEventPostType($postType)) {
            return;
        }

        LocationFields::enqueueMapAssets();
    }

    public function renderMetaBox(\WP_Post $post): void
    {
        if (! $this->isEventPostType($post->post_type)) {
            return;
        }

        $meta = EventMeta::read($post->ID);

        \wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <div class="postbox scem-event-details">
            <h2 class="hndle"><span>Event Details</span></h2>
            <div class="inside">

                <h3>Location</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="scem_venue_select">Saved venue</label></th>
                        <td>
                            <select id="scem_venue_select" name="scem[venue_id]" data-edit-url-base="<?php echo \esc_url(\admin_url('post.php?action=edit&post=')); ?>">
                                <option value="">— Enter manually —</option>
                                <?php foreach ($this->allVenues() as $venue) : ?>
                                    <option value="<?php echo \esc_attr((string) $venue->ID); ?>" <?php \selected($meta['venue_id'], (string) $venue->ID); ?>><?php echo \esc_html($venue->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Pick a venue you've saved before, or enter one-off details manually below.</p>
                        </td>
                    </tr>
                    <tr id="scem-venue-summary-row">
                        <th scope="row">Venue details</th>
                        <td>
                            <div id="scem-venue-summary"></div>
                        </td>
                    </tr>
                </table>

                <div id="scem-manual-venue-fields">
                    <?php LocationFields::render($meta); ?>
                    <p>
                        <label>
                            <input type="checkbox" name="scem[save_as_venue]" value="1">
                            Save these details as a venue I can reuse for future events
                        </label>
                    </p>
                </div>

                <h3>Date &amp; Time</h3>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">One day event?</th>
                        <td>
                            <label>
                                <input type="checkbox" id="scem_is_one_day" name="scem[is_one_day]" value="1" <?php \checked($meta['is_one_day']); ?>>
                                This event happens on a single day
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="scem_start_date">Start date</label></th>
                        <td><input type="date" id="scem_start_date" name="scem[start_date]" value="<?php echo \esc_attr($meta['start_date']); ?>"></td>
                    </tr>
                    <tr id="scem-end-date-row">
                        <th scope="row"><label for="scem_end_date">End date</label></th>
                        <td><input type="date" id="scem_end_date" name="scem[end_date]" value="<?php echo \esc_attr($meta['end_date']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Start time</th>
                        <td><?php $this->renderHourMinuteSelect('scem[start_time_hour]', 'scem[start_time_minute]', $meta['start_time_hour'], $meta['start_time_minute']); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">End time</th>
                        <td><?php $this->renderHourMinuteSelect('scem[end_time_hour]', 'scem[end_time_minute]', $meta['end_time_hour'], $meta['end_time_minute']); ?></td>
                    </tr>
                    <tr id="scem-different-times-row">
                        <th scope="row">Different times on different dates?</th>
                        <td>
                            <label>
                                <input type="checkbox" id="scem_different_times_per_date" name="scem[different_times_per_date]" value="1" <?php \checked($meta['different_times_per_date']); ?>>
                                Set its own start/end time for each date in the range
                            </label>
                        </td>
                    </tr>
                    <tr id="scem-date-times-row">
                        <th scope="row">Times per date</th>
                        <td>
                            <table id="scem-date-times-table" class="widefat striped">
                                <thead><tr><th>Date</th><th>Start</th><th>End</th></tr></thead>
                                <tbody>
                                    <?php $this->renderDateTimeRows($meta['start_date'], $meta['end_date'], $meta['date_times']); ?>
                                </tbody>
                            </table>
                            <p class="description">Rows are rebuilt automatically from the start/end date above.</p>
                        </td>
                    </tr>
                </table>

                <div id="scem-recurrence-section">
                    <h3>Recurrence</h3>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="scem_recurrence_frequency">Repeats</label></th>
                            <td>
                                <select id="scem_recurrence_frequency" name="scem[recurrence_frequency]">
                                    <?php foreach (['' => 'Does not repeat', 'daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month'] as $value => $label) : ?>
                                        <option value="<?php echo \esc_attr($value); ?>" <?php \selected($meta['recurrence_frequency'], $value); ?>><?php echo \esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr id="scem-recurrence-weekly-row">
                            <th scope="row">On these days</th>
                            <td>
                                <?php foreach (self::WEEKDAYS as $value => $label) : ?>
                                    <label style="margin-right: 12px; display: inline-block;">
                                        <input type="checkbox" name="scem[recurrence_days][]" value="<?php echo \esc_attr($value); ?>" <?php \checked(\in_array($value, $meta['recurrence_days'], true)); ?>>
                                        <?php echo \esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                        <tr id="scem-recurrence-monthly-row">
                            <th scope="row">Monthly on</th>
                            <td>
                                <select name="scem[recurrence_monthly_type]">
                                    <option value="day_of_month" <?php \selected($meta['recurrence_monthly_type'], 'day_of_month'); ?>>The same date each month</option>
                                    <option value="weekday_of_month" <?php \selected($meta['recurrence_monthly_type'], 'weekday_of_month'); ?>>The same weekday each month (e.g. third Thursday)</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="scem-recurrence-until-row">
                            <th scope="row"><label for="scem_recurrence_until">Ends on</label></th>
                            <td><input type="date" id="scem_recurrence_until" name="scem[recurrence_until]" value="<?php echo \esc_attr($meta['recurrence_until']); ?>"></td>
                        </tr>
                    </table>
                </div>

            </div>
        </div>
        <script type="application/json" id="scem-existing-date-times"><?php echo \wp_json_encode($meta['date_times']); ?></script>
        <script type="application/json" id="scem-venues-data"><?php echo \wp_json_encode($this->venuesForJs()); ?></script>
        <?php
    }

    /**
     * @return \WP_Post[]
     */
    private function allVenues(): array
    {
        return \get_posts([
            'post_type' => VenuePostType::POST_TYPE,
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'post_status' => 'publish',
        ]);
    }

    /**
     * A small summary of every saved venue, keyed by post ID, so the
     * JS can update the read-only summary when the dropdown changes
     * without a page reload.
     *
     * @return array<int, array<string, mixed>>
     */
    private function venuesForJs(): array
    {
        $venues = [];

        foreach ($this->allVenues() as $venue) {
            $venueMeta = LocationFields::readMeta($venue->ID);
            $venues[$venue->ID] = [
                'name' => $venue->post_title,
                'address' => $venueMeta['venue_address'],
                'town' => $venueMeta['venue_town'],
                'postcode' => $venueMeta['venue_postcode'],
                'indoor' => $venueMeta['venue_indoor'],
                'outdoor' => $venueMeta['venue_outdoor'],
                'disabled_access' => $venueMeta['venue_disabled_access'],
            ];
        }

        return $venues;
    }

    private function renderHourMinuteSelect(string $hourFieldName, string $minuteFieldName, string $hour, string $minute): void
    {
        ?>
        <select name="<?php echo \esc_attr($hourFieldName); ?>">
            <option value="">--</option>
            <?php for ($h = 0; $h < 24; $h++) : $value = \sprintf('%02d', $h); ?>
                <option value="<?php echo \esc_attr($value); ?>" <?php \selected($hour, $value); ?>><?php echo \esc_html($value); ?></option>
            <?php endfor; ?>
        </select>
        :
        <select name="<?php echo \esc_attr($minuteFieldName); ?>">
            <option value="">--</option>
            <?php foreach (['00', '15', '30', '45'] as $value) : ?>
                <option value="<?php echo \esc_attr($value); ?>" <?php \selected($minute, $value); ?>><?php echo \esc_html($value); ?></option>
            <?php endforeach; ?>
        </select>
        <?php
    }

    /**
     * Server-renders the initial rows so saved data is visible even
     * before JS runs; event-meta-box.js rebuilds this table whenever
     * the start/end date fields change afterwards.
     */
    private function renderDateTimeRows(string $startDate, string $endDate, array $dateTimes): void
    {
        foreach ($this->dateRange($startDate, $endDate) as $date) {
            $existing = $dateTimes[$date] ?? ['start_hour' => '', 'start_minute' => '', 'end_hour' => '', 'end_minute' => ''];
            ?>
            <tr>
                <td><?php echo \esc_html($date); ?></td>
                <td><?php $this->renderHourMinuteSelect("scem[date_times][{$date}][start_hour]", "scem[date_times][{$date}][start_minute]", $existing['start_hour'], $existing['start_minute']); ?></td>
                <td><?php $this->renderHourMinuteSelect("scem[date_times][{$date}][end_hour]", "scem[date_times][{$date}][end_minute]", $existing['end_hour'], $existing['end_minute']); ?></td>
            </tr>
            <?php
        }
    }

    /**
     * @return string[] Y-m-d dates from $start to $end inclusive, capped at MAX_DATE_RANGE_DAYS.
     */
    private function dateRange(string $start, string $end): array
    {
        if (! $this->isValidDate($start) || ! $this->isValidDate($end)) {
            return [];
        }

        try {
            $startDate = new \DateTimeImmutable($start);
            $endDate = new \DateTimeImmutable($end);
        } catch (\Exception) {
            return [];
        }

        if ($endDate < $startDate) {
            return [];
        }

        $dates = [];

        for ($date = $startDate; $date <= $endDate && \count($dates) < self::MAX_DATE_RANGE_DAYS; $date = $date->modify('+1 day')) {
            $dates[] = $date->format('Y-m-d');
        }

        return $dates;
    }

    public function saveMetaBox(int $postId): void
    {
        if (
            ! isset($_POST[self::NONCE_NAME])
            || ! \wp_verify_nonce(\sanitize_text_field(\wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
        ) {
            return;
        }

        if (\defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        $post = \get_post($postId);

        if (! $post || ! $this->isEventPostType($post->post_type) || ! \current_user_can('edit_post', $postId)) {
            return;
        }

        $data = \wp_unslash($_POST['scem'] ?? []);

        $this->saveVenue($postId, $data);
        Gallery::save($postId, \is_array($data['gallery'] ?? null) ? $data['gallery'] : []);

        $isOneDay = ! empty($data['is_one_day']);
        // '0', not '' — MetaField::saveValue() deletes on an empty
        // string, and EventMeta::read()/EventOccurrences::readRaw()
        // both treat a *missing* key as "one day" (the new-post
        // default). Saving '' for an explicitly multi-day event would
        // delete the row and silently flip it back to one-day on the
        // next read.
        MetaField::saveValue($postId, '_scem_is_one_day', $isOneDay ? '1' : '0');
        MetaField::saveValue($postId, '_scem_start_date', $this->sanitizeDate($data['start_date'] ?? ''));
        MetaField::saveValue($postId, '_scem_end_date', $isOneDay ? '' : $this->sanitizeDate($data['end_date'] ?? ''));
        MetaField::saveValue($postId, '_scem_start_time', $this->sanitizeTime($data['start_time_hour'] ?? '', $data['start_time_minute'] ?? ''));
        MetaField::saveValue($postId, '_scem_end_time', $this->sanitizeTime($data['end_time_hour'] ?? '', $data['end_time_minute'] ?? ''));

        $differentTimes = ! $isOneDay && ! empty($data['different_times_per_date']);
        MetaField::saveValue($postId, '_scem_different_times_per_date', $differentTimes ? '1' : '');
        $this->saveDateTimes($postId, $differentTimes, \is_array($data['date_times'] ?? null) ? $data['date_times'] : []);

        $frequency = $isOneDay ? \sanitize_key($data['recurrence_frequency'] ?? '') : '';
        $frequency = \in_array($frequency, ['daily', 'weekly', 'monthly'], true) ? $frequency : '';
        MetaField::saveValue($postId, '_scem_recurrence_frequency', $frequency);
        $this->saveRecurrenceDays($postId, $frequency, \is_array($data['recurrence_days'] ?? null) ? $data['recurrence_days'] : []);

        $monthlyType = \sanitize_key($data['recurrence_monthly_type'] ?? '');
        $monthlyType = $frequency === 'monthly' && \in_array($monthlyType, ['day_of_month', 'weekday_of_month'], true) ? $monthlyType : '';
        MetaField::saveValue($postId, '_scem_recurrence_monthly_type', $monthlyType);
        MetaField::saveValue($postId, '_scem_recurrence_until', $frequency !== '' ? $this->sanitizeDate($data['recurrence_until'] ?? '') : '');

        $ageRestricted = ! empty($data['age_restricted']);
        MetaField::saveValue($postId, '_scem_age_restricted', $ageRestricted ? '1' : '');
        $ageOver = $ageRestricted ? $this->sanitizeAge($data['age_over'] ?? '') : '';
        $ageUnder = $ageRestricted ? $this->sanitizeAge($data['age_under'] ?? '') : '';
        MetaField::saveValue($postId, '_scem_age_over', $ageOver);
        MetaField::saveValue($postId, '_scem_age_under', $ageUnder);
        MetaField::saveValue($postId, '_scem_responsible_adult_required', ($ageUnder !== '' && ! empty($data['responsible_adult_required'])) ? '1' : '');
        MetaField::saveValue($postId, '_scem_dogs_allowed', ! empty($data['dogs_allowed']) ? '1' : '');

        MetaField::saveValue($postId, '_scem_ticket_url', \esc_url_raw($data['ticket_url'] ?? ''));
        TicketPrices::save(
            $postId,
            \is_array($data['ticket_prices'] ?? null) ? $data['ticket_prices'] : [],
            (string) ($data['ticket_notes'] ?? '')
        );

        $status = \sanitize_key($data['status'] ?? 'scheduled');
        MetaField::saveValue($postId, '_scem_status', \in_array($status, ['scheduled', 'postponed', 'cancelled', 'sold_out'], true) ? $status : 'scheduled');

        MetaField::saveText($postId, '_scem_organiser_name', $data['organiser_name'] ?? '');
        MetaField::saveValue($postId, '_scem_organiser_email', \sanitize_email($data['organiser_email'] ?? ''));
        MetaField::saveText($postId, '_scem_organiser_phone', $data['organiser_phone'] ?? '');
    }

    /**
     * Either links to an existing saved venue, creates a new one from
     * the manual fields (when "save as venue" was ticked), or keeps
     * the manual fields as a one-off, self-contained location.
     *
     * @param array<string, mixed> $data
     */
    private function saveVenue(int $postId, array $data): void
    {
        $venueId = (int) ($data['venue_id'] ?? 0);

        if ($venueId > 0 && \get_post_type($venueId) === VenuePostType::POST_TYPE) {
            MetaField::saveValue($postId, '_scem_venue_id', (string) $venueId);
            LocationFields::clear($postId);

            return;
        }

        $venueName = \sanitize_text_field($data['venue_name'] ?? '');
        $newVenueId = (! empty($data['save_as_venue']) && $venueName !== '')
            ? $this->createVenueFromManualFields($venueName, $data)
            : 0;

        if ($newVenueId > 0) {
            MetaField::saveValue($postId, '_scem_venue_id', (string) $newVenueId);
            LocationFields::clear($postId);
            $this->flagVenueSavedNotice();

            return;
        }

        MetaField::saveValue($postId, '_scem_venue_id', '');
        LocationFields::save($postId, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createVenueFromManualFields(string $venueName, array $data): int
    {
        $venueId = \wp_insert_post([
            'post_type' => VenuePostType::POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $venueName,
        ]);

        if (! $venueId || \is_wp_error($venueId)) {
            return 0;
        }

        LocationFields::save($venueId, $data);
        // The venue's own screen never renders/saves this field — its post title is the name.
        \delete_post_meta($venueId, '_scem_venue_name');

        return $venueId;
    }

    private function flagVenueSavedNotice(): void
    {
        \add_filter('redirect_post_location', static function (string $location): string {
            return \add_query_arg('scem-venue-saved', '1', $location);
        });
    }

    public function maybeShowVenueSavedNotice(): void
    {
        if (($_GET['scem-venue-saved'] ?? '') !== '1') {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            \esc_html__('Venue saved — manage it under Events Manager → Venues.', 'sc-events-manager')
        );
    }

    private function saveDateTimes(int $postId, bool $differentTimes, array $rows): void
    {
        if (! $differentTimes || $rows === []) {
            \delete_post_meta($postId, '_scem_date_times');

            return;
        }

        $dateTimes = [];

        foreach ($rows as $date => $times) {
            $date = $this->sanitizeDate((string) $date);

            if ($date === '' || ! \is_array($times)) {
                continue;
            }

            $dateTimes[$date] = [
                'start' => $this->sanitizeTime($times['start_hour'] ?? '', $times['start_minute'] ?? ''),
                'end' => $this->sanitizeTime($times['end_hour'] ?? '', $times['end_minute'] ?? ''),
            ];
        }

        if ($dateTimes === []) {
            \delete_post_meta($postId, '_scem_date_times');

            return;
        }

        \update_post_meta($postId, '_scem_date_times', $dateTimes);
    }

    private function saveRecurrenceDays(int $postId, string $frequency, array $days): void
    {
        if ($frequency !== 'weekly') {
            \delete_post_meta($postId, '_scem_recurrence_days');

            return;
        }

        $validDays = \array_values(\array_intersect(\array_keys(self::WEEKDAYS), \array_map('sanitize_key', $days)));

        if ($validDays === []) {
            \delete_post_meta($postId, '_scem_recurrence_days');

            return;
        }

        \update_post_meta($postId, '_scem_recurrence_days', $validDays);
    }

    private function sanitizeDate(string $date): string
    {
        return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    private function isValidDate(string $date): bool
    {
        return $this->sanitizeDate($date) !== '';
    }

    private function sanitizeAge(string $value): string
    {
        if ($value === '' || ! \is_numeric($value)) {
            return '';
        }

        $age = (int) $value;

        return ($age < 0 || $age > 120) ? '' : (string) $age;
    }

    private function sanitizeTime(string $hour, string $minute): string
    {
        if ($hour === '' || $minute === '') {
            return '';
        }

        $hour = (int) $hour;
        $minute = (int) $minute;

        if ($hour < 0 || $hour > 23 || ! \in_array($minute, [0, 15, 30, 45], true)) {
            return '';
        }

        return \sprintf('%02d:%02d', $hour, $minute);
    }
}
