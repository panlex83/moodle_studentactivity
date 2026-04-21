<?php
// local/studentactivity/lib.php
// All real-data queries for the Student Activity Dashboard.
defined('MOODLE_INTERNAL') || die();

/**
 * Return the list of students to display.
 *
 * Strategy: find every user who has the 'student' archetype role
 * in at least one course context, then apply the optional name filter
 * passed via the URL (?userids=1,2,3).
 *
 * @param array $filter_ids  optional explicit user-id list
 * @return array  of stdClass {id, firstname, lastname, fullname, initials}
 */
function sad_get_students(array $filter_ids = []): array {
    global $DB;

    $role = $DB->get_record('role', ['shortname' => 'student'], 'id', MUST_EXIST);

    $sql = "SELECT DISTINCT u.id, u.firstname, u.lastname
              FROM {user} u
              JOIN {role_assignments} ra ON ra.userid = u.id
             WHERE ra.roleid = :roleid
               AND u.deleted = 0
               AND u.suspended = 0
          ORDER BY u.lastname, u.firstname";

    $rows = $DB->get_records_sql($sql, ['roleid' => $role->id]);

    if (!empty($filter_ids)) {
        $rows = array_filter($rows, fn($r) => in_array((int)$r->id, $filter_ids));
    }

    $students = [];
    foreach ($rows as $r) {
        $r->fullname = trim($r->firstname . ' ' . $r->lastname);
        $r->initials = mb_strtoupper(mb_substr($r->firstname, 0, 1) . mb_substr($r->lastname, 0, 1));
        $students[] = $r;
    }
    return $students;
}

/**
 * Build the full data blob for one student, used by index.php.
 *
 * @param stdClass $student   from sad_get_students()
 * @param int      $from      unix timestamp — period start
 * @param int      $to        unix timestamp — period end  (defaults to now)
 * @return array
 */
