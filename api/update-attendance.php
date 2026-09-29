<?php
// api/update-attendance.php
// POST - amend a single attendance record (or a batch of them) in place.
// The record keeps its identity; only the status changes, and the change is
// written to attendance_records so it survives a page refresh.
//
// Body:
//   sessionId (int) + studentId (int) + status (present|absent|late|excused)
//   ...or a records array of { sessionId, studentId, status }.
require_once __DIR__ . '/config.php';

requireMethod('POST');

$input = readRequestInput();

$VALID_STATUS = ['present', 'absent', 'late', 'excused'];

$updates = [];
if (isset($input['records']) && is_array($input['records'])) {
    foreach ($input['records'] as $rec) {
        if (!is_array($rec)) continue;
        $updates[] = $rec;
    }
} else {
    $updates[] = $input;
}

if (count($updates) === 0) {
    sendJsonError('Nothing to update.', 400);
}

$clean = [];
foreach ($updates as $rec) {
    $sessionId = isset($rec['sessionId']) ? (int)$rec['sessionId'] : 0;
    $studentId = isset($rec['studentId']) ? (int)$rec['studentId'] : 0;
    $status    = isset($rec['status']) ? strtolower(trim((string)$rec['status'])) : '';

    if ($sessionId <= 0) {
        sendJsonError('sessionId is required and must be a positive integer.', 400, ['field' => 'sessionId']);
    }
    if ($studentId <= 0) {
        sendJsonError('studentId is required and must be a positive integer.', 400, ['field' => 'studentId']);
    }
    if (!in_array($status, $VALID_STATUS, true)) {
        sendJsonError("Invalid status '$status'. Allowed: " . implode(', ', $VALID_STATUS) . '.', 400, ['field' => 'status']);
    }
    $clean[] = ['sessionId' => $sessionId, 'studentId' => $studentId, 'status' => $status];
}

try {
    $pdo = dbOrFail();
    $pdo->beginTransaction();

    // Verify every target row really exists before changing anything. The join
    // also brings back the session source, because migrated sessions are
    // immutable history: amending one would silently rewrite the original
    // per-semester percentages that the migration preserved exactly.
    $stmt = $pdo->prepare(
        "SELECT ar.id, ar.session_id, ar.student_id, ar.status, ses.source
         FROM attendance_records ar
         INNER JOIN attendance_sessions ses ON ses.id = ar.session_id
         WHERE ar.session_id = :sid AND ar.student_id = :stu"
    );

    $changed = [];
    $notFound = [];
    $protected = [];

    foreach ($clean as $u) {
        $stmt->execute(['sid' => $u['sessionId'], 'stu' => $u['studentId']]);
        $row = $stmt->fetch();
        if (!$row) {
            $notFound[] = "session {$u['sessionId']} / student {$u['studentId']}";
            continue;
        }

        if ($row['source'] === 'legacy') {
            $protected[] = "session {$u['sessionId']} / student {$u['studentId']}";
            continue;
        }

        if ($row['status'] !== $u['status']) {
            $upd = $pdo->prepare(
                "UPDATE attendance_records
                 SET status = :st, marked_at = CURRENT_TIMESTAMP
                 WHERE id = :id"
            );
            $upd->execute(['st' => $u['status'], 'id' => (int)$row['id']]);
        }

        $changed[] = [
            'sessionId' => (int)$row['session_id'],
            'studentId' => (int)$row['student_id'],
            'status'    => $u['status'],
            'was'       => $row['status']
        ];
    }

    if (count($protected) > 0 && count($changed) === 0) {
        $pdo->rollBack();
        sendJsonError(
            'Migrated historical attendance is immutable and cannot be amended: '
            . implode('; ', $protected) . '.',
            409,
            ['field' => 'sessionId', 'source' => 'legacy']
        );
    }

    if (count($protected) > 0) {
        // A mixed batch: keep the editable rows but report what was refused.
        $pdo->commit();
        sendJsonResponse([
            'success'     => true,
            'partial'     => true,
            'message'     => 'Attendance updated, but migrated historical records were skipped: '
                            . implode('; ', $protected) . '.',
            'updated'     => $changed,
            'protected'   => $protected,
            'percentages' => []
        ]);
    }

    if (count($changed) === 0) {
        $pdo->rollBack();
        sendJsonError('No matching attendance record found for: ' . implode('; ', $notFound) . '.', 404);
    }

    $pdo->commit();

    // Return the recomputed percentage for each affected student, from records.
    $touched = [];
    foreach ($changed as $c) {
        $touched[$c['studentId']] = true;
    }

    $percentages = [];
    foreach (array_keys($touched) as $sid) {
        $percentages[] = [
            'studentId'  => (int)$sid,
            'percentage' => computeAttendancePct($pdo, (int)$sid)
        ];
    }

    sendJsonResponse([
        'success'     => true,
        'message'     => 'Attendance updated in the database.',
        'updated'     => $changed,
        'percentages' => $percentages
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendPdoError($e);
}
