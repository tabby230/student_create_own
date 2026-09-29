<?php
// api/student.php
// GET /api/student.php?id=1  OR  /api/student.php?roll=22CS001
// Returns the complete student profile as a single JSON object.
// The database is the only source of truth; there is no fallback dataset.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/avatar-map-lookup.php';

$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$roll = isset($_GET['roll']) ? strtoupper(trim($_GET['roll'])) : '';

if ($id <= 0 && $roll === '') {
    sendJsonError('Provide a student id or roll number.', 400);
    return;
}

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

function gradePoint($marks) {
    if ($marks >= 95) return 10;
    if ($marks >= 90) return 9;
    if ($marks >= 80) return 8;
    if ($marks >= 70) return 7;
    if ($marks >= 60) return 6;
    if ($marks >= 50) return 4;
    return 0;
}

function sgpaFromMarks($subjectRows) {
    $nums = array_map(function ($m) { return (float)$m[2]; }, $subjectRows);
    if (count($nums) === 0) return 0;
    $points = array_sum(array_map('gradePoint', $nums));
    return (float)round($points / count($nums), 2);
}

function buildProfileRecord($p) {
    $curSem       = (int)$p['semester'];
    $currentMarks = array_map(function ($m) { return (float)$m[2]; }, $p['subjects']);
    $ownAvg       = count($currentMarks) ? (float)round(array_sum($currentMarks) / count($currentMarks), 1) : 0.0;

    $academicYear  = (int)date('Y') . '-' . ((int)date('Y') + 1);
    $course        = isset($p['course']) && $p['course'] !== null ? $p['course'] : $p['dept'];
    $courseDur     = (strpos($course, 'B.E') !== false || strpos($course, 'B.Tech') !== false) ? '4 Years' : '3 Years';

    // ---- semesters: ONLY semesters that actually have marks rows.
    //      Semesters with no data are omitted rather than invented. ----
    $semesterRows = [];
    $sgpaList     = [];
    foreach ($p['semesterAggregates'] as $agg) {
        $num   = (float)$agg['avg_marks'];
        $sgpaK = (float)round(gradePoint($num), 2);
        $isCurrent = ((int)$agg['semester_number'] === $curSem);

        $semesterRows[] = [
            'semester'   => (int)$agg['semester_number'],
            'sgpa'       => $sgpaK,
            'percentage' => round($num, 1),
            'credits'    => (int)$agg['credits'],
            'status'     => $isCurrent ? ((count($currentMarks) === 0) ? 'Completed' : 'Ongoing') : 'Completed'
        ];
        $sgpaList[] = $sgpaK;
    }
    $cgpa = count($sgpaList) ? (float)round(array_sum($sgpaList) / count($sgpaList), 2) : null;

    // ---- rank: how many peers from the same dept have a higher average ----
    $peers = $p['peersAvgs'];
    $rank  = 1;
    foreach ($peers as $avg) {
        if ((float)$avg > $ownAvg) $rank++;
    }

    // ---- subjects (current semester) ----
    $subjectRows = [];
    foreach ($p['subjects'] as $m) {
        $g = calculateGradeDetails((float)$m[2]);
        $subjectRows[] = [
            'code'      => $m[0],
            'name'      => $m[1],
            'semester'  => $curSem,
            'marks'     => (float)$m[2],
            'grade'     => $g['grade'],
            'status'    => (float)$m[2] >= 50 ? 'Passed' : 'Failed'
        ];
    }

    // ---- attendance series, supplied by the caller straight from
    //      attendance_records (see attendanceSeries() in config.php) ----
    $attBySem   = $p['attendanceBySemester'];
    $attMonthly = $p['attendanceMonthly'];

    // ---- semester results: built from real `marks` rows only ----
    $bySemSubjects = [];
    foreach ($p['semesterSubjects'] as $s) {
        $bySemSubjects[(int)$s['semester_number']][] = $s;
    }

    $resultRows = [];
    foreach ($p['semesterAggregates'] as $agg) {
        $semNo = (int)$agg['semester_number'];
        $subjs = [];
        foreach ($bySemSubjects[$semNo] ?? [] as $s) {
            $mk = (float)$s['marks'];
            $subjs[] = [
                'name'   => $s['name'],
                'marks'  => $mk,
                'grade'  => calculateGradeDetails($mk)['grade'],
                'status' => $mk >= 50 ? 'Passed' : 'Failed'
            ];
        }
        if (count($subjs) === 0) continue;

        $pctRes = (float)$agg['avg_marks'];
        $resultRows[] = [
            'semester'   => $semNo,
            'subjects'   => $subjs,
            'total'      => (float)$agg['total_marks'],
            'percentage' => round($pctRes, 1),
            'grade'      => calculateGradeDetails($pctRes)['grade'],
            'rank'       => $p['semesterRanks'][$semNo] ?? null
        ];
    }

    // ---- documents ----
    $docRows = [];
    foreach ($p['documents'] as $d) {
        $docRows[] = [
            'id'         => (int)$d[0],
            'name'       => $d[1],
            'type'       => $d[2],
            'status'     => $d[3],
            'uploadedOn' => $d[4]
        ];
    }

    return [
        'id'                => (int)$p['id'],
        'name'              => $p['name'],
        'rollNo'            => $p['rollNo'],
        'registerNo'        => isset($p['registerNo']) ? $p['registerNo'] : null,
        'dept'              => $p['dept'],
        'deptCode'          => $p['deptCode'],
        'course'            => $course,
        'section'           => isset($p['section']) ? $p['section'] : null,
        'year'              => (int)$p['year'],
        'semester'          => $curSem,
        'photo'             => isset($p['photo']) ? $p['photo'] : null,
        'avatar'            => isset($p['avatar']) ? $p['avatar'] : null,
        'email'             => isset($p['email']) ? $p['email'] : null,
        'phone'             => isset($p['phone']) ? $p['phone'] : null,
        'dob'               => isset($p['dob']) ? $p['dob'] : null,
        'gender'            => isset($p['gender']) ? $p['gender'] : null,
        'bloodGroup'        => isset($p['bloodGroup']) ? $p['bloodGroup'] : null,
        'category'          => isset($p['category']) ? $p['category'] : null,
        'address'           => isset($p['address']) ? $p['address'] : null,
        'languages'         => isset($p['languages']) ? $p['languages'] : null,
        'guardianName'      => isset($p['guardianName']) ? $p['guardianName'] : null,
        'guardianPhone'     => isset($p['guardianPhone']) ? $p['guardianPhone'] : null,
        'emergencyContact'  => isset($p['emergencyContact']) ? $p['emergencyContact'] : null,
        'relationship'      => isset($p['relationship']) ? $p['relationship'] : null,
        'admissionYear'     => isset($p['admissionYear']) ? (int)$p['admissionYear'] : null,
        'courseDuration'    => $courseDur,
        'academicYear'      => $academicYear,
        'stats' => [
            'cgpa'       => $cgpa,
            'sgpa'       => sgpaFromMarks($p['subjects']),
            'attendance' => (float)$p['attendancePct'],
            'credits'    => isset($p['credits']) && $p['credits'] !== null ? (int)$p['credits'] : null,
            'backlogs'   => isset($p['backlogs']) ? (int)$p['backlogs'] : null,
            'rank'       => $rank
        ],
        'semesters'           => $semesterRows,
        'subjects'            => $subjectRows,
        'attendanceBySemester'=> $attBySem,
        'attendanceMonthly'   => $attMonthly,
        'results'             => $resultRows,
        'documents'           => $docRows
    ];
}