function sad_student_data(stdClass $student, int $from, int $to): array {
    global $DB;

    $uid = (int)$student->id;

    // ── 1. Courses this student is enrolled in ──────────────────────────────
    $courses = sad_get_enrolled_courses($uid);
    $course_ids = array_keys($courses);

    // ── 2. Access check: is the student enrolled in *any* course? ───────────
    $has_access = !empty($course_ids);

    // ── 3. Lectures (page / resource / url / folder viewed) ─────────────────
    $lectures_done  = 0;
    $lectures_total = 0;
    if ($course_ids) {
        [$in_sql, $in_params] = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'cid');
        $lectures_total = (int)$DB->count_records_select(
            'course_modules',
            "course $in_sql AND deletioninprogress = 0 AND visible = 1 AND module IN (
                SELECT id FROM {modules} WHERE name IN ('page','resource','url','folder','book')
             )",
            $in_params
        );
        // viewed = log entry with action 'viewed' for those module types
        $sql = "SELECT COUNT(DISTINCT cm.id)
                  FROM {logstore_standard_log} l
                  JOIN {course_modules} cm ON cm.id = l.contextinstanceid
                  JOIN {modules} m ON m.id = cm.module
                 WHERE l.userid = :uid
                   AND l.action = 'viewed'
                   AND l.timecreated BETWEEN :from AND :to
                   AND m.name IN ('page','resource','url','folder','book')
                   AND l.courseid $in_sql";
        $params = array_merge(['uid' => $uid, 'from' => $from, 'to' => $to], $in_params);
        $lectures_done = (int)$DB->count_records_sql($sql, $params);
    }

    // ── 4. Assignments (homework) ────────────────────────────────────────────
    $hw_submitted = 0;
    $hw_total     = 0;
    $deadlines    = [];

    if ($course_ids) {
        [$in_sql, $in_params] = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'cid');

        // Total assignments across enrolled courses
        $hw_total = (int)$DB->count_records_select(
            'assign',
            "course $in_sql AND nosubmissions = 0",
            $in_params
        );

        // Submitted in period
        $sql = "SELECT COUNT(DISTINCT s.assignment)
                  FROM {assign_submission} s
                  JOIN {assign} a ON a.id = s.assignment
                 WHERE s.userid = :uid
                   AND s.status = 'submitted'
                   AND s.timemodified BETWEEN :from AND :to
                   AND a.course $in_sql";
        $params = array_merge(['uid' => $uid, 'from' => $from, 'to' => $to], $in_params);
        $hw_submitted = (int)$DB->count_records_sql($sql, $params);

        // Deadlines: assignments due within next 14 days or overdue in last 7 days
        $dl_from = time() - 7 * 86400;
        $dl_to   = time() + 14 * 86400;
        $sql = "SELECT a.id, a.name, a.duedate, a.course,
                       s.status AS sub_status, s.timemodified AS sub_time
                  FROM {assign} a
             LEFT JOIN {assign_submission} s ON s.assignment = a.id AND s.userid = :uid AND s.status = 'submitted'
                 WHERE a.course $in_sql
                   AND a.duedate > 0
                   AND a.duedate BETWEEN :dl_from AND :dl_to
              ORDER BY a.duedate ASC";
        $params = array_merge(['uid' => $uid, 'dl_from' => $dl_from, 'dl_to' => $dl_to], $in_params);
        $dl_rows = $DB->get_records_sql($sql, $params);
        foreach ($dl_rows as $dr) {
            $days_left = (int)round(($dr->duedate - time()) / 86400);
            $submitted = !empty($dr->sub_status);
            if ($submitted) continue; // skip already submitted
            $deadlines[] = [
                'n'    => shorten_text($dr->name, 40),
                'd'    => userdate($dr->duedate, get_string('strftimedate', 'langconfig')),
                'late' => $days_left < 0,
                'days' => $days_left,
                'sub'  => $courses[$dr->course]->shortname ?? 'Курс',
            ];
        }
    }

    // ── 5. Synchronous lessons (mod_attendance) ──────────────────────────────
    $sync_attended = 0;
    $sync_total    = 0;
    if ($course_ids && sad_attendance_installed()) {
        [$in_sql, $in_params] = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'cid');

        // Total sessions in enrolled courses during period
        $sql = "SELECT COUNT(*)
                  FROM {attendance_sessions} s
                  JOIN {attendance} a ON a.id = s.attendanceid
                 WHERE a.course $in_sql
                   AND s.sessdate BETWEEN :from AND :to";
        $params = array_merge(['from' => $from, 'to' => $to], $in_params);
        $sync_total = (int)$DB->count_records_sql($sql, $params);

        // Sessions where student was marked present/late
        $sql = "SELECT COUNT(*)
                  FROM {attendance_log} al
                  JOIN {attendance_sessions} s ON s.id = al.sessionid
                  JOIN {attendance} a ON a.id = s.attendanceid
                  JOIN {attendance_statuses} st ON st.id = al.statusid
                 WHERE al.studentid = :uid
                   AND a.course $in_sql
                   AND s.sessdate BETWEEN :from AND :to
                   AND st.acronym IN ('P','L','E')";
        $params = array_merge(['uid' => $uid, 'from' => $from, 'to' => $to], $in_params);
        $sync_attended = (int)$DB->count_records_sql($sql, $params);
    }

    // ── 6. Activity by day (time on platform) ───────────────────────────────
    $act_by_day = sad_activity_by_day($uid, $from, $to);

    // Average time per active day (minutes)
    $active_days = array_filter($act_by_day, fn($v) => $v > 0);
    $avg_time = count($active_days) ? (int)round(array_sum($active_days) / count($active_days)) : 0;

    // Streak: consecutive days with activity up to today
    $streak = sad_calc_streak($act_by_day);

    // ── 7. Grades per course ─────────────────────────────────────────────────
    $subjects = sad_get_subjects_with_grades($uid, $course_ids, $courses, $from, $to);

    // ── 8. Class rank (percentile among co-enrolled students) ───────────────
    $pct = sad_class_percentile($uid, $course_ids);

    return [
        'id'       => $uid,
        'name'     => $student->fullname,
        'cl'       => sad_student_cohort($uid),
        'av'       => $student->initials,
        'access'   => $has_access,
        'tot'      => count($course_ids),
        'streak'   => $streak,
        'avg'      => $avg_time,
        'pct'      => $pct,
        'subs'     => $subjects,
        'actW'     => sad_activity_by_day($uid, strtotime('monday this week'), time()),
        'actM'     => array_values($act_by_day),
        'dl'       => array_values($deadlines),
    ];
}

// ────────────────────────────────────────────────────────────────────────────
// HELPERS
// ────────────────────────────────────────────────────────────────────────────

function sad_get_enrolled_courses(int $uid): array {
    global $DB;
    $sql = "SELECT c.id, c.shortname, c.fullname
              FROM {course} c
              JOIN {enrol} e ON e.courseid = c.id
              JOIN {user_enrolments} ue ON ue.enrolid = e.id
             WHERE ue.userid = :uid
               AND ue.status = 0
               AND e.status = 0
               AND c.visible = 1
               AND c.id != :siteid";
    $rows = $DB->get_records_sql($sql, ['uid' => $uid, 'siteid' => SITEID]);
    return $rows; // keyed by course id
}

