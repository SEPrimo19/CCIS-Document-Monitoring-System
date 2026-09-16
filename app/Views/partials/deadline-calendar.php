<?php
/**
 * FR-39: the dashboard deadline calendar — a month grid marking the days that
 * carry requirement deadlines in the active academic period, with each marked
 * day linking through to the requirements list.
 *
 * Shared by both dashboards rather than copied into each: the Secretary's and
 * the Faculty member's calendar differ only in WHICH deadlines were fetched
 * (DeadlineCalendar::forSecretary() vs forFaculty()) and where a day links to,
 * so one partial keeps the grid, the accessible names and the empty states
 * from drifting apart between the two roles.
 *
 * Built as a real <table>: a calendar made of <div>s announces as a wall of
 * loose numbers, whereas a table gives every date its row and column headers.
 * No inline style= and no onclick anywhere — the app's CSP (default-src
 * 'self', no 'unsafe-inline') drops both silently — and no script at all: the
 * prev/next controls are plain links carrying ?month=YYYY-MM, so the calendar
 * behaves identically with JavaScript off.
 *
 * Included by require, so it reads these from the calling view's scope:
 *
 * @var array{month:string,label:string,prev:string,next:string,start:string,end:string,weeks:list<list<?string>>} $calendarWindow
 * @var array<string,array{count:int,overdue:int,items:list<array{title:string,doc_type_name:string,is_overdue:int}>}> $calendarDays
 * @var string $calendarToday        today as YYYY-MM-DD
 * @var string $calendarDashboardPath  this role's dashboard, for the month links
 * @var string $calendarTargetPath     the requirements list a marked day opens
 * @var string $calendarTargetLabel    that list's name, for link text and labels
 * @var bool   $calendarHasPeriod      false when no academic period is active
 */

$weekdays = [
    ['Sun', 'Sunday'],
    ['Mon', 'Monday'],
    ['Tue', 'Tuesday'],
    ['Wed', 'Wednesday'],
    ['Thu', 'Thursday'],
    ['Fri', 'Friday'],
    ['Sat', 'Saturday'],
];

