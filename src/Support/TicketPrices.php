<?php

namespace SCEventsManager\Support;

/**
 * An event's ticket prices: an ordered list of rows, each one a
 * ticket type ("Adult", "Child", ...) with an optional age qualifier
 * and either an amount or "free".
 *
 * Why rows rather than the free-text price string this replaces: a
 * string can't be sorted, filtered, or shortened, so every consumer
 * had to print it whole and hope it fitted. Listing cards in
 * particular have a fixed amount of room, and "£10 adults, £5
 * unaccompanied children, under 5 free" doesn't fit in it.
 *
 * Ages are stored the way Restrictions already stores them — a
 * from (inclusive) and an under (exclusive) — so "under 5",
 * "ages 5-15" and "60+" are all the same two fields, rather than
 * being six near-duplicate entries in the type list.
 *
 * _scem_price is still written on every save, holding summary()'s
 * output, so anything reading that meta key directly (or an event
 * saved before this existed and not re-saved since) still gets a
 * sensible string.
 */
final class TicketPrices
{
    public const ROWS_META_KEY = '_scem_ticket_prices';
    public const NOTES_META_KEY = '_scem_ticket_notes';
    public const LEGACY_META_KEY = '_scem_price';

    /**
     * Ticket types, in the canonical order rows are always displayed
     * in — the order here is the order on the front end, so editors
     * never have to think about sorting and two events priced the
     * same always read the same way.
     *
     * The age tiers run oldest-first (Adult, Teen, Child, Infant) so
     * the headline price leads and the table reads down to the free
     * rows, the way a ticket price is written on a poster.
     *
     * These are names, not age bands: a venue that prices two child
     * bands adds two Child rows with different age qualifiers rather
     * than needing a type per boundary.
     *
     * @var array<string, string>
     */
    public const TYPES = [
        'adult' => 'Adult',
        'teen' => 'Teen',
        'child' => 'Child',
        'infant' => 'Infant',
        'concession' => 'Concession',
        'senior' => 'Senior',
        'student' => 'Student',
        'carer' => 'Carer',
        'family' => 'Family',
        'group' => 'Group',
        'member' => 'Member',
    ];

    /**
     * How each type is phrased in the one-line "... free" note a
     * listing card can show under the headline price.
     *
     * @var array<string, string>
     */
    private const FREE_NOTE_PLURALS = [
        'adult' => 'Adults',
        'teen' => 'Teens',
        'child' => 'Children',
        'infant' => 'Infants',
        'concession' => 'Concessions',
        'senior' => 'Seniors',
        'student' => 'Students',
        'carer' => 'Carers',
        'family' => 'Family tickets',
        'group' => 'Groups',
        'member' => 'Members',
    ];

    /** Guard against a runaway row count from a malformed POST. */
    private const MAX_ROWS = 20;

    /**
     * Reads an event's rows, falling back to migrating the legacy
     * free-text price when none have been saved yet. Nothing is
     * written here — the migrated row is just what the meta box
     * shows, and it becomes real the next time the post is saved.
     *
     * @return array<int, array{type: string, age_from: string, age_under: string, free: bool, amount: string}>
     */
    public static function read(int $postId): array
    {
        $stored = \get_post_meta($postId, self::ROWS_META_KEY, true);

        if (\is_array($stored) && $stored !== []) {
            return self::sanitizeRows($stored);
        }

        return self::rowsFromLegacyPrice((string) \get_post_meta($postId, self::LEGACY_META_KEY, true));
    }

    public static function readNotes(int $postId): string
    {
        $notes = (string) \get_post_meta($postId, self::NOTES_META_KEY, true);

        if ($notes !== '' || self::read($postId) !== []) {
            return $notes;
        }

        // A legacy price string too wordy to become a row isn't
        // thrown away — it surfaces here so the editor can see what
        // the event used to say while re-entering it as rows.
        return (string) \get_post_meta($postId, self::LEGACY_META_KEY, true);
    }

    /**
     * @param array<int|string, mixed> $rows
     */
    public static function save(int $postId, array $rows, string $notes): void
    {
        $clean = self::sanitizeRows($rows);

        if ($clean === []) {
            \delete_post_meta($postId, self::ROWS_META_KEY);
        } else {
            \update_post_meta($postId, self::ROWS_META_KEY, $clean);
        }

        MetaField::saveText($postId, self::NOTES_META_KEY, $notes);

        // Keep the legacy string in step so direct readers of
        // _scem_price never see it drift away from the rows.
        MetaField::saveValue($postId, self::LEGACY_META_KEY, self::summary($clean));
    }

    /**
     * @param array<int|string, mixed> $rows
     * @return array<int, array{type: string, age_from: string, age_under: string, free: bool, amount: string}>
     */
    public static function sanitizeRows(array $rows): array
    {
        $clean = [];

        foreach ($rows as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $type = \sanitize_key((string) ($row['type'] ?? ''));

            if (! isset(self::TYPES[$type])) {
                continue;
            }

            $free = ! empty($row['free']);
            $amount = $free ? '' : self::sanitizeAmount((string) ($row['amount'] ?? ''));

            // A zero amount is what an editor types when they mean
            // free but haven't spotted the checkbox — treat it as
            // free rather than advertising a "£0" ticket.
            if (! $free && $amount === '0') {
                $free = true;
                $amount = '';
            }

            // A row that is neither free nor priced carries no
            // information — it's an empty row the editor added and
            // then left alone.
            if (! $free && $amount === '') {
                continue;
            }

            $clean[] = [
                'type' => $type,
                'age_from' => self::sanitizeAge((string) ($row['age_from'] ?? '')),
                'age_under' => self::sanitizeAge((string) ($row['age_under'] ?? '')),
                'free' => $free,
                'amount' => $amount,
            ];

            if (\count($clean) >= self::MAX_ROWS) {
                break;
            }
        }

        return self::sortRows($clean);
    }