function sad_attendance_installed(): bool {
    global $DB;
    static $checked = null;
    if ($checked === null) {
        $checked = $DB->get_manager()->table_exists('attendance_sessions');
    }
    return $checked;
}

/**
 * Returns array of minutes-per-day indexed by date string 'Y-m-d'.
 * Approximation: each log event = 2 minutes on platform (standard Moodle heuristic).
 */
function sad_activity_by_day(int $uid, int $from, int $to): array {
    global $DB;

    $sql = "SELECT DATE_FORMAT(FROM_UNIXTIME(timecreated), '%Y-%m-%d') AS day,
                   COUNT(*) AS hits
              FROM {logstore_standard_log}
             WHERE userid = :uid
               AND timecreated BETWEEN :from AND :to
               AND action != 'failed'
          GROUP BY day
          ORDER BY day ASC";
    $rows = $DB->get_records_sql($sql, ['uid' => $uid, 'from' => $from, 'to' => $to]);

    // Build a full day-by-day array (fill gaps with 0)
    $result = [];
    $cur = strtotime(date('Y-m-d', $from));
    $end = strtotime(date('Y-m-d', min($to, time())));
    while ($cur <= $end) {
        $key = date('Y-m-d', $cur);
        $hits = isset($rows[$key]) ? (int)$rows[$key]->hits : 0;
        $result[$key] = min($hits * 2, 180); // cap at 3 hours
        $cur += 86400;
    }
    return $result;
}

function sad_calc_streak(array $act_by_day): int {
    $days = array_reverse(array_values($act_by_day));
    $streak = 0;
    foreach ($days as $v) {
        if ($v > 0) $streak++;
        else break;
    }
    return $streak;
}

function sad_get_subjects_with_grades(int $uid, array $course_ids, array $courses, int $from, int $to): array {
    global $DB;
    if (empty($course_ids)) return [];

    [$in_sql, $in_params] = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'cid');

    // Final grade per course from gradebook
    $sql = "SELECT gi.courseid,
                   AVG(gg.finalgrade) AS avg_grade,
                   AVG(gi.grademax) AS grade_max
              FROM {grade_grades} gg
              JOIN {grade_items} gi ON gi.id = gg.itemid
             WHERE gg.userid = :uid
               AND gi.itemtype = 'course'
               AND gi.courseid $in_sql
          GROUP BY gi.courseid";
    $params = array_merge(['uid' => $uid], $in_params);
    $grade_rows = $DB->get_records_sql($sql, $params);

    // Progress: completed modules / total modules
    $sql_prog = "SELECT cm.course,
                        COUNT(*) AS total,
                        SUM(CASE WHEN cmc.completionstate > 0 THEN 1 ELSE 0 END) AS done
                   FROM {course_modules} cm
              LEFT JOIN {course_modules_completion} cmc ON cmc.coursemoduleid = cm.id AND cmc.userid = :uid
                  WHERE cm.course $in_sql
                    AND cm.deletioninprogress = 0
                    AND cm.visible = 1
                    AND cm.completion > 0
               GROUP BY cm.course";
    $params_prog = array_merge(['uid' => $uid], $in_params);
    $prog_rows = $DB->get_records_sql($sql_prog, $params_prog);

    // Submitted assignments per course in period
    $sql_hw = "SELECT a.course, COUNT(*) AS submitted
                 FROM {assign_submission} s
                 JOIN {assign} a ON a.id = s.assignment
                WHERE s.userid = :uid
                  AND s.status = 'submitted'
                  AND s.timemodified BETWEEN :from AND :to
                  AND a.course $in_sql
             GROUP BY a.course";
    $params_hw = array_merge(['uid' => $uid, 'from' => $from, 'to' => $to], $in_params);
    $hw_rows = $DB->get_records_sql($sql_hw, $params_hw);

    // Lecture views per course in period
    $sql_lec = "SELECT l.courseid, COUNT(DISTINCT l.contextinstanceid) AS viewed
                  FROM {logstore_standard_log} l
                  JOIN {course_modules} cm ON cm.id = l.contextinstanceid
                  JOIN {modules} m ON m.id = cm.module
                 WHERE l.userid = :uid
                   AND l.action = 'viewed'
                   AND l.timecreated BETWEEN :from AND :to
                   AND m.name IN ('page','resource','url','folder','book')
                   AND l.courseid $in_sql
              GROUP BY l.courseid";
    $params_lec = array_merge(['uid' => $uid, 'from' => $from, 'to' => $to], $in_params);
    $lec_rows = $DB->get_records_sql($sql_lec, $params_lec);

    // Attendance per course
    $att_rows = [];
    if (sad_attendance_installed()) {
        $sql_att = "SELECT a.course, COUNT(*) AS attended
                      FROM {attendance_log} al
                      JOIN {attendance_sessions} s ON s.id = al.sessionid
                      JOIN {attendance} a ON a.id = s.attendanceid
                      JOIN {attendance_statuses} st ON st.id = al.statusid
                     WHERE al.studentid = :uid
                       AND a.course $in_sql
                       AND s.sessdate BETWEEN :from AND :to
                       AND st.acronym IN ('P','L','E')
                  GROUP BY a.course";
        $params_att = array_merge(['uid' => $uid, 'from' => $from, 'to' => $to], $in_params);
        $att_rows = $DB->get_records_sql($sql_att, $params_att);
    }

    // Assemble per-course subject rows
    $subjects = [];
    foreach ($course_ids as $cid) {
        $course = $courses[$cid] ?? null;
        if (!$course) continue;

        $prog = $prog_rows[$cid] ?? null;
        $progress = ($prog && $prog->total > 0) ? (int)round($prog->done / $prog->total * 100) : 0;

        $gr = $grade_rows[$cid] ?? null;
        $grade_letter = '—';
        $trend = '—';
        if ($gr && $gr->avg_grade !== null && $gr->grade_max > 0) {
            $pct = $gr->avg_grade / $gr->grade_max * 100;
            if ($pct >= 90) $grade_letter = 'A';
            elseif ($pct >= 75) $grade_letter = 'B';
            elseif ($pct >= 60) $grade_letter = 'C';
            else $grade_letter = 'D';
            $trend = '+' . round($pct - 70); // simplified trend vs baseline 70%
            if (substr($trend, 0, 2) === '+-') $trend = '-' . ltrim(substr($trend, 2));
        }

        $subjects[] = [
            'n' => shorten_text($course->shortname ?: $course->fullname, 20),
            'p' => $progress,
            'l' => (int)($lec_rows[$cid]->viewed ?? 0),
            'h' => (int)($hw_rows[$cid]->submitted ?? 0),
            's' => (int)($att_rows[$cid]->attended ?? 0),
            'g' => $grade_letter,
            't' => $trend,
        ];
    }
    return $subjects;
}

