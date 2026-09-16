<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;
use PDO;

/**
 * The dashboard deadline calendar (FR-39): which days of a given month carry
 * requirement deadlines in the active academic period, for the month grid on
 * both the Secretary and the Faculty dashboard.
 *
 * Two finders, one per audience, because the two questions are genuinely
 * different and the difference belongs in SQL:
 *
 *   forSecretary() — every requirement in the period. The Secretary publishes
 *                    them, so every deadline is theirs to see.
 *   forFaculty()   — driven from the faculty member's OWN `submissions` rows,
 *                    bound to their user_id. Those rows are the stored result
 *                    of the audience rules (FR-35: all_faculty / program /
 *                    individual), so reading them is the one source that
 *                    cannot drift from what the publish + backfill steps
 *                    actually decided. Nothing is narrowed in PHP afterwards.
 *
 * Unlike its neighbours this model also carries the month arithmetic
 * (month(), window()). That is deliberate: the controller needs the month's
 * first and last day to bind into the query, and the view needs that very same
 * window to lay out the grid and build the prev/next links — so there is
 * exactly one definition of "the month being shown" rather than three that can
 * disagree.
 */
final class DeadlineCalendar
{
    /** A month as the `month` query parameter must spell it: YYYY-MM. */
    private const MONTH_PATTERN = '/^\d{4}-(0[1-9]|1[0-2])$/';

    /**
     * The month to display, as YYYY-MM.
     *
     * $raw comes straight off the query string, so it is untrusted in every
     * sense: it may be absent, an array (`?month[]=x`, which is why this takes
     * mixed and checks is_string rather than casting), or an injection
     * attempt. Anything that is not an exactly well-formed YYYY-MM string
     * falls back to the current month, so the value that leaves here is always
     * safe to bind, to do date arithmetic on, and to put in a link. The raw
     * input is never echoed anywhere.
     */
    public static function month(mixed $raw): string
    {
        if (!is_string($raw)) {
            return date('Y-m');
        }

        $candidate = trim($raw);

        return preg_match(self::MONTH_PATTERN, $candidate) === 1 ? $candidate : date('Y-m');
    }

