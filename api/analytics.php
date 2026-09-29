<?php
// api/analytics.php
require_once __DIR__ . '/config.php';

$dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$semester = isset($_GET['semester']) ? trim($_GET['semester']) : '';
$year = isset($_GET['year']) ? trim($_GET['year']) : '';
$dateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$dateTo = isset($_GET['date_to']) ? trim($_GET['date_to']) : '';

try {
    $pdo = dbOrFail();

    // Build filter clauses for students/marks
    $where = ["1=1"];
    $params = [];

    if ($dept !== '' && $dept !== 'All' && $dept !== 'All Departments') {
        $where[] = "(d.name = :dept_name OR d.code = :dept_code)";
        $params['dept_name'] = $dept;
        $params['dept_code'] = $dept;
    }

    if ($semester !== '' && $semester !== 'All' && $semester !== 'All Semesters') {
        $where[] = "sem.semester_number = :semester";
        $params['semester'] = (int)$semester;
    }

    if ($year !== '' && $year !== 'All' && $year !== 'All Years') {
        $where[] = "s.year = :year";
        $params['year'] = (int)$year;
    }

    if ($dateFrom !== '') {
        $where[] = "s.created_at >= :date_from";
        $params['date_from'] = $dateFrom;
    }

    if ($dateTo !== '') {
        $where[] = "s.created_at <= :date_to";
        $params['date_to'] = $dateTo;
    }

    $whereClause = implode(' AND ', $where);

    // 1. Grade distribution from student average marks with filters applied
    $distSql = "
        SELECT 
            COUNT(CASE WHEN avg_m >= 95 THEN 1 END) AS grade_o,
            COUNT(CASE WHEN avg_m >= 90 AND avg_m < 95 THEN 1 END) AS grade_aplus,
            COUNT(CASE WHEN avg_m >= 80 AND avg_m < 90 THEN 1 END) AS grade_a,
            COUNT(CASE WHEN avg_m >= 70 AND avg_m < 80 THEN 1 END) AS grade_bplus,
            COUNT(CASE WHEN avg_m < 70 THEN 1 END) AS grade_b
        FROM (
            SELECT s.id, AVG(m.marks) AS avg_m
            FROM `students` s
            INNER JOIN `departments` d ON s.department_id = d.id
            INNER JOIN `semesters` sem ON s.semester_id = sem.id
            INNER JOIN `marks` m ON s.id = m.student_id AND s.semester_id = m.semester_id
            WHERE $whereClause
            GROUP BY s.id
        ) sm
    ";
    $distStmt = runQuery($pdo, $distSql, $params, 'Analytics query failed (grade distribution).');
    $distRow = $distStmt->fetch();

    $gradeCounts = [
        (int)($distRow['grade_o'] ?? 0),
        (int)($distRow['grade_aplus'] ?? 0),
        (int)($distRow['grade_a'] ?? 0),
        (int)($distRow['grade_bplus'] ?? 0),
        (int)($distRow['grade_b'] ?? 0)
    ];

    // Note: no synthetic baseline. If a filter genuinely matches no marks,
    // the real result is an all-zero distribution and that is what we return.

    // 2. Department statistics (Bar chart & Department cards)
    $deptSql = "
        SELECT 
            d.dept_key AS id,
            d.name,
            d.code,
            d.icon,
            d.color,
            d.bg_color AS bgColor,
            d.border_color AS borderColor,
            d.hod,
            d.faculty_count AS facultyCount,
            d.sparkline,
            COUNT(DISTINCT s.id) AS studentCount,
            ROUND(AVG(m.marks), 1) AS averageScore
        FROM `departments` d
        LEFT JOIN `students` s ON d.id = s.department_id
        LEFT JOIN `marks` m ON s.id = m.student_id AND s.semester_id = m.semester_id
        GROUP BY d.id, d.dept_key, d.name, d.code, d.icon, d.color, d.bg_color, d.border_color, d.hod, d.faculty_count, d.sparkline
        ORDER BY d.id ASC
    ";
    $deptStmt = runQuery($pdo, $deptSql, [], 'Analytics query failed (departments).');
    $deptRows = $deptStmt->fetchAll();

    $deptList = [];
    foreach ($deptRows as $r) {
        $spark = json_decode($r['sparkline'], true);
        if (!is_array($spark)) {
            // No stored sparkline: report null rather than an invented trend.
            $spark = null;
        }
        $deptList[] = [
            'id' => $r['id'],
            'name' => $r['name'],
            'code' => $r['code'],
            'icon' => $r['icon'],
            'color' => $r['color'],
            'bgColor' => $r['bgColor'],
            'borderColor' => $r['borderColor'],
            'hod' => $r['hod'],
            'facultyCount' => (int)$r['facultyCount'],
            'sparkline' => $spark,
            'studentCount' => (int)$r['studentCount'],
            'averageScore' => $r['averageScore'] === null ? null : (float)$r['averageScore']
        ];
    }

    // Monthly trend computed from the real attendance tables. There is no
    // synthetic series here: months with no recorded sessions are simply
    // absent from the chart, and every plotted number is derived from rows
    // that actually exist in attendance_sessions / attendance_records.
    $trendWhere = [];
    $trendParams = [];

    if ($dept !== '' && $dept !== 'All' && $dept !== 'All Departments') {
        $trendWhere[] = "(d.name = :trend_dept OR d.code = :trend_dept_code)";
        $trendParams['trend_dept'] = $dept;
        $trendParams['trend_dept_code'] = $dept;
    }
    if ($year !== '' && $year !== 'All' && $year !== 'All Years') {
        $trendWhere[] = "s.year = :trend_year";
        $trendParams['trend_year'] = (int)$year;
    }
    if ($semester !== '' && $semester !== 'All' && $semester !== 'All Semesters') {
        $trendWhere[] = "ats.semester_id IN (SELECT id FROM semesters WHERE semester_number = :trend_sem)";
        $trendParams['trend_sem'] = (int)$semester;
    }
    if ($dateFrom !== '') {
        $trendWhere[] = "ats.session_date >= :trend_from";
        $trendParams['trend_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $trendWhere[] = "ats.session_date <= :trend_to";
        $trendParams['trend_to'] = $dateTo;
    }
    $trendWhereSql = $trendWhere ? (' WHERE ' . implode(' AND ', $trendWhere)) : '';

    $trendSql = "
        SELECT DATE_FORMAT(ats.session_date, '%Y-%m') AS ym,
               ROUND(SUM(ar.status IN ('present','late')) * 100.0
                     / NULLIF(SUM(ar.status IN ('present','late','absent')), 0), 1) AS avg_pct,
               ROUND(SUM(ar.status IN ('present','late')) * 100.0
                     / NULLIF(COUNT(*), 0), 1) AS present_rate
        FROM `attendance_records` ar
        INNER JOIN `attendance_sessions` ats ON ats.id = ar.session_id
        INNER JOIN `students` s ON s.id = ar.student_id
        INNER JOIN `departments` d ON d.id = s.department_id
        $trendWhereSql
        GROUP BY ym
        ORDER BY ym DESC
        LIMIT 7
    ";
    $trendStmt = runQuery($pdo, $trendSql, $trendParams, 'Analytics query failed (monthly trends).');
    $trendRows = array_reverse($trendStmt->fetchAll());

    $trendLabels = [];
    $trendAverages = [];
    $trendPassRates = [];
    foreach ($trendRows as $trendRow) {
        $trendLabels[] = date('M Y', strtotime($trendRow['ym'] . '-01'));
        $trendAverages[] = $trendRow['avg_pct'] === null ? null : (float)$trendRow['avg_pct'];
        $trendPassRates[] = $trendRow['present_rate'] === null ? null : (float)$trendRow['present_rate'];
    }

    sendJsonResponse([
        'monthlyTrends' => [
            'labels' => $trendLabels,
            'averages' => $trendAverages,
            'passRates' => $trendPassRates
        ],
        'departments' => $deptList,
        'gradeDistribution' => [
            'labels' => ['Grade O (95%+)', 'Grade A+ (90-94%)', 'Grade A (80-89%)', 'Grade B+ (70-79%)', 'Grade B (60-69%)'],
            'counts' => $gradeCounts
        ]
    ]);
} catch (PDOException $e) {
    // Reached only when the CONNECTION itself failed - a failing query is
    // already reported as a 500 by runQuery() above.
    sendDbUnavailable($e);
}
