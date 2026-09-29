<?php
// api/export.php
require_once __DIR__ . '/config.php';

// Check if format or filters are requested
$dept = isset($_GET['dept']) ? trim($_GET['dept']) : '';
$semester = isset($_GET['semester']) ? trim($_GET['semester']) : '';
$grade = isset($_GET['grade']) ? trim($_GET['grade']) : '';
$format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';

function getGrade($marks) {
    if ($marks >= 95) return 'O';
    if ($marks >= 90) return 'A+';
    if ($marks >= 80) return 'A';
    if ($marks >= 70) return 'B+';
    if ($marks >= 60) return 'B';
    return 'C';
}

$results = [];

try {
    $pdo = getDBConnection();

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

    $sql = "
        SELECT 
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

    $stmt = runQuery($pdo, $sql, $params, 'Results export query failed.');
    $rows = $stmt->fetchAll();

    $rank = 1;
    foreach ($rows as $r) {
        $m = (float)$r['marks'];
        $g = getGrade($m);
        if ($grade !== '' && $grade !== 'All' && $g !== $grade) {
            continue;
        }

        $results[] = [
            'Rank' => $rank++,
            'Roll Number' => $r['rollNo'],
            'Student Name' => $r['name'],
            'Department' => $r['dept'],
            'Semester' => (int)$r['semester'],
            'Marks (%)' => $m,
            'Total Marks' => 100,
            'Grade' => $g,
            'Status' => $m >= 50 ? 'Passed' : 'Failed'
        ];
    }
} catch (PDOException $e) {
    // No invented CSV: a failed export must fail loudly.
    sendDbUnavailable($e);
}

// Generate CSV output
$filename = 'edutrack_results_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for Excel compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

if (!empty($results)) {
    // Header row
    fputcsv($output, array_keys($results[0]));

    // Data rows
    foreach ($results as $row) {
        fputcsv($output, $row);
    }
} else {
    fputcsv($output, ['Rank', 'Roll Number', 'Student Name', 'Department', 'Semester', 'Marks (%)', 'Total Marks', 'Grade', 'Status']);
}

fclose($output);
exit();
