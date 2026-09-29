<?php
// api/semesters.php
// Reference data for the admin forms: the real semesters from the database,
// and (optionally) the real subjects belonging to one semester.
//
// GET /api/semesters.php
//     -> { success, semesters: [ { id, semesterNumber, academicYear, term, studentCount } ] }
//
// GET /api/semesters.php?withSubjects=1&semesterId=6
//     -> adds subjects: [ { id, code, name, credits, departmentId, departmentName } ]
//
// This endpoint only ever reports what is stored in MariaDB. There is no
// hardcoded semester or subject list anywhere in this project.

require_once __DIR__ . '/config.php';

if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') {
    requireMethod('GET');
}

try {
    $pdo = dbOrFail();

    $sql = "
        SELECT sem.id,
               sem.semester_number,
               sem.academic_year,
               sem.term,
               COUNT(s.id) AS student_count
        FROM `semesters` sem
        LEFT JOIN `students` s ON s.semester_id = sem.id
        GROUP BY sem.id, sem.semester_number, sem.academic_year, sem.term
        ORDER BY sem.semester_number ASC
    ";
    $rows = $pdo->query($sql)->fetchAll();

    $semesters = [];
    foreach ($rows as $r) {
        $semesters[] = [
            'id'           => (int)$r['id'],
            'semesterNumber' => (int)$r['semester_number'],
            'academicYear' => $r['academic_year'],
            'term'         => $r['term'],
            'studentCount' => (int)$r['student_count']
        ];
    }

    $response = ['success' => true, 'semesters' => $semesters];

    if (isset($_GET['withSubjects']) && $_GET['withSubjects'] !== '' && $_GET['withSubjects'] !== '0') {
        $semesterId = isset($_GET['semesterId']) ? (int)$_GET['semesterId'] : 0;

        $subSql = "
            SELECT sub.id, sub.subject_code, sub.name, sub.credits,
                   sub.department_id, d.name AS department_name
            FROM `subjects` sub
            LEFT JOIN `departments` d ON d.id = sub.department_id
        ";
        $subParams = [];
        if ($semesterId > 0) {
            $subSql .= ' WHERE sub.semester_id = :sem';
            $subParams['sem'] = $semesterId;
        }
        $subSql .= ' ORDER BY sub.subject_code ASC';

        $subStmt = $pdo->prepare($subSql);
        $subStmt->execute($subParams);

        $subjects = [];
        foreach ($subStmt->fetchAll() as $r) {
            $subjects[] = [
                'id'             => (int)$r['id'],
                'code'           => $r['subject_code'],
                'name'           => $r['name'],
                'credits'        => $r['credits'] === null ? null : (int)$r['credits'],
                'departmentId'   => $r['department_id'] === null ? null : (int)$r['department_id'],
                'departmentName' => $r['department_name']
            ];
        }
        $response['subjects'] = $subjects;
    }

    sendJsonResponse($response);
} catch (PDOException $e) {
    sendPdoError($e);
}
