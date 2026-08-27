<?php

namespace SCEventsManager\Admin;

use SCEventsManager\Contracts\Hookable;
use SCEventsManager\PostTypes\EventPostTypes;
use SCEventsManager\Settings\Settings;
use SCEventsManager\Setup\Activator;

/**
 * The plugin's one settings screen: lists every configured event type
 * (rename labels inline, all in one form) and, while under the limit,
 * a form to add another. First activation redirects here since the
 * list starts empty — see maybeRedirectToSetup().
 */
final class EventTypesPage implements Hookable
{
    private const PAGE_SLUG = 'scem-settings';
    private const ADD_ACTION = 'scem_add_event_type';
    private const RELABEL_ACTION = 'scem_relabel_event_types';
    private const DELETE_ACTION = 'scem_delete_event_type';

    /**
     * Post statuses counted as "existing" for the pre-delete warning
     * and actually moved to Trash — deliberately excludes 'trash'
     * (already there) and 'auto-draft' (never a real post an editor
     * created). Matches what get_posts(['post_status' => 'any']) below
     * returns, since that's the same core exclusion list.
     */
    private const DELETABLE_STATUSES = ['publish', 'future', 'draft', 'pending', 'private'];

    public function __construct(private Settings $settings)
    {
    }

    public function register(): void
    {
        \add_action('admin_menu', [$this, 'registerMenu']);
        \add_action('admin_menu', [$this, 'reorderMenu'], 999);
        \add_action('admin_init', [$this, 'maybeRedirectToSetup']);
        \add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        \add_action('admin_post_'.self::ADD_ACTION, [$this, 'handleAdd']);
        \add_action('admin_post_'.self::RELABEL_ACTION, [$this, 'handleRelabel']);
        \add_action('admin_post_'.self::DELETE_ACTION, [$this, 'handleDelete']);
        \add_action('admin_notices', [$this, 'maybeShowCreatedNotice']);
    }

    public function enqueueAssets(): void
    {
        if (($_GET['page'] ?? '') !== self::PAGE_SLUG) {
            return;
        }

        \wp_enqueue_style('scem-admin', SCEM_URL.'assets/css/admin.css', [], SCEM_VERSION);
        \wp_enqueue_script('scem-event-types-delete', SCEM_URL.'assets/js/event-types-delete.js', [], SCEM_VERSION, true);
    }

