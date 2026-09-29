<?php
// api/delete-student.php
// POST - remove a student. Related rows (marks, attendance_records,
//        student_documents) go with them through the existing
//        ON DELETE CASCADE foreign keys.
//
// Body: { id: 123 }  or  { rollNo: "22CS050" }
// Optional `confirmRollNo` must match the stored roll number, so a wrong id
// cannot silently destroy the wrong record.
//
// 200 deleted, 400 invalid, 404 unknown, 409 roll-number mismatch.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/student-fields.php';

requireMethod('POST');

$input = readRequestInput();

$id = isset($input['id']) ? (int)$input['id'] : 0;
$rollArg = '';
if ($id <= 0) {
    $rollArg = isset($input['rollNo']) ? strtoupper(trim((string)$input['rollNo'])) : '';
    if ($rollArg === '') {
        sendJsonError('Provide the id or roll number of the student to delete.', 400, ['field' => 'id']);
    }
}

try {
    $pdo = dbOrFail();

    // ---- locate the student ----
    if ($id > 0) {
        $st = $pdo->prepare(
            "SELECT s.id, s.roll_no, s.name,
                    (SELECT COUNT(*) FROM marks m WHERE m.student_id = s.id) AS mark_count,
                    (SELECT COUNT(*) FROM attendance_records a WHERE a.student_id = s.id) AS att_count,
                    (SELECT COUNT(*) FROM student_documents dd WHERE dd.student_id = s.id) AS doc_count
             FROM students s WHERE s.id = :id"
        );
        $st->execute(['id' => $id]);
    } else {
        $st = $pdo->prepare(
            "SELECT s.id, s.roll_no, s.name,
                    (SELECT COUNT(*) FROM marks m WHERE m.student_id = s.id) AS mark_count,
                    (SELECT COUNT(*) FROM attendance_records a WHERE a.student_id = s.id) AS att_count,
                    (SELECT COUNT(*) FROM student_documents dd WHERE dd.student_id = s.id) AS doc_count
             FROM students s WHERE s.roll_no = :r"
        );
        $st->execute(['r' => $rollArg]);
    }

    $student = $st->fetch();
    if (!$student) {
        sendJsonError('Student not found.', 404);
    }

    $id = (int)$student['id'];

    // ---- guard against deleting the wrong record ----
    if (isset($input['confirmRollNo']) && trim((string)$input['confirmRollNo']) !== '') {
        if (strtoupper(trim((string)$input['confirmRollNo'])) !== $student['roll_no']) {
            sendJsonError(
                "Roll number mismatch: this student is {$student['roll_no']}, not {$input['confirmRollNo']}.",
                409,
                ['field' => 'confirmRollNo']
            );
        }
    }

    // ---- report what will be removed, then remove it in a transaction ----
    $pdo->beginTransaction();

    $affected = [
        'marks'           => (int)$student['mark_count'],
        'attendanceRecords'=> (int)$student['att_count'],
        'documents'       => (int)$student['doc_count']
    ];

    $del = $pdo->prepare("DELETE FROM students WHERE id = :id");
    $del->execute(['id' => $id]);

    if ($del->rowCount() === 0) {
        $pdo->rollBack();
        sendJsonError('The student could not be deleted.', 500);
    }

    $pdo->commit();

    sendJsonResponse([
        'success'  => true,
        'message'  => "Student {$student['name']} ({$student['roll_no']}) was deleted from the database.",
        'deleted'  => [
            'id'     => $id,
            'name'   => $student['name'],
            'rollNo' => $student['roll_no']
        ],
        'cascadedRows' => $affected
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendPdoError($e);
}