// ---------------------------------------------------------------------
// 1) Try the real database
// ---------------------------------------------------------------------
try {
    $pdo = dbOrFail();

    $sql = "SELECT s.*, d.name AS dept, d.code AS deptCode, sem.semester_number AS semester
            FROM `students` s
            INNER JOIN `departments` d ON s.department_id = d.id
            INNER JOIN `semesters` sem ON s.semester_id = sem.id
            WHERE s.id = :id OR s.roll_no = :roll
            LIMIT 1";
    $stmt = runQuery($pdo, $sql, ['id' => $id, 'roll' => $roll], 'Student lookup query failed.');
    $row = $stmt->fetch();

    if (!$row) {
        sendJsonError('Student not found.', 404);
        return;
    }

    // Current semester subjects + marks (real rows from the marks table)
    $subs = [];
    $ms = runQuery($pdo,
        "SELECT sub.subject_code, sub.name, m.marks
         FROM `marks` m
         INNER JOIN `subjects` sub ON sub.id = m.subject_id
         WHERE m.student_id = :sid
         ORDER BY sub.id",
        ['sid' => (int)$row['id']],
        'Student query failed (current semester subjects).');
    foreach ($ms->fetchAll() as $m) {
        $subs[] = [$m['subject_code'], $m['name'], (float)$m['marks']];
    }

    // Attendance, computed from real attendance_records for this student.
    // Current-semester figure plus the full per-semester / per-month series.
    $attSeries   = attendanceSeries($pdo, (int)$row['id']);
    $att         = $attSeries['overall'];
    $attBySem    = $attSeries['bySemester'];
    $attMonthly  = $attSeries['byMonth'];

    // Real per-semester marks aggregates (no invented semesters).
    $semAggStmt = runQuery($pdo,
        "SELECT sem.semester_number,
                AVG(m.marks)  AS avg_marks,
                SUM(m.marks)  AS total_marks,
                COUNT(*)      AS subject_count,
                SUM(sub.credits) AS credits
         FROM `marks` m
         INNER JOIN `semesters` sem ON sem.id = m.semester_id
         INNER JOIN `subjects`  sub ON sub.id = m.subject_id
         WHERE m.student_id = :sid
         GROUP BY sem.semester_number
         ORDER BY sem.semester_number ASC",
        ['sid' => (int)$row['id']],
        'Student query failed (semester aggregates).');
    $semAggregates = $semAggStmt->fetchAll();

    // Real subject-level marks per semester, for the result rows.
    $semSubStmt = runQuery($pdo,
        "SELECT sem.semester_number, sub.subject_code, sub.name, m.marks
         FROM `marks` m
         INNER JOIN `semesters` sem ON sem.id = m.semester_id
         INNER JOIN `subjects`  sub ON sub.id = m.subject_id
         WHERE m.student_id = :sid
         ORDER BY sem.semester_number ASC, sub.id ASC",
        ['sid' => (int)$row['id']],
        'Student query failed (subject marks).');
    $semSubjects = $semSubStmt->fetchAll();

    // Real rank per semester: how many peers in the same department scored higher.
    $rankStmt = runQuery($pdo,
        "SELECT sem.semester_number, COUNT(*) + 1 AS rnk
         FROM (
             SELECT m.student_id, m.semester_id, AVG(m.marks) AS avg_m
             FROM `marks` m
             INNER JOIN `students` st ON st.id = m.student_id
             WHERE st.department_id = :dept
             GROUP BY m.student_id, m.semester_id
         ) peer
         INNER JOIN `semesters` sem ON sem.id = peer.semester_id
         WHERE peer.avg_m > (
             SELECT AVG(m2.marks)
             FROM `marks` m2
             WHERE m2.student_id = :sid AND m2.semester_id = peer.semester_id
         )
         GROUP BY sem.semester_number",
        ['dept' => (int)$row['department_id'], 'sid' => (int)$row['id']],
        'Student query failed (rank).');
    $semesterRanks = [];
    foreach ($rankStmt->fetchAll() as $r) {
        $semesterRanks[(int)$r['semester_number']] = (int)$rnk = (int)$r['rnk'];
    }

    // Documents
    $docs = [];
    $ds = runQuery($pdo, "SELECT `id`, `doc_name`, `doc_type`, `status`, `uploaded_on` FROM `student_documents` WHERE `student_id` = :sid ORDER BY `id`", ['sid' => (int)$row['id']], 'Student query failed (documents).');
    foreach ($ds->fetchAll() as $d) {
        $docs[] = [(int)$d['id'], $d['doc_name'], $d['doc_type'], $d['status'], $d['uploaded_on']];
    }

    // Peers average (same department) for rank computation
    $peers = [];
    $rq = runQuery($pdo,
        "SELECT s.department_id, ROUND(AVG(m.marks), 2) AS avg
         FROM `students` s
         INNER JOIN `marks` m ON m.student_id = s.id
         GROUP BY s.id, s.department_id",
        [],
        'Student query failed (peer averages).');
    foreach ($rq->fetchAll() as $r) {
        if ((int)$r['department_id'] === (int)$row['department_id']) {
            $peers[] = (float)$r['avg'];
        }
    }

    $profile = buildProfileRecord([
        'id'              => (int)$row['id'],
        'name'            => $row['name'],
        'rollNo'          => $row['roll_no'],
        'registerNo'      => $row['register_no'] ?? null,
        'dept'            => $row['dept'],
        'deptCode'        => $row['deptCode'],
        'course'          => $row['course'] ?? null,
        'section'         => $row['section'] ?? null,
        'year'            => (int)$row['year'],
        'semester'        => (int)$row['semester'],
        'photo'           => $row['photo'] ?? null,
        'avatar'          => $row['avatar'] ?? null,
        'email'           => $row['email'] ?? null,
        'phone'           => $row['phone'] ?? null,
        'dob'             => $row['dob'] ?? null,
        'gender'          => $row['gender'] ?? null,
        'bloodGroup'      => $row['blood_group'] ?? null,
        'category'        => $row['category'] ?? null,
        'address'         => $row['address'] ?? null,
        'languages'       => $row['languages_known'] ?? null,
        'guardianName'    => $row['guardian_name'] ?? null,
        'guardianPhone'   => $row['guardian_phone'] ?? null,
        'emergencyContact'=> $row['emergency_contact'] ?? null,
        'relationship'    => $row['relationship'] ?? null,
        'admissionYear'   => $row['admission_year'] ?? null,
        'credits'         => $row['credits'] ?? null,
        'backlogs'        => $row['backlogs'] ?? null,
        'subjects'        => $subs,
        'attendancePct'   => $att,
        'attendanceBySemester' => $attBySem,
        'attendanceMonthly'    => $attMonthly,
        'semesterAggregates'   => $semAggregates,
        'semesterSubjects'     => $semSubjects,
        'semesterRanks'        => $semesterRanks,
        'peersAvgs'       => $peers,
        'documents'       => $docs
    ]);

    sendJsonResponse($profile);
} catch (PDOException $e) {
    // Reached only when the CONNECTION itself failed - a failing query is
    // already reported as a 500 by runQuery() above.
    sendDbUnavailable($e);
}