/**
 * Return student's percentile rank among all students enrolled in the same courses.
 * Based on average gradebook grade across shared courses.
 */
function sad_class_percentile(int $uid, array $course_ids): int {
    global $DB;
    if (empty($course_ids)) return 0;

    [$in_sql, $in_params] = $DB->get_in_or_equal($course_ids, SQL_PARAMS_NAMED, 'cid');

    $sql = "SELECT gg.userid, AVG(gg.finalgrade / NULLIF(gi.grademax,0)) AS ratio
              FROM {grade_grades} gg
              JOIN {grade_items} gi ON gi.id = gg.itemid
             WHERE gi.itemtype = 'course'
               AND gi.courseid $in_sql
               AND gg.finalgrade IS NOT NULL
          GROUP BY gg.userid";
    $rows = $DB->get_records_sql($sql, $in_params);
    if (empty($rows)) return 0;

    $ratios = array_map(fn($r) => (float)$r->ratio, $rows);
    sort($ratios);
    $my_ratio = (float)($rows[$uid]->ratio ?? 0);
    $below = count(array_filter($ratios, fn($r) => $r < $my_ratio));
    return (int)round($below / count($ratios) * 100);
}

/**
 * Try to return a cohort name as the student's "class".
 * Falls back to first enrolled course shortname, then '—'.
 */
function sad_student_cohort(int $uid): string {
    global $DB;
    $sql = "SELECT c.name
              FROM {cohort} c
              JOIN {cohort_members} cm ON cm.cohortid = c.id
             WHERE cm.userid = :uid
          ORDER BY c.id ASC
             LIMIT 1";
    $row = $DB->get_record_sql($sql, ['uid' => $uid]);
    if ($row) return $row->name;

    // fallback: first enrolled course shortname
    $sql2 = "SELECT c.shortname
               FROM {course} c
               JOIN {enrol} e ON e.courseid = c.id
               JOIN {user_enrolments} ue ON ue.enrolid = e.id
              WHERE ue.userid = :uid AND c.id != :siteid
           ORDER BY c.id ASC LIMIT 1";
    $row2 = $DB->get_record_sql($sql2, ['uid' => $uid, 'siteid' => SITEID]);
    return $row2 ? $row2->shortname : '—';
}
