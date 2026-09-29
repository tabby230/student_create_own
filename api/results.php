<?php
// api/results.php
require_once __DIR__ . '/config.php';

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$semester = isset($_GET['semester']) ? trim($_GET['semester']) : '';
$grade = isset($_GET['grade']) ? trim($_GET['grade']) : '';
$exam = isset($_GET['exam']) ? trim($_GET['exam']) : ''; // supports exam filter

function calculateGradeDetails($marks) {
    if ($marks >= 95) {
        return ['grade' => 'O', 'color' => '#10b981'];
    } elseif ($marks >= 90) {
        return ['grade' => 'A+', 'color' => '#38bdf8'];
    } elseif ($marks >= 80) {
        return ['grade' => 'A', 'color' => '#a855f7'];
    } elseif ($marks >= 70) {
        return ['grade' => 'B+', 'color' => '#eab308'];
    } elseif ($marks >= 60) {
        return ['grade' => 'B', 'color' => '#f97316'];
    } else {
        return ['grade' => 'C', 'color' => '#ef4444'];
    }
}

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

    if ($semester !== '' && $semester !== 'All' && $semester !== 'All Semesters') {
        $where[] = "sem.semester_number = :semester";
        $params['semester'] = (int)$semester;
    }

    $sql = "
        SELECT 
            s.id,
            s.roll_no AS rollNo,
            s.name,
            d.name AS dept,
            sem.semester_number AS semester,
            ROUND(AVG(m.marks), 1) AS marks
        FROM `students` s
        INNER JOIN `departments` d ON s.department_id = d.id
        INNER JOIN `semesters` sem ON s.semester_id = sem.id
        INNER JOIN `marks` m ON s.id = m.student_id AND s.semester_id = m.semester_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY s.id, s.roll_no, s.name, d.name, sem.semester_number
        ORDER BY marks DESC, s.roll_no ASC
    ";

    $stmt = runQuery($pdo, $sql, $params, 'Results query failed.');
    $rows = $stmt->fetchAll();

    $results = [];
    $rank = 1;
    foreach ($rows as $r) {
        $marks = (float)$r['marks'];
        $gradeInfo = calculateGradeDetails($marks);

        if ($grade !== '' && $grade !== 'All' && $gradeInfo['grade'] !== $grade) {
            continue;
        }

        $results[] = [
            'rank' => $rank++,
            'rollNo' => $r['rollNo'],
            'name' => $r['name'],
            'dept' => $r['dept'],
            'semester' => (int)$r['semester'],
            'marks' => $marks,
            'totalMarks' => 100,
            'grade' => $gradeInfo['grade'],
            'gradeColor' => $gradeInfo['color'],
            'status' => $marks >= 50 ? 'Passed' : 'Failed'
        ];
    }
    sendJsonResponse($results);
} catch (PDOException $e) {
    // Reached only when the CONNECTION itself failed - a failing query is
    // already reported as a 500 by runQuery() above.
    sendDbUnavailable($e);
}
