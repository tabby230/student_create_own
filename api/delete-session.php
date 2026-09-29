<?php
// api/delete-session.php
// POST - delete one attendance session and, by ON DELETE CASCADE, every record
// that belongs to it. Nothing is soft-deleted or left behind.
require_once __DIR__ . '/config.php';

requireMethod('POST');

$input = readRequestInput();

$sessionId = isset($input['sessionId']) ? (int)$input['sessionId'] : 0;
if ($sessionId <= 0) {
    sendJsonError('sessionId is required and must be a positive integer.', 400, ['field' => 'sessionId']);
}

// A session created by the migration is protected. The reconstructed
// attendance_sessions/attendance_records rows are the only structured
// representation of the original per-semester percentages, so they are treated
// as immutable history and can never be deleted through the API.
//
// There is deliberately no client-supplied override (an earlier "onlyLegacy"
// flag let any caller bypass this): with no authentication layer in front of
// this endpoint, an override is indistinguishable from an accident. Removing
// migrated history is a deliberate DBA action performed directly in SQL.
$legacySources = ['legacy'];

try {
    $pdo = dbOrFail();

    $chk = $pdo->prepare("SELECT id, source, session_date, semester_id FROM attendance_sessions WHERE id = :id");
    $chk->execute(['id' => $sessionId]);
    $session = $chk->fetch();

    if (!$session) {
        sendJsonError("Attendance session $sessionId not found.", 404, ['field' => 'sessionId']);
    }

    if (in_array($session['source'], $legacySources, true)) {
        sendJsonError(
            'This is a migrated historical session and cannot be deleted. '
            . 'Migrated attendance is immutable; import a correcting session instead.',
            409,
            ['field' => 'sessionId', 'source' => $session['source']]
        );
    }

    $count = $pdo->prepare("SELECT COUNT(*) FROM attendance_records WHERE session_id = :id");
    $count->execute(['id' => $sessionId]);
    $recordCount = (int)$count->fetchColumn();

    $pdo->beginTransaction();

    // Explicit child delete first: works even if the FK cascade is missing,
    // and keeps the transaction honest.
    $delRec = $pdo->prepare("DELETE FROM attendance_records WHERE session_id = :id");
    $delRec->execute(['id' => $sessionId]);

    $delSes = $pdo->prepare("DELETE FROM attendance_sessions WHERE id = :id");
    $delSes->execute(['id' => $sessionId]);

    $pdo->commit();

    sendJsonResponse([
        'success'       => true,
        'message'       => "Session $sessionId and its $recordCount attendance record(s) were deleted.",
        'sessionId'     => $sessionId,
        'recordsDeleted'=> $recordCount
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendPdoError($e);
}
