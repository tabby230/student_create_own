<?php
// api/attendance.php  (read-only)
// GET - every figure below is computed from attendance_records via SQL.
//       Nothing is synthesised.
//
//   ?action=history   studentId, semesterId, subjectId, date, source, page, perPage, sort
//   ?action=roster    semesterId, departmentId            -> students to mark
//   ?action=student   studentId                           -> per-semester / per-month series
//   ?action=sessions  semesterId, subjectId, date, source  -> session list with tallies
require_once __DIR__ . '/config.php';

$action = isset($_GET['action']) ? strtolower(trim($_GET['action'])) : 'history';

try {
    $pdo = dbOrFail();

    switch ($action) {

        // ---------------------------------------------------------
        // The roster of students an admin can mark for a session.
        // ---------------------------------------------------------
        case 'roster': {
            $semesterId = isset($_GET['semesterId']) ? (int)$_GET['semesterId'] : 0;
            if ($semesterId <= 0) {
                sendJsonError('semesterId is required.', 400, ['field' => 'semesterId']);
            }
            $departmentId = isset($_GET['departmentId']) && $_GET['departmentId'] !== ''
                ? (int)$_GET['departmentId'] : null;

            $sql = "SELECT s.id, s.roll_no, s.name, s.year, d.name AS dept
                    FROM students s
                    INNER JOIN departments d ON d.id = s.department_id
                    WHERE s.semester_id = :sem";
            $params = ['sem' => $semesterId];
            if ($departmentId !== null && $departmentId > 0) {
                $sql .= " AND s.department_id = :dept";
                $params['dept'] = $departmentId;
            }
            $sql .= " ORDER BY s.roll_no ASC";

            $st = $pdo->prepare($sql);
            $st->execute($params);

            $roster = [];
            foreach ($st->fetchAll() as $r) {
                $roster[] = [
                    'studentId' => (int)$r['id'],
                    'rollNo'    => $r['roll_no'],
                    'name'      => $r['name'],
                    'year'      => (int)$r['year'],
                    'dept'      => $r['dept']
                ];
            }

            sendJsonResponse(['success' => true, 'count' => count($roster), 'roster' => $roster]);
        }

        // ---------------------------------------------------------
        // Per-semester and per-month series for one student.
        // ---------------------------------------------------------
        case 'student': {
            $studentId = isset($_GET['studentId']) ? (int)$_GET['studentId'] : 0;
            if ($studentId <= 0) {
                sendJsonError('studentId is required.', 400, ['field' => 'studentId']);
            }

            $chk = $pdo->prepare("SELECT id, name, roll_no FROM students WHERE id = :id");
            $chk->execute(['id' => $studentId]);
            $student = $chk->fetch();
            if (!$student) {
                sendJsonError("Student $studentId not found.", 404, ['field' => 'studentId']);
            }

            $series = attendanceSeries($pdo, $studentId);

            // Recent individual records so an admin can see what was actually marked.
            $rec = $pdo->prepare(
                "SELECT ses.id AS sessionId, ses.session_date, ses.source,
                        sub.subject_code, sub.name AS subject_name,
                        sem.semester_number, ar.status, ar.marked_at
                 FROM attendance_records ar
                 INNER JOIN attendance_sessions ses ON ses.id = ar.session_id
                 INNER JOIN semesters sem ON sem.id = ses.semester_id
                 LEFT JOIN subjects sub ON sub.id = ses.subject_id
                 WHERE ar.student_id = :sid
                 ORDER BY ses.session_date DESC, ar.id DESC
                 LIMIT 100"
            );
            $rec->execute(['sid' => $studentId]);

            $records = [];
            foreach ($rec->fetchAll() as $r) {
                $records[] = [
                    'sessionId'    => (int)$r['sessionId'],
                    'sessionDate'  => $r['session_date'],
                    'subjectCode'  => $r['subject_code'],
                    'subjectName'  => $r['subject_name'],
                    'semester'     => (int)$r['semester_number'],
                    'status'       => $r['status'],
                    'source'       => $r['source'],
                    'markedAt'     => $r['marked_at']
                ];
            }

            sendJsonResponse([
                'success' => true,
                'student' => [
                    'id'     => (int)$student['id'],
                    'name'   => $student['name'],
                    'rollNo' => $student['roll_no']
                ],
                'overall'    => $series['overall'],
                'bySemester' => $series['bySemester'],
                'byMonth'    => $series['byMonth'],
                'records'    => $records
            ]);
        }

        // ---------------------------------------------------------
        // Session list with real tallies.
        // ---------------------------------------------------------
        case 'sessions': {
            $semesterId = isset($_GET['semesterId']) ? (int)$_GET['semesterId'] : 0;
            $subjectId  = isset($_GET['subjectId'])  ? (int)$_GET['subjectId']  : 0;
            $studentId  = isset($_GET['studentId'])  ? (int)$_GET['studentId']  : 0;
            $source     = isset($_GET['source'])     ? trim((string)$_GET['source']) : '';
            $date       = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$_GET['date'])
                            ? (string)$_GET['date'] : '';

            $page    = isset($_GET['page'])    ? max(1, (int)$_GET['page']) : 1;
            $perPage = isset($_GET['perPage']) ? max(1, min(500, (int)$_GET['perPage'])) : 25;

            // Whitelist: never interpolate a client string into ORDER BY.
            $SORTABLE = [
                'date'     => 'ses.session_date',
                'semester' => 'sem.semester_number',
                'subject'  => 'sub.subject_code',
                'source'   => 'ses.source'
            ];
            $sortKey = isset($_GET['sort']) && isset($SORTABLE[$_GET['sort']]) ? $_GET['sort'] : 'date';
            $sortDir = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';
            $orderBy = $SORTABLE[$sortKey] . ' ' . $sortDir . ', ses.id ' . $sortDir;

            $where  = ['1=1'];
            $params = [];

            if ($semesterId > 0) { $where[] = "ses.semester_id = :sem";  $params['sem'] = $semesterId; }
            if ($subjectId  > 0) { $where[] = "ses.subject_id = :subj";  $params['subj'] = $subjectId; }
            if ($date !== '')    { $where[] = "ses.session_date = :dt";  $params['dt'] = $date; }
            if (in_array($source, ['legacy', 'manual', 'import'], true)) {
                $where[] = "ses.source = :src";
                $params['src'] = $source;
            }
            if ($studentId > 0) {
                $where[] = "EXISTS (SELECT 1 FROM attendance_records arx
                                   WHERE arx.session_id = ses.id AND arx.student_id = :stu)";
                $params['stu'] = $studentId;
            }

            $whereSql = implode(' AND ', $where);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM attendance_sessions ses
                                  INNER JOIN semesters sem ON sem.id = ses.semester_id
                                  LEFT JOIN subjects sub ON sub.id = ses.subject_id
                                  WHERE $whereSql");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $sql = "SELECT ses.id, ses.session_key, ses.subject_id, ses.session_date,
                           ses.taken_by, ses.source, ses.note,
                           sem.semester_number,
                           sub.subject_code, sub.name AS subject_name,
                           COUNT(ar.id) AS total,
                           SUM(ar.status = 'present') AS present,
                           SUM(ar.status = 'absent')  AS absent,
                           SUM(ar.status = 'late')    AS late,
                           SUM(ar.status = 'excused') AS excused
                    FROM attendance_sessions ses
                    INNER JOIN semesters sem ON sem.id = ses.semester_id
                    LEFT JOIN subjects sub ON sub.id = ses.subject_id
                    LEFT JOIN attendance_records ar ON ar.session_id = ses.id
                    WHERE $whereSql
                    GROUP BY ses.id, ses.session_key, ses.subject_id, ses.session_date,
                             ses.taken_by, ses.source, ses.note, sem.semester_number,
                             sub.subject_code, sub.name
                    ORDER BY $orderBy
                    LIMIT $perPage OFFSET $offset";

            $st = $pdo->prepare($sql);
            $st->execute($params);

            $sessions = [];
            foreach ($st->fetchAll() as $r) {
                $countable = (int)$r['present'] + (int)$r['absent'] + (int)$r['late'];
                $attended  = (int)$r['present'] + (int)$r['late'];
                $sessions[] = [
                    'sessionId'    => (int)$r['id'],
                    'sessionKey'   => $r['session_key'],
                    'subjectId'    => $r['subject_id'] === null ? null : (int)$r['subject_id'],
                    'subjectCode'  => $r['subject_code'],
                    'subjectName'  => $r['subject_name'],
                    'semester'     => (int)$r['semester_number'],
                    'sessionDate'  => $r['session_date'],
                    'takenBy'      => $r['taken_by'],
                    'source'       => $r['source'],
                    'note'         => $r['note'],
                    'total'        => (int)$r['total'],
                    'present'      => (int)$r['present'],
                    'absent'       => (int)$r['absent'],
                    'late'         => (int)$r['late'],
                    'excused'      => (int)$r['excused'],
                    'percentage'   => $countable > 0 ? round($attended * 100.0 / $countable, 1) : null
                ];
            }

            sendJsonResponse([
                'success'  => true,
                'total'    => $total,
                'count'    => count($sessions),
                'page'     => $page,
                'perPage'  => $perPage,
                'sessions' => $sessions
            ]);
        }

        // ---------------------------------------------------------
        // Default: flat history of individual records, with filtering.
        // ---------------------------------------------------------
        default: {
            $studentId  = isset($_GET['studentId'])  ? (int)$_GET['studentId']  : 0;
            $sessionId  = isset($_GET['sessionId'])  ? (int)$_GET['sessionId']  : 0;
            $semesterId = isset($_GET['semesterId']) ? (int)$_GET['semesterId'] : 0;
            $subjectId  = isset($_GET['subjectId'])  ? (int)$_GET['subjectId']  : 0;
            $source     = isset($_GET['source'])     ? trim((string)$_GET['source']) : '';

            $page    = isset($_GET['page'])    ? max(1, (int)$_GET['page']) : 1;
            $perPage = isset($_GET['perPage']) ? max(1, min(500, (int)$_GET['perPage'])) : 100;

            // Whitelist: never interpolate a client string into ORDER BY.
            $SORTABLE = [
                'date'      => 'ses.session_date',
                'student'   => 's.roll_no',
                'status'    => 'ar.status',
                'semester'  => 'sem.semester_number'
            ];
            $sortKey = isset($_GET['sort']) && isset($SORTABLE[$_GET['sort']]) ? $_GET['sort'] : 'date';
            $sortDir = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';
            $orderBy = $SORTABLE[$sortKey] . ' ' . $sortDir . ', ar.id ' . $sortDir;

            $where  = ['1=1'];
            $params = [];

            if ($studentId  > 0) { $where[] = "ar.student_id = :stu";  $params['stu']  = $studentId; }
            if ($sessionId  > 0) { $where[] = "ar.session_id = :ses";  $params['ses']  = $sessionId; }
            if ($semesterId > 0) { $where[] = "ses.semester_id = :sem"; $params['sem'] = $semesterId; }
            if ($subjectId  > 0) { $where[] = "ses.subject_id = :subj"; $params['subj'] = $subjectId; }
            if (in_array($source, ['legacy', 'manual'], true)) {
                $where[] = "ses.source = :src";
                $params['src'] = $source;
            }

            $whereSql = implode(' AND ', $where);

            $cnt = $pdo->prepare("SELECT COUNT(*) FROM attendance_records ar
                                  INNER JOIN attendance_sessions ses ON ses.id = ar.session_id
                                  WHERE $whereSql");
            $cnt->execute($params);
            $total = (int)$cnt->fetchColumn();

            $offset = ($page - 1) * $perPage;
            $sql = "SELECT ar.id, ar.student_id, ar.status, ar.marked_at,
                           ses.id AS session_id, ses.session_date, ses.source,
                           s.roll_no, s.name,
                           sem.semester_number,
                           sub.subject_code, sub.name AS subject_name
                    FROM attendance_records ar
                    INNER JOIN attendance_sessions ses ON ses.id = ar.session_id
                    INNER JOIN students s ON s.id = ar.student_id
                    INNER JOIN semesters sem ON sem.id = ses.semester_id
                    LEFT JOIN subjects sub ON sub.id = ses.subject_id
                    WHERE $whereSql
                    ORDER BY $orderBy
                    LIMIT $perPage OFFSET $offset";

            $st = $pdo->prepare($sql);
            $st->execute($params);

            $records = [];
            foreach ($st->fetchAll() as $r) {
                $records[] = [
                    'id'          => (int)$r['id'],
                    'sessionId'   => (int)$r['session_id'],
                    'studentId'   => (int)$r['student_id'],
                    'rollNo'      => $r['roll_no'],
                    'name'        => $r['name'],
                    'status'      => $r['status'],
                    'sessionDate' => $r['session_date'],
                    'semester'    => (int)$r['semester_number'],
                    'subjectCode' => $r['subject_code'],
                    'subjectName' => $r['subject_name'],
                    'source'      => $r['source'],
                    'markedAt'    => $r['marked_at']
                ];
            }

            sendJsonResponse([
                'success'  => true,
                'total'    => $total,
                'page'     => $page,
                'perPage'  => $perPage,
                'records'  => $records
            ]);
        }
    }
} catch (PDOException $e) {
    sendPdoError($e);
}
