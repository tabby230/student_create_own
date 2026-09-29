<?php
// api/mark-attendance.php
// POST - create or update a real attendance session and its records.
//
// Body (JSON or form):
//   semesterId  (int, required)
//   sessionDate (Y-m-d, required)
//   subjectId   (int, optional - NULL means unspecified/legacy-style subject)
//   departmentId(int, optional - when `records` is omitted, every student in
//                      this department+semester is marked with defaultStatus)
//   takenBy     (string, optional)
//   note        (string, optional)
//   defaultStatus (present|absent|late|excused, default 'present')
//   records     [ { studentId:int, status:present|absent|late|excused }, ... ]
//
// Re-marking the same subject+semester+date UPDATES the existing session and
// records, so the operation is idempotent. Everything is written inside one
// transaction with PDO prepared statements.
require_once __DIR__ . '/config.php';

requireMethod('POST');

$input = readRequestInput();

$VALID_STATUS = ['present', 'absent', 'late', 'excused'];

// ---- validate header fields ----
$semesterId = isset($input['semesterId']) ? (int)$input['semesterId'] : 0;
if ($semesterId <= 0) {
    sendJsonError('semesterId is required.', 400, ['field' => 'semesterId']);
}

$sessionDate = isset($input['sessionDate']) ? trim((string)$input['sessionDate']) : '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)) {
    sendJsonError('sessionDate must be in YYYY-MM-DD format.', 400, ['field' => 'sessionDate']);
}
$ts = strtotime($sessionDate);
if ($ts === false) {
    sendJsonError('sessionDate is not a real calendar date.', 400, ['field' => 'sessionDate']);
}
$sessionDate = date('Y-m-d', $ts);

$subjectId = isset($input['subjectId']) && $input['subjectId'] !== '' && $input['subjectId'] !== null
    ? (int)$input['subjectId']
    : null;
if ($subjectId !== null && $subjectId <= 0) {
    sendJsonError('subjectId must be a positive integer or omitted.', 400, ['field' => 'subjectId']);
}

$departmentId = isset($input['departmentId']) && $input['departmentId'] !== '' && $input['departmentId'] !== null
    ? (int)$input['departmentId']
    : null;
if ($departmentId !== null && $departmentId <= 0) {
    sendJsonError('departmentId must be a positive integer or omitted.', 400, ['field' => 'departmentId']);
}

$defaultStatus = isset($input['defaultStatus']) ? strtolower(trim((string)$input['defaultStatus'])) : 'present';
if (!in_array($defaultStatus, $VALID_STATUS, true)) {
    sendJsonError('defaultStatus must be one of: ' . implode(', ', $VALID_STATUS) . '.', 400, ['field' => 'defaultStatus']);
}

$sessionLabel = isset($input['note']) ? trim((string)$input['note']) : null;
if ($sessionLabel === '') $sessionLabel = null;
if ($sessionLabel !== null && mb_strlen($sessionLabel) > 255) {
    sendJsonError('note must be 255 characters or fewer.', 400, ['field' => 'note']);
}

// ---- normalise the records array ----
$records = [];
if (isset($input['records']) && is_array($input['records'])) {
    foreach ($input['records'] as $rec) {
        if (!is_array($rec)) continue;
        $sid = isset($rec['studentId']) ? (int)$rec['studentId'] : 0;
        $st  = isset($rec['status']) ? strtolower(trim((string)$rec['status'])) : '';
        if ($sid <= 0) {
            sendJsonError('Every record needs a positive studentId.', 400, ['field' => 'records[].studentId']);
        }
        if (!in_array($st, $VALID_STATUS, true)) {
            sendJsonError("Invalid status '$st'. Allowed: " . implode(', ', $VALID_STATUS) . '.', 400, ['field' => 'records[].status']);
        }
        $records[$sid] = $st;   // last write wins for a repeated studentId
    }
}

if (count($records) === 0 && $departmentId === null) {
    sendJsonError('Provide either a records array or a departmentId to mark a whole class.', 400, ['field' => 'records']);
}

