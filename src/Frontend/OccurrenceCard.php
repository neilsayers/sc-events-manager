<?php

namespace SCEventsManager\Frontend;

/**
 * Renders one occurrence as a listing "card", showing only the
 * fields enabled in Settings::listingFields(), in that order. Title
 * is always shown — see ListingFields' docblock.
 */
final class OccurrenceCard
{
    /**
     * @param array<string, mixed> $occurrence
     * @param string[] $fields
     */
    public static function render(array $occurrence, array $fields, int $index = 0, int $total = 1): void
    {
        $classes = \array_merge(['scem-listing-item'], self::modifierClasses($occurrence, $index, $total));
        ?>
        <div class="<?php echo \esc_attr(\implode(' ', $classes)); ?>">
            <?php if (\in_array('featured_image', $fields, true) && $occurrence['featured_image_url'] !== '') : ?>
                <div class="scem-listing-image">
                    <a href="<?php echo \esc_url($occurrence['view_url']); ?>">
                        <img src="<?php echo \esc_url($occurrence['featured_image_url']); ?>" alt="">
                    </a>
                </div>
            <?php endif; ?>

            <div class="scem-listing-content">
                <h3 class="scem-listing-title">
                    <a href="<?php echo \esc_url($occurrence['view_url']); ?>"><?php echo \esc_html($occurrence['title']); ?></a>
                </h3>

                <?php foreach ($fields as $field) : ?>
                    <?php self::renderField($field, $occurrence); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /**
     * BEM modifiers for the `scem-listing-item` block — everything an
     * end user styling a listing with plain CSS would want a hook
     * for, without needing to touch PHP: position in the list, where
     * the occurrence sits relative to today, what kind of event it
     * is, and its type/taxonomy terms.
     *
     * @param array<string, mixed> $occurrence
     * @return string[]
     */
    private static function modifierClasses(array $occurrence, int $index, int $total): array
    {
        $modifiers = [$occurrence['status']];

        if ($index === 0) {
            $modifiers[] = 'first';
        }

        if ($index === $total - 1) {
            $modifiers[] = 'last';
        }

        $modifiers[] = $index % 2 === 0 ? 'odd' : 'even'; // 1-indexed: item 1 (index 0) is odd.

        try {
            $start = new \DateTimeImmutable($occurrence['date']);
            $end = new \DateTimeImmutable($occurrence['end_date'] ?? $occurrence['date']);
            $today = new \DateTimeImmutable('today');
            $tomorrow = $today->modify('+1 day');
            $weekStart = $today->modify('monday this week');
            $weekEnd = $today->modify('sunday this week');
            $monthStart = $today->modify('first day of this month');
            $monthEnd = $today->modify('last day of this month');
            $overlaps = static fn (\DateTimeImmutable $rangeStart, \DateTimeImmutable $rangeEnd): bool => $start <= $rangeEnd && $end >= $rangeStart;

            if ($end < $today) {
                $modifiers[] = 'past';
            } elseif ($start > $today) {
                $modifiers[] = 'future';
            }

            if ($overlaps($today, $today)) {
                $modifiers[] = 'today';
            }

            if ($overlaps($tomorrow, $tomorrow)) {
                $modifiers[] = 'tomorrow';
            }

            if ($overlaps($weekStart, $weekEnd)) {
                $modifiers[] = 'this-week';
            }

            if ($overlaps($monthStart, $monthEnd)) {
                $modifiers[] = 'this-month';
            }
        } catch (\Exception) {
            // Malformed date — skip the date-relative modifiers rather than fatal.
        }

        if ($occurrence['is_multi_day_range']) {
            $modifiers[] = 'multiday';
        }

        if ($occurrence['is_recurring']) {
            $modifiers[] = 'recurring';
        }

        if ($occurrence['ticket_url'] !== '') {
            $modifiers[] = 'has-tickets';
        }

        $modifiers[] = 'type-'.$occurrence['post_type'];

        foreach ($occurrence['terms_by_taxonomy'] ?? [] as $taxonomy => $slugs) {
            if ($slugs !== []) {
                $modifiers[] = 'taxonomy-'.$taxonomy;
            }

            foreach ($slugs as $slug) {
                $modifiers[] = 'term-'.$slug;
            }
        }

        return \array_map(static fn (string $modifier): string => 'scem-listing-item--'.$modifier, $modifiers);
    }

    /**
     * @param array<string, mixed> $occurrence
     */
    private static function renderField(string $field, array $occurrence): void
    {
        switch ($field) {
            case 'featured_image':
                // Rendered outside the text flow in render() above.
                break;

            case 'date_time':
                $when = self::formatDate($occurrence['date'], $occurrence['end_date'] ?? null);

                if ($occurrence['start_time'] !== '' && ! isset($occurrence['end_date'])) {
                    $when .= ' at '.$occurrence['start_time'];
                }

                echo '<p class="scem-listing-datetime">'.\esc_html($when).'</p>';
                break;

            case 'venue':
                if ($occurrence['venue_name'] !== '') {
                    echo '<p class="scem-listing-venue">'.\esc_html($occurrence['venue_name']).'</p>';
                }

                break;

            case 'price':
                if ($occurrence['price'] !== '') {
                    echo '<p class="scem-listing-price">'.\esc_html($occurrence['price']).'</p>';
                }

                // At most one extra line, so a card's height stays
                // the same whether an event has two price tiers or
                // eight — the full breakdown is on the event page.
                if (($occurrence['price_note'] ?? '') !== '') {
                    echo '<p class="scem-listing-price-note">'.\esc_html($occurrence['price_note']).'</p>';
                }

                break;

            case 'status_badge':
                if ($occurrence['status'] !== 'scheduled') {
                    echo '<p class="scem-listing-status scem-listing-status--'.\esc_attr($occurrence['status']).'">'.\esc_html($occurrence['status_label']).'</p>';
                }

                break;

            case 'type_label':
                echo '<p class="scem-listing-type">'.\esc_html($occurrence['type_label']).'</p>';
                break;

            case 'excerpt':
                if ($occurrence['excerpt'] !== '') {
                    echo '<p class="scem-listing-excerpt">'.\esc_html($occurrence['excerpt']).'</p>';
                }

                break;

            case 'ticket_link':
                if ($occurrence['ticket_url'] !== '') {
                    echo '<p class="scem-listing-tickets"><a class="scem-listing-tickets-link" href="'.\esc_url($occurrence['ticket_url']).'" target="_blank" rel="noopener">Tickets</a></p>';
                }

                break;
        }
    }

    private static function formatDate(string $date, ?string $endDate = null): string
    {
        try {
            $start = new \DateTimeImmutable($date);

            if ($endDate === null || $endDate === $date) {
                return $start->format('l j F Y');
            }

            $end = new \DateTimeImmutable($endDate);

            if ($start->format('Y-m') === $end->format('Y-m')) {
                return $start->format('j').'-'.$end->format('j M Y');
            }

            if ($start->format('Y') === $end->format('Y')) {
                return $start->format('j M').' - '.$end->format('j M Y');
            }

            return $start->format('j M Y').' - '.$end->format('j M Y');
        } catch (\Exception) {
            return $date;
        }
    }
}