$calendarTargetHref = url($calendarTargetPath);
$calendarIsThisMonth = $calendarWindow['month'] === date('Y-m');
?>
<section class="calendar-section" aria-labelledby="calendar-heading">
    <h2 class="section-title" id="calendar-heading">Deadline calendar</h2>

    <div class="calendar-card">
        <div class="calendar-head">
            <?php // Plain links, not buttons: month navigation is a GET of the ?>
            <?php // same dashboard with a different ?month=, so it must keep ?>
            <?php // working with scripting off (and be bookmarkable). The month ?>
            <?php // values come from DeadlineCalendar::window(), which derives ?>
            <?php // them from an already-validated YYYY-MM — the raw query ?>
            <?php // parameter is never echoed back. ?>
            <a class="calendar-nav" rel="prev"
               href="<?= htmlspecialchars(url($calendarDashboardPath) . '?month=' . $calendarWindow['prev']) ?>"
               aria-label="Previous month">
                <span aria-hidden="true">&larr;</span> Prev
            </a>

            <p class="calendar-month"><?= htmlspecialchars($calendarWindow['label']) ?></p>

            <a class="calendar-nav" rel="next"
               href="<?= htmlspecialchars(url($calendarDashboardPath) . '?month=' . $calendarWindow['next']) ?>"
               aria-label="Next month">
                Next <span aria-hidden="true">&rarr;</span>
            </a>
        </div>

        <table class="calendar">
            <caption class="sr-only">
                Requirement deadlines in <?= htmlspecialchars($calendarWindow['label']) ?>.
                Days with a deadline link to <?= htmlspecialchars($calendarTargetLabel) ?>.
            </caption>
            <thead>
                <tr>
                    <?php foreach ($weekdays as [$short, $full]): ?>
                        <?php // abbr= gives assistive technology the full day name ?>
                        <?php // while the column stays three characters wide at ?>
                        <?php // phone width. ?>
                        <th scope="col" abbr="<?= $full ?>"><?= $short ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($calendarWindow['weeks'] as $week): ?>
                    <tr>
                        <?php foreach ($week as $date): ?>
                            <?php if ($date === null): ?>
                                <?php // Padding either side of the month: no date, ?>
                                <?php // so nothing to announce or click. ?>
                                <td class="cal-cell cal-cell-blank"></td>
                            <?php else: ?>
                                <?php
                                $dayNo = (int) substr($date, 8, 2);
                                $isToday = $date === $calendarToday;
                                $day = $calendarDays[$date] ?? null;
                                $count = $day !== null ? $day['count'] : 0;
                                $overdue = $day !== null ? $day['overdue'] : 0;

                                $cellClass = 'cal-cell';
                                if ($isToday) {
                                    $cellClass .= ' is-today';
                                }
                                if ($count > 0) {
                                    $cellClass .= $overdue > 0 ? ' has-overdue' : ' has-deadline';
                                }

                                // Built as one sentence so the link reads the
                                // same way whichever combination of facts is
                                // true of the day.
                                $dayLabel = date('F j, Y', strtotime($date))
                                    . ($isToday ? ' (today)' : '')
                                    . ' — ' . $count . ($count === 1 ? ' deadline' : ' deadlines')
                                    . ($overdue > 0 ? ', ' . $overdue . ' overdue' : '')
                                    . '. Open ' . $calendarTargetLabel . '.';
                                ?>
                                <td class="<?= $cellClass ?>"<?= $isToday ? ' aria-current="date"' : '' ?>>
                                    <?php if ($count > 0): ?>
                                        <?php // aria-label, not the cell text: "15 2" ?>
                                        <?php // announces as two loose numbers with no ?>
                                        <?php // month, no year and no hint of where the ?>
                                        <?php // link goes. ?>
                                        <a class="cal-link" href="<?= htmlspecialchars($calendarTargetHref) ?>"
                                           aria-label="<?= htmlspecialchars($dayLabel) ?>">
                                            <span class="cal-day"><?= $dayNo ?></span>
                                            <span class="cal-count"><?= $count ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span class="cal-day"><?= $dayNo ?></span>
                                        <?php if ($isToday): ?><span class="sr-only"> (today)</span><?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php // Colour alone never carries the meaning of a cell: this legend ?>
        <?php // names each mark, and the list underneath restates every ?>
        <?php // deadline in words. ?>
        <ul class="cal-legend">
            <li class="cal-legend-item"><span class="cal-swatch cal-swatch-today" aria-hidden="true"></span>Today</li>
            <li class="cal-legend-item"><span class="cal-swatch cal-swatch-deadline" aria-hidden="true"></span>Deadline</li>
            <li class="cal-legend-item"><span class="cal-swatch cal-swatch-overdue" aria-hidden="true"></span>Overdue</li>
        </ul>

        <?php if (!$calendarHasPeriod): ?>
            <p class="muted-note cal-note">No active academic period is set, so there are no deadlines to show.</p>
        <?php elseif ($calendarDays === []): ?>
            <p class="muted-note cal-note">No requirement deadlines fall in <?= htmlspecialchars($calendarWindow['label']) ?>.</p>
        <?php else: ?>
            <?php // The client asked for the requirements to be visible AT the ?>
            <?php // deadline date; a 2.5rem grid cell only has room for a count, ?>
            <?php // so the month's deadlines are spelled out here as well. This ?>
            <?php // is also what makes the grid usable on a narrow phone. ?>
            <ul class="cal-list">
                <?php foreach ($calendarDays as $date => $day): ?>
                    <?php foreach ($day['items'] as $item): ?>
                        <li class="cal-list-item">
                            <span class="cal-list-date"><?= htmlspecialchars(date('M j', strtotime($date))) ?></span>
                            <span class="cal-list-title">
                                <?= htmlspecialchars($item['title']) ?>
                                <?php if ($item['is_overdue'] === 1): ?><span class="overdue">Overdue</span><?php endif; ?>
                                <span class="muted-note cal-list-type"><?= htmlspecialchars($item['doc_type_name']) ?></span>
                            </span>
                        </li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p class="cal-foot">
            <?php if (!$calendarIsThisMonth): ?>
                <?php // No ?month= at all, so "this month" is whatever the server ?>
                <?php // says today is rather than a date baked into the link. ?>
                <a class="calendar-nav" href="<?= htmlspecialchars(url($calendarDashboardPath)) ?>">This month</a>
            <?php endif; ?>
            <a class="card-link" href="<?= htmlspecialchars($calendarTargetHref) ?>">Open <?= htmlspecialchars($calendarTargetLabel) ?> &rarr;</a>
        </p>
    </div>
</section>