try {
    $pdo = dbOrFail();

    $pdo->beginTransaction();

    // ---- 1. verify the semester exists (FK integrity, explicit error) ----
    $sem = $pdo->prepare("SELECT id, semester_number FROM semesters WHERE id = :id");
    $sem->execute(['id' => $semesterId]);
    if (!$sem->fetch()) {
        $pdo->rollBack();
        sendJsonError("Semester $semesterId does not exist.", 404, ['field' => 'semesterId']);
    }

    // ---- 2. resolve the roster when marking a whole class ----
    if (count($records) === 0) {
        $rst = $pdo->prepare(
            "SELECT id FROM students
             WHERE department_id = :dept AND semester_id = :sem
             ORDER BY id ASC"
        );
        $rst->execute(['dept' => $departmentId, 'sem' => $semesterId]);
        $roster = $rst->fetchAll(PDO::FETCH_COLUMN);
        if (count($roster) === 0) {
            $pdo->rollBack();
            sendJsonError('No students found in that department and semester.', 404);
        }
        foreach ($roster as $sid) {
            $records[(int)$sid] = $defaultStatus;
        }
    }

    // ---- 3. reject any studentId that is not a real student ----
    $ids = array_keys($records);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $chk = $pdo->prepare("SELECT id FROM students WHERE id IN ($ph)");
    $chk->execute($ids);
    $existing = array_map('intval', $chk->fetchAll(PDO::FETCH_COLUMN));
    $missing  = array_diff($ids, $existing);
    if (count($missing) > 0) {
        $pdo->rollBack();
        sendJsonError('Unknown student id(s): ' . implode(', ', $missing) . '.', 404, ['field' => 'records[].studentId']);
    }

    // ---- 4. upsert the session (idempotent on session_key) ----
    $sessionKey = 'm|' . ($subjectId === null ? 0 : $subjectId) . '|' . $semesterId . '|' . $sessionDate;

    $up = $pdo->prepare(
        "INSERT INTO attendance_sessions
            (session_key, subject_id, semester_id, session_date, taken_by, source, note)
         VALUES
            (:k, :subj, :sem, :date, :by, 'manual', :note)
         ON DUPLICATE KEY UPDATE
            subject_id  = VALUES(subject_id),
            taken_by    = COALESCE(VALUES(taken_by), taken_by),
            note        = COALESCE(VALUES(note), note)"
    );
    $up->execute([
        'k'    => $sessionKey,
        'subj' => $subjectId,
        'sem'  => $semesterId,
        'date' => $sessionDate,
        'by'   => isset($input['takenBy']) && trim((string)$input['takenBy']) !== ''
                    ? mb_substr(trim((string)$input['takenBy']), 0, 120)
                    : null,
        'note' => $sessionLabel
    ]);

    $sel = $pdo->prepare("SELECT id FROM attendance_sessions WHERE session_key = :k");
    $sel->execute(['k' => $sessionKey]);
    $sessionId = (int)$sel->fetchColumn();

    // ---- 5. upsert every record (idempotent on session+student) ----
    $ins = $pdo->prepare(
        "INSERT INTO attendance_records (session_id, student_id, status)
         VALUES (:sid, :stu, :st)
         ON DUPLICATE KEY UPDATE status = VALUES(status), marked_at = CURRENT_TIMESTAMP"
    );
    foreach ($records as $studentId => $status) {
        $ins->execute(['sid' => $sessionId, 'stu' => (int)$studentId, 'st' => $status]);
    }

    $pdo->commit();

    // ---- 6. return the persisted state, straight from the database ----
    $rs = $pdo->prepare(
        "SELECT ar.student_id, ar.status
         FROM attendance_records ar
         WHERE ar.session_id = :sid
         ORDER BY ar.student_id ASC"
    );
    $rs->execute(['sid' => $sessionId]);

    $saved = [];
    $tally = ['present' => 0, 'absent' => 0, 'late' => 0, 'excused' => 0];
    foreach ($rs->fetchAll() as $r) {
        $saved[] = ['studentId' => (int)$r['student_id'], 'status' => $r['status']];
        $tally[$r['status']]++;
    }

    sendJsonResponse([
        'success'   => true,
        'message'   => 'Attendance saved to the database.',
        'session'   => [
            'id'          => $sessionId,
            'sessionKey'  => $sessionKey,
            'subjectId'   => $subjectId,
            'semesterId'  => $semesterId,
            'sessionDate' => $sessionDate,
            'source'      => 'manual'
        ],
        'markedCount' => count($records),
        'tally'      => $tally,
        'records'    => $saved
    ], 201);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendPdoError($e);
}
