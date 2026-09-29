<?php
// api/students.php
// GET - the student directory. Every field is read from the database.
// There is no fallback dataset: if MySQL is unreachable the endpoint
// answers 503 instead of inventing students.
require_once __DIR__ . '/config.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$year = isset($_GET['year']) ? trim($_GET['year']) : '';
$semester = isset($_GET['semester']) ? trim($_GET['semester']) : '';

// Optional pagination parameters
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : null;
$per_page = isset($_GET['per_page']) ? max(1, min(100, (int)$_GET['per_page'])) : null;

try {
    $pdo = getDBConnection();

    $where = ["1=1"];
    $params = [];

    if ($search !== '') {
        $where[] = "(s.name LIKE :search_name OR s.roll_no LIKE :search_roll OR d.name LIKE :search_dept)";
        $params['search_name'] = "%$search%";
        $params['search_roll'] = "%$search%";
        $params['search_dept'] = "%$search%";
    }

    if ($dept !== '' && $dept !== 'All' && $dept !== 'All Departments') {
        $where[] = "(d.name = :dept_name OR d.code = :dept_code)";
        $params['dept_name'] = $dept;
        $params['dept_code'] = $dept;
    }

    if ($year !== '' && $year !== 'All' && $year !== 'All Years') {
        $where[] = "s.year = :year";
        $params['year'] = (int)$year;
    }

    if ($semester !== '' && $semester !== 'All' && $semester !== 'All Semesters') {
        $where[] = "sem.semester_number = :semester";
        $params['semester'] = (int)$semester;
    }

    $sql = "
        SELECT 
            s.id,
            s.name,
            s.roll_no AS rollNo,
            d.name AS dept,
            s.year,
            sem.semester_number AS semester,
            COALESCE(ROUND((
                SELECT SUM(ar.status IN ('present','late')) * 100.0
                       / NULLIF(SUM(ar.status IN ('present','late','absent')), 0)
                FROM `attendance_records` ar
                INNER JOIN `attendance_sessions` ats ON ats.id = ar.session_id
                WHERE ar.student_id = s.id AND ats.semester_id = s.semester_id
            ), 0), 0) AS attendance,
            ROUND(AVG(m.marks), 1) AS marks,
            ROUND(AVG(m.marks) / 10, 1) AS cgpa,
            s.avatar,
            s.email,
            s.phone
        FROM `students` s
        INNER JOIN `departments` d ON s.department_id = d.id
        INNER JOIN `semesters` sem ON s.semester_id = sem.id
        LEFT JOIN `marks` m ON s.id = m.student_id AND m.semester_id = s.semester_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY s.id, s.semester_id, s.name, s.roll_no, d.name, s.year, sem.semester_number, s.avatar, s.email, s.phone
        ORDER BY s.id ASC
    ";

    // Handle pagination if requested
    if ($page !== null && $per_page !== null) {
        $offset = ($page - 1) * $per_page;
        $sql .= " LIMIT " . (int)$offset . ", " . (int)$per_page;
    }

    $stmt = runQuery($pdo, $sql, $params, 'Students query failed.');
    $rows = $stmt->fetchAll();

    $result = [];
    foreach ($rows as $r) {
        $result[] = [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'rollNo' => $r['rollNo'],
            'dept' => $r['dept'],
            'year' => (int)$r['year'],
            'semester' => (int)$r['semester'],
            'attendance' => (int)$r['attendance'],
            'marks' => $r['marks'] === null ? null : (float)$r['marks'],
            'cgpa' => $r['cgpa'] === null ? null : (float)$r['cgpa'],
            'avatar' => $r['avatar'],
            'email' => $r['email'],
            'phone' => $r['phone']
        ];
    }

    sendJsonResponse($result);
} catch (PDOException $e) {
    // Reached only when the CONNECTION itself failed - a failing query is
    // already reported as a 500 by runQuery() above.
    sendDbUnavailable($e);
}