    public function registerMenu(): void
    {
        \add_menu_page(
            'SC Events Manager',
            'Events Manager',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage'],
            'dashicons-calendar-alt',
            26
        );

        /*
         * Without an explicit submenu entry whose own slug matches the
         * parent, this settings page would have no menu link pointing
         * to it at all — see reorderMenu() for why, and for why this
         * alone isn't quite enough either.
         */
        \add_submenu_page(
            self::PAGE_SLUG,
            'SC Events Manager',
            'Event Types',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'renderPage']
        );
    }

    /**
     * The top-level menu link's own href is whichever submenu item
     * was registered *first*, not whichever one shares its slug. The
     * Venues post type's show_in_menu targets this same parent slug,
     * and core registers a CPT's "all items" submenu entry before any
     * plugin's admin_menu hook runs — so "All Venues" always lands in
     * $submenu[self::PAGE_SLUG] before registerMenu() above ever gets
     * a chance to add this page's own entry, and the top-level link
     * silently opens Venues instead. Run last (priority 999) and move
     * this page's entry to the front so the top-level link — and the
     * visible submenu order — both point here first.
     */
    public function reorderMenu(): void
    {
        global $submenu;

        if (empty($submenu[self::PAGE_SLUG])) {
            return;
        }

        $items = $submenu[self::PAGE_SLUG];

        foreach ($items as $index => $item) {
            if ($item[2] === self::PAGE_SLUG) {
                unset($items[$index]);
                \array_unshift($items, $item);
                $submenu[self::PAGE_SLUG] = \array_values($items);

                return;
            }
        }
    }

    /**
     * Sends a freshly-activated admin here once, the same way
     * WooCommerce/Yoast-style wizards do. Skips bulk/network
     * activation so it doesn't hijack that summary screen.
     */
    public function maybeRedirectToSetup(): void
    {
        if (! \get_transient(Activator::REDIRECT_TRANSIENT)) {
            return;
        }

        \delete_transient(Activator::REDIRECT_TRANSIENT);

        if (
            $this->settings->hasEventTypes()
            || \wp_doing_ajax()
            || isset($_GET['activate-multi'])
            || ! \current_user_can('manage_options')
        ) {
            return;
        }

        \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG));
        exit;
    }

    public function renderPage(): void
    {
        if (! \current_user_can('manage_options')) {
            return;
        }

        $eventTypes = $this->settings->allEventTypes();
        ?>
        <div class="wrap">
            <h1>SC Events Manager</h1>

            <?php \settings_errors('scem_settings'); ?>

            <?php if ($eventTypes !== []) : ?>
                <h2>Event types</h2>
                <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                    <?php \wp_nonce_field(self::RELABEL_ACTION); ?>
                    <input type="hidden" name="action" value="<?php echo \esc_attr(self::RELABEL_ACTION); ?>">

                    <table class="widefat striped" style="max-width: 1100px;">
                        <thead>
                            <tr>
                                <th>Singular</th>
                                <th>Plural</th>
                                <th>URL slug</th>
                                <th></th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventTypes as $eventType) : ?>
                                <tr>
                                    <td>
                                        <input
                                            type="text"
                                            name="labels[<?php echo \esc_attr($eventType['post_type']); ?>][singular]"
                                            value="<?php echo \esc_attr($eventType['label_singular']); ?>"
                                            class="regular-text"
                                            required
                                        >
                                    </td>
                                    <td>
                                        <input
                                            type="text"
                                            name="labels[<?php echo \esc_attr($eventType['post_type']); ?>][plural]"
                                            value="<?php echo \esc_attr($eventType['label_plural']); ?>"
                                            class="regular-text"
                                            required
                                        >
                                    </td>
                                    <td><code>/<?php echo \esc_html($eventType['slug']); ?>/</code></td>
                                    <td>
                                        <a
                                            href="<?php echo \esc_url(\admin_url('edit.php?post_type='.$eventType['post_type'])); ?>"
                                            class="button"
                                        >View <?php echo \esc_html($eventType['label_plural']); ?></a>
                                    </td>
                                    <td><?php $this->renderDeleteButton($eventType); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <p class="description">
                        Renaming only changes the labels shown in wp-admin — the URL slug stays fixed so existing
                        links never break.
                    </p>

                    <?php \submit_button('Save changes'); ?>
                </form>

                <?php foreach ($eventTypes as $eventType) : ?>
                    <?php $this->renderDeleteDialog($eventType); ?>
                <?php endforeach; ?>
            <?php endif; ?>

            <h2><?php echo $eventTypes === [] ? 'Create your first event type' : 'Add another event type'; ?></h2>

            <?php if ($eventTypes === []) : ?>
                <p>
                    Before SC Events Manager builds its calendar, tell it what to call a single event on this site —
                    for example <strong>Show</strong>, <strong>Gig</strong>, or <strong>Class</strong>.
                </p>
            <?php endif; ?>

            <?php if ($this->settings->canAddEventType()) : ?>
                <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                    <?php \wp_nonce_field(self::ADD_ACTION); ?>
                    <input type="hidden" name="action" value="<?php echo \esc_attr(self::ADD_ACTION); ?>">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="scem_label_singular">Singular name</label></th>
                            <td>
                                <input name="label_singular" type="text" id="scem_label_singular" class="regular-text" placeholder="e.g. Show" required>
                                <p class="description">Used for "Add New Show", "Edit Show", and so on.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="scem_label_plural">Plural name</label></th>
                            <td>
                                <input name="label_plural" type="text" id="scem_label_plural" class="regular-text" placeholder="e.g. Shows" required>
                                <p class="description">Used for the admin menu and archive page, and to build its URL.</p>
                            </td>
                        </tr>
                    </table>

                    <?php \submit_button('Create event type'); ?>
                </form>
            <?php else : ?>
                <p>
                    <?php echo \esc_html(\sprintf(
                        'You\'ve reached the current limit of %d event types.',
                        $this->settings->maxEventTypes()
                    )); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * @param array{post_type: string, slug: string, label_singular: string, label_plural: string} $eventType
     */
    private function renderDeleteButton(array $eventType): void
    {
        ?>
        <button
            type="button"
            class="button button-link-delete"
            onclick="document.getElementById('scem-delete-dialog-<?php echo \esc_attr($eventType['post_type']); ?>').showModal()"
        >Delete&hellip;</button>
        <?php
    }

    /**
     * The confirmation dialog for one event type's delete button —
     * typing the plural label (GitHub delete-repo style) is required
     * to enable the submit button; see assets/js/event-types-delete.js.
     * check_admin_referer() below is the real guard against a forged
     * request — the typed-label check only guards against a misclick.
     *
     * @param array{post_type: string, slug: string, label_singular: string, label_plural: string} $eventType
     */
    private function renderDeleteDialog(array $eventType): void
    {
        $postType = $eventType['post_type'];
        $postCount = $this->postCountForType($postType);
        $taxonomyCount = \count($this->settings->taxonomiesForEventType($postType));
        $dialogId = 'scem-delete-dialog-'.$postType;
        $inputId = 'scem-delete-confirm-'.$postType;
        ?>
        <dialog id="<?php echo \esc_attr($dialogId); ?>" class="scem-delete-dialog">
            <form method="post" action="<?php echo \esc_url(\admin_url('admin-post.php')); ?>">
                <?php \wp_nonce_field(self::DELETE_ACTION); ?>
                <input type="hidden" name="action" value="<?php echo \esc_attr(self::DELETE_ACTION); ?>">
                <input type="hidden" name="post_type" value="<?php echo \esc_attr($postType); ?>">

                <h2>Delete <?php echo \esc_html($eventType['label_plural']); ?>?</h2>

                <p>
                    This will delete
                    <strong><?php echo \esc_html(\sprintf('%d %s', $postCount, $eventType['label_plural'])); ?></strong>.
                    They'll be moved to Trash, where they can be restored (or permanently deleted) for 30 days.
                </p>

                <?php if ($taxonomyCount > 0) : ?>
                    <p>
                        It will also remove
                        <?php echo \esc_html(\sprintf(
                            $taxonomyCount === 1 ? '%d custom taxonomy' : '%d custom taxonomies',
                            $taxonomyCount
                        )); ?>
                        attached to this event type — see <a href="<?php echo \esc_url(\admin_url('admin.php?page=scem-taxonomies')); ?>">Taxonomies</a>.
                    </p>
                <?php endif; ?>

                <p>
                    <label for="<?php echo \esc_attr($inputId); ?>">
                        Type <strong><?php echo \esc_html($eventType['label_plural']); ?></strong> to confirm:
                    </label>
                    <br>
                    <input
                        type="text"
                        id="<?php echo \esc_attr($inputId); ?>"
                        name="confirm_label"
                        class="regular-text scem-delete-confirm-input"
                        data-confirm="<?php echo \esc_attr($eventType['label_plural']); ?>"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                    >
                </p>

                <p>
                    <button type="submit" class="button button-primary scem-delete-confirm-submit" disabled>
                        Delete <?php echo \esc_html($eventType['label_plural']); ?>
                    </button>
                    <button type="button" class="button" onclick="document.getElementById('<?php echo \esc_attr($dialogId); ?>').close()">Cancel</button>
                </p>
            </form>
        </dialog>
        <?php
    }

    /**
     * Posts that actually exist for this type right now — the same
     * statuses get_posts(['post_status' => 'any']) returns in
     * handleDelete(), so this count matches what's about to be
     * trashed. Excludes 'trash' (already there) and 'auto-draft'
     * (never a real post).
     */
    private function postCountForType(string $postType): int
    {
        $counts = \wp_count_posts($postType);
        $total = 0;

        foreach (self::DELETABLE_STATUSES as $status) {
            $total += (int) ($counts->$status ?? 0);
        }

        return $total;
    }

    public function handleAdd(): void
    {
        if (! \current_user_can('manage_options')) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'sc-events-manager'));
        }

        \check_admin_referer(self::ADD_ACTION);

        $singular = \sanitize_text_field(\wp_unslash($_POST['label_singular'] ?? ''));
        $plural = \sanitize_text_field(\wp_unslash($_POST['label_plural'] ?? ''));

        $error = $this->settings->validateNewEventType($singular, $plural);

        if ($error !== null) {
            \add_settings_error('scem_settings', 'scem_invalid', $error);
            \set_transient('settings_errors', \get_settings_errors(), 30);
            \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG.'&settings-updated=true'));
            exit;
        }

        $postType = $this->settings->addEventType($singular, $plural);

        // Deferred to the next request's init — see EventPostTypes::FLUSH_TRANSIENT.
        \set_transient(EventPostTypes::FLUSH_TRANSIENT, true, 30);

        \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG.'&scem-created='.$postType));
        exit;
    }

    public function handleRelabel(): void
    {
        if (! \current_user_can('manage_options')) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'sc-events-manager'));
        }

        \check_admin_referer(self::RELABEL_ACTION);

        $labels = \wp_unslash($_POST['labels'] ?? []);
        $hadError = false;

        foreach ($labels as $postType => $pair) {
            $singular = \sanitize_text_field($pair['singular'] ?? '');
            $plural = \sanitize_text_field($pair['plural'] ?? '');

            if ($singular === '' || $plural === '') {
                $hadError = true;
                continue;
            }

            $this->settings->relabelEventType(\sanitize_key($postType), $singular, $plural);
        }

        if ($hadError) {
            \add_settings_error('scem_settings', 'scem_missing_labels', \__('Every event type needs both a singular and plural name — any left blank were skipped.', 'sc-events-manager'));
        } else {
            \add_settings_error('scem_settings', 'scem_saved', \__('Settings saved.', 'sc-events-manager'), 'success');
        }

        \set_transient('settings_errors', \get_settings_errors(), 30);
        \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG.'&settings-updated=true'));
        exit;
    }

    /**
     * Trashes the type's posts, deletes its taxonomies, then removes
     * the type itself — in that order, since both of the first two
     * steps need Settings::getEventType()/taxonomiesForEventType() to
     * still find it. The rewrite flush is deferred to the next
     * request for the same reason as handleAdd() — see
     * EventPostTypes::FLUSH_TRANSIENT.
     */
    public function handleDelete(): void
    {
        if (! \current_user_can('manage_options')) {
            \wp_die(\esc_html__('You are not allowed to do this.', 'sc-events-manager'));
        }

        \check_admin_referer(self::DELETE_ACTION);

        $postType = \sanitize_key(\wp_unslash($_POST['post_type'] ?? ''));
        $confirmLabel = \sanitize_text_field(\wp_unslash($_POST['confirm_label'] ?? ''));

        $eventType = $this->settings->getEventType($postType);

        if ($eventType === null) {
            \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG));
            exit;
        }

        // Belt-and-braces alongside the JS-disabled submit button —
        // that only stops an accidental click, not a resubmitted or
        // hand-crafted request.
        if (\trim($confirmLabel) !== $eventType['label_plural']) {
            \add_settings_error(
                'scem_settings',
                'scem_delete_mismatch',
                \sprintf(
                    'Typed confirmation didn\'t match "%s" — nothing was deleted.',
                    $eventType['label_plural']
                )
            );
            \set_transient('settings_errors', \get_settings_errors(), 30);
            \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG.'&settings-updated=true'));
            exit;
        }

        $postIds = \get_posts([
            'post_type' => $postType,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        foreach ($postIds as $postId) {
            \wp_trash_post($postId);
        }

        foreach ($this->settings->taxonomiesForEventType($postType) as $taxonomy) {
            $this->settings->deleteTaxonomy($taxonomy['taxonomy']);
        }

        $this->settings->deleteEventType($postType);

        // Deferred to the next request's init — see EventPostTypes::FLUSH_TRANSIENT.
        \set_transient(EventPostTypes::FLUSH_TRANSIENT, true, 30);

        \add_settings_error(
            'scem_settings',
            'scem_deleted',
            \sprintf(
                '"%s" deleted — %d moved to Trash.',
                $eventType['label_plural'],
                \count($postIds)
            ),
            'success'
        );
        \set_transient('settings_errors', \get_settings_errors(), 30);

        \wp_safe_redirect(\admin_url('admin.php?page='.self::PAGE_SLUG.'&settings-updated=true'));
        exit;
    }

    public function maybeShowCreatedNotice(): void
    {
        $postType = \sanitize_key(\wp_unslash($_GET['scem-created'] ?? ''));

        if ($postType === '') {
            return;
        }

        $eventType = $this->settings->getEventType($postType);

        if ($eventType === null) {
            return;
        }

        printf(
            '<div class="notice notice-success is-dismissible"><p>%s</p></div>',
            \esc_html(\sprintf('"%s" is ready — start adding your first one below.', $eventType['label_plural']))
        );
    }
}
