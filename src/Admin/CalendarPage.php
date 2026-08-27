<?php

namespace SCEventsManager\Admin;

use SCEventsManager\Calendar\CalendarMonth;
use SCEventsManager\Calendar\EventOccurrences;
use SCEventsManager\Contracts\Hookable;
use SCEventsManager\Settings\Settings;

/**
 * "All Events Calendar" — a month grid across every configured event
 * type. The initial month is rendered server-side; navigating to
 * another month fetches it via admin-ajax and re-renders client-side
 * (see assets/js/calendar.js) rather than a full page reload.
 */
final class CalendarPage implements Hookable
{
    private const PAGE_SLUG = 'scem-calendar';
    private const AJAX_ACTION = 'scem_calendar_month';

    private CalendarMonth $calendarMonth;

    public function __construct(private Settings $settings)
    {
        $this->calendarMonth = new CalendarMonth(new EventOccurrences($settings));
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'registerMenu']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        \add_action('wp_ajax_'.self::AJAX_ACTION, [$this, 'handleAjaxRequest']);
    }

    public function registerMenu(): void
    {
        \add_submenu_page(
            'scem-settings',
            'All Events Calendar',
            'Calendar',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    public function enqueueAssets(): void
    {
        if (($_GET['page'] ?? '') !== self::PAGE_SLUG) {
            return;
        }

        \wp_enqueue_style('scem-admin', SCEM_URL.'assets/css/admin.css', [], SCEM_VERSION);
        \wp_enqueue_script('scem-calendar', SCEM_URL.'assets/js/calendar.js', [], SCEM_VERSION, true);
        \wp_localize_script('scem-calendar', 'scemCalendar', [
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => \wp_create_nonce(self::AJAX_ACTION),
        ]);
    }

    public function renderPage(): void
    {
        if (! \current_user_can('manage_options')) {
            return;
        }

        $today = new \DateTimeImmutable('today');
        $grid = $this->calendarMonth->build((int) $today->format('Y'), (int) $today->format('n'));
        ?>
        <div class="wrap">
            <h1>All Events Calendar</h1>

            <?php if ($this->settings->allEventTypes() === []) : ?>
                <p>
                    <a href="<?php echo \esc_url(\admin_url('admin.php?page=scem-settings')); ?>">Create an event type</a>
                    first — there's nothing to show on the calendar yet.
                </p>
            <?php else : ?>
                <div id="scem-calendar-root" class="scem-cal-loading">
                    <p>Loading calendar…</p>
                </div>
                <script type="application/json" id="scem-calendar-initial-data"><?php echo \wp_json_encode($grid); ?></script>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handleAjaxRequest(): void
    {
        \check_ajax_referer(self::AJAX_ACTION, 'nonce');

        if (! \current_user_can('manage_options')) {
            \wp_send_json_error(['message' => 'Not allowed'], 403);
        }

        $year = (int) ($_GET['year'] ?? 0);
        $month = (int) ($_GET['month'] ?? 0);

        if ($year < 1 || $month < 1 || $month > 12) {
            \wp_send_json_error(['message' => 'Invalid month'], 400);
        }

        \wp_send_json_success($this->calendarMonth->build($year, $month));
    }
}