    /**
     * Canonical order: TYPES order first, then cheapest first within
     * a type, so "Child (5-15) £5" always precedes "Child (under 5)
     * Free" no matter what order they were entered in.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function sortRows(array $rows): array
    {
        $typeOrder = \array_flip(\array_keys(self::TYPES));

        \usort($rows, static function (array $a, array $b) use ($typeOrder): int {
            return [$typeOrder[$a['type']], $a['free'] ? 1 : 0, (float) ($a['amount'] ?: 0)]
                <=> [$typeOrder[$b['type']], $b['free'] ? 1 : 0, (float) ($b['amount'] ?: 0)];
        });

        return $rows;
    }

    /**
     * The short string a listing card shows. Bounded by construction:
     * at most "From " plus one formatted amount.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function summary(array $rows, ?string $currency = null): string
    {
        if ($rows === []) {
            return '';
        }

        $amounts = self::paidAmounts($rows);

        if ($amounts === []) {
            return 'Free';
        }

        $formatted = self::formatAmount(\min($amounts), $currency);

        // "From" only when there is genuinely more than one price to
        // be at the bottom of — a single tier plus free children is
        // "£10", not "From £10".
        return \count(\array_unique($amounts)) > 1 ? 'From '.$formatted : $formatted;
    }

    /**
     * The cheapest paid amount, for sorting and price filters, or
     * null when the event is free or unpriced.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function lowestAmount(array $rows): ?float
    {
        $amounts = self::paidAmounts($rows);

        return $amounts === [] ? null : \min($amounts);
    }

    /**
     * One short "... free" line for a listing card, or "" when there
     * isn't one worth showing. Deliberately at most one note: the
     * card's height has to stay predictable, and the full breakdown
     * is on the event's own page.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function freeNote(array $rows): string
    {
        // Every row free is already said by summary()'s "Free".
        if ($rows === [] || self::paidAmounts($rows) === []) {
            return '';
        }

        foreach ($rows as $row) {
            if (! $row['free'] || $row['type'] === 'adult') {
                continue;
            }

            if ($row['age_under'] !== '' && $row['age_from'] === '') {
                return 'Under '.$row['age_under'].'s free';
            }

            return (self::FREE_NOTE_PLURALS[$row['type']] ?? self::TYPES[$row['type']]).' free';
        }

        return '';
    }

    /**
     * "Adult", "Child (under 5)", "Child (ages 5-15)", "Senior (60+)".
     *
     * @param array<string, mixed> $row
     */
    public static function rowLabel(array $row): string
    {
        $label = self::TYPES[$row['type']] ?? $row['type'];
        $from = (string) $row['age_from'];
        $under = (string) $row['age_under'];

        if ($from !== '' && $under !== '') {
            // "under" is exclusive, so the inclusive top of the range
            // is one below it — an editor typing 5 and 16 means the
            // 5-15 band they'd write on a poster.
            $top = \max((int) $from, (int) $under - 1);

            return $label.' (ages '.$from.'-'.$top.')';
        }

        if ($from !== '') {
            return $label.' ('.$from.'+)';
        }

        if ($under !== '') {
            return $label.' (under '.$under.')';
        }

        return $label;
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function rowPrice(array $row, ?string $currency = null): string
    {
        return $row['free'] ? 'Free' : self::formatAmount((float) $row['amount'], $currency);
    }

    public static function formatAmount(float $amount, ?string $currency = null): string
    {
        $currency ??= self::currency();

        // Whole pounds lose the ".00" — "£10", not "£10.00".
        $number = \fmod($amount, 1.0) === 0.0
            ? \number_format($amount, 0)
            : \number_format($amount, 2);

        return $currency.$number;
    }

    public static function currency(): string
    {
        return \SCEventsManager\Plugin::instance()->settings()->currency();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, float>
     */
    private static function paidAmounts(array $rows): array
    {
        $amounts = [];

        foreach ($rows as $row) {
            if (empty($row['free']) && ($row['amount'] ?? '') !== '') {
                $amounts[] = (float) $row['amount'];
            }
        }

        return $amounts;
    }

    /**
     * Turns a legacy free-text price into rows, but only when it says
     * one unambiguous thing — a bare amount, or "free". Anything
     * wordier is left alone for readNotes() to surface rather than
     * being guessed at and silently mispriced.
     *
     * @return array<int, array{type: string, age_from: string, age_under: string, free: bool, amount: string}>
     */
    private static function rowsFromLegacyPrice(string $price): array
    {
        $price = \trim($price);

        if ($price === '') {
            return [];
        }

        if (\preg_match('/^free$/i', $price)) {
            return [['type' => 'adult', 'age_from' => '', 'age_under' => '', 'free' => true, 'amount' => '']];
        }

        if (\preg_match('/^\p{Sc}?\s*(\d+(?:\.\d{1,2})?)$/u', $price, $matches)) {
            return [[
                'type' => 'adult',
                'age_from' => '',
                'age_under' => '',
                'free' => false,
                'amount' => self::sanitizeAmount($matches[1]),
            ]];
        }

        return [];
    }

    private static function sanitizeAmount(string $amount): string
    {
        $amount = \preg_replace('/[^0-9.]/', '', $amount) ?? '';

        if ($amount === '' || ! \is_numeric($amount)) {
            return '';
        }

        $value = \round((float) $amount, 2);

        if ($value < 0) {
            return '';
        }

        return \rtrim(\rtrim(\number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function sanitizeAge(string $age): string
    {
        $age = \trim($age);

        if ($age === '' || ! \ctype_digit($age)) {
            return '';
        }

        return (string) \min((int) $age, 120);
    }
}