    /**
     * Everything the grid and the query need about one month: the date window
     * to bind, the neighbouring months for the prev/next links, a human label,
     * and the weeks laid out Sunday-first with null for the padding cells
     * either side of the month.
     *
     * $month must already have been through month() — this does no validation
     * of its own, the same way Requirement::create() trusts its caller.
     *
     * @return array{month:string,label:string,prev:string,next:string,start:string,end:string,weeks:list<list<?string>>}
     */
    public static function window(string $month): array
    {
        // '!' zeroes the time fields, so no part of today's clock leaks into
        // the arithmetic below.
        $first = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        $last = $first->modify('last day of this month');

        $daysInMonth = (int) $first->format('t');
        // 0 = Sunday.
        $leadingBlanks = (int) $first->format('w');

        $cells = array_fill(0, $leadingBlanks, null);
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $cells[] = sprintf('%s-%02d', $month, $day);
        }
        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        return [
            'month' => $month,
            'label' => $first->format('F Y'),
            // Anchored on the 1st, so these can never skid past a short month
            // the way '+1 month' does from a 31st.
            'prev'  => $first->modify('-1 month')->format('Y-m'),
            'next'  => $first->modify('+1 month')->format('Y-m'),
            'start' => $first->format('Y-m-d'),
            'end'   => $last->format('Y-m-d'),
            'weeks' => array_chunk($cells, 7),
        ];
    }

    /**
     * Every requirement in the period whose deadline falls inside the month
     * window — the Secretary's calendar (FR-39).
     *
     * `is_overdue` mirrors Submission::overdueCountForPeriod(): past deadline
     * AND at least one submission against it still not Approved, so the
     * calendar says "overdue" about exactly what the dashboard's Overdue card
     * is already counting.
     *
     * @return list<array{deadline:string,title:string,doc_type_name:string,is_overdue:int}>
     */
    public static function forSecretary(int $periodId, string $monthStart, string $monthEnd, string $today): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT r.deadline, r.title, dt.name AS doc_type_name,
                    CASE WHEN r.deadline < :today
                              AND EXISTS (SELECT 1 FROM submissions s
                                           WHERE s.requirement_id = r.requirement_id
                                             AND s.status <> 'Approved')
                         THEN 1 ELSE 0 END AS is_overdue
             FROM requirements r
             INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
             WHERE r.period_id = :period_id
               AND r.deadline IS NOT NULL
               AND r.deadline BETWEEN :month_start AND :month_end
             ORDER BY r.deadline ASC, r.title ASC"
        );
        $stmt->execute([
            ':period_id'   => $periodId,
            ':month_start' => $monthStart,
            ':month_end'   => $monthEnd,
            ':today'       => $today,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * The deadlines one faculty member is actually on the hook for, inside the
     * month window — the Faculty calendar (FR-39).
     *
     * Driven from `submissions` rather than from `requirements` plus a second
     * copy of the FR-35 audience rules: a submission row exists if and only if
     * the requirement targets this faculty member, so `s.faculty_id =
     * :faculty_id` IS the audience filter, bound to their own session id and
     * resolved by the database. A requirement aimed at another program, or at
     * a different named individual, has no row here and therefore cannot reach
     * the grid at all.
     *
     * `is_overdue` is the same test the checklist view applies per row (FR-6,
     * FR-20): past deadline, and this faculty member's own copy not Approved.
     *
     * @return list<array{deadline:string,title:string,doc_type_name:string,is_overdue:int}>
     */
    public static function forFaculty(int $facultyId, int $periodId, string $monthStart, string $monthEnd, string $today): array
    {
        $stmt = self::pdo()->prepare(
            "SELECT r.deadline, r.title, dt.name AS doc_type_name,
                    CASE WHEN r.deadline < :today AND s.status <> 'Approved'
                         THEN 1 ELSE 0 END AS is_overdue
             FROM submissions s
             INNER JOIN requirements r ON r.requirement_id = s.requirement_id
             INNER JOIN document_types dt ON dt.doc_type_id = r.doc_type_id
             WHERE s.faculty_id = :faculty_id
               AND r.period_id = :period_id
               AND r.deadline IS NOT NULL
               AND r.deadline BETWEEN :month_start AND :month_end
             ORDER BY r.deadline ASC, r.title ASC"
        );
        $stmt->execute([
            ':faculty_id'  => $facultyId,
            ':period_id'   => $periodId,
            ':month_start' => $monthStart,
            ':month_end'   => $monthEnd,
            ':today'       => $today,
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Regroup either finder's rows by deadline date, for the grid: one entry
     * per day that carries at least one deadline. Presentation only — WHICH
     * rows are in hand was settled in SQL above, and nothing here drops any.
     *
     * @param list<array{deadline:string,title:string,doc_type_name:string,is_overdue:int}> $rows
     * @return array<string,array{count:int,overdue:int,items:list<array{title:string,doc_type_name:string,is_overdue:int}>}>
     */
    public static function byDay(array $rows): array
    {
        $days = [];

        foreach ($rows as $row) {
            $date = (string) $row['deadline'];

            if (!isset($days[$date])) {
                $days[$date] = ['count' => 0, 'overdue' => 0, 'items' => []];
            }

            $days[$date]['count']++;
            $days[$date]['overdue'] += (int) $row['is_overdue'] === 1 ? 1 : 0;
            $days[$date]['items'][] = [
                'title'         => (string) $row['title'],
                'doc_type_name' => (string) $row['doc_type_name'],
                'is_overdue'    => (int) $row['is_overdue'],
            ];
        }

        return $days;
    }

    private static function pdo(): PDO
    {
        static $config = null;
        if ($config === null) {
            $config = require dirname(__DIR__, 2) . '/config/config.php';
        }

        return Database::connection($config['db']);
    }
}
