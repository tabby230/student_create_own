<?php
// api/top-performers.php
require_once __DIR__ . '/config.php';

try {
    $pdo = getDBConnection();

    $sql = "
        SELECT 
            s.id,
            s.name,
            s.avatar,
            d.name AS department,
            sem.semester_number,
            ROUND(AVG(m.marks), 1) AS avg_marks
        FROM `students` s
        INNER JOIN `departments` d ON s.department_id = d.id
        INNER JOIN `semesters` sem ON s.semester_id = sem.id
        INNER JOIN `marks` m ON s.id = m.student_id AND m.semester_id = s.semester_id
        GROUP BY s.id, s.roll_no, s.name, s.avatar, d.name, sem.semester_number
        ORDER BY avg_marks DESC, s.roll_no ASC
        LIMIT 3
    ";

    $stmt = runQuery($pdo, $sql, [], 'Top performers query failed.');
    $rows = $stmt->fetchAll();

    $performers = [];
    $rank = 1;
    $defaultAvatars = [
        1 => 'assets/images/avatar-rithik.png',
        2 => 'assets/images/avatar-sneha.png',
        3 => 'assets/images/avatar-karthik.png'
    ];

    foreach ($rows as $row) {
        $semNum = (int)$row['semester_number'];
        $suffix = 'th';
        if ($semNum === 1) $suffix = 'st';
        elseif ($semNum === 2) $suffix = 'nd';
        elseif ($semNum === 3) $suffix = 'rd';

        $score = (float)$row['avg_marks'];
        $avatar = $row['avatar'];
        if (!$avatar && isset($defaultAvatars[$rank])) {
            $avatar = $defaultAvatars[$rank];
        }

        $performers[] = [
            'rank' => $rank,
            'name' => $row['name'],
            'department' => $row['department'],
            'semester' => $semNum . $suffix . ' Semester',
            'score' => number_format($score, 1) . '%',
            'cgpa' => round($score / 10, 1),
            'avatar' => $avatar ?: 'assets/images/avatar-rithik.png'
        ];
        $rank++;
    }

    if (count($performers) > 0) {
        sendJsonResponse($performers);
    }
} catch (PDOException $e) {
    // The database is the single source of truth. If it is unreachable we
    // report the real failure instead of serving invented data.
    sendDbUnavailable($e);
}
