<?php
// api/update-student.php
// POST - update any editable column of an existing student and return the
//        complete row re-read from the database.
//
// Route the student by `id`, or by `rollNo` when no id is given.
// 200 on success, 400 invalid input, 404 unknown student, 409 conflict,
// 503 database unavailable.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/student-fields.php';

requireMethod('POST');

$input = readRequestInput();

$id = isset($input['id']) ? (int)$input['id'] : 0;

if ($id <= 0) {
    // Fall back to routing by roll number.
    $roll = isset($input['rollNo']) ? strtoupper(trim((string)$input['rollNo']))
         : (isset($input['roll']) ? strtoupper(trim((string)$input['roll'])) : '');
    if ($roll === '') {
        sendJsonError('Provide the student id to update.', 400, ['field' => 'id']);
    }
    $input['__routeByRoll'] = $roll;
}

try {
    $pdo = dbOrFail();

    // ---- resolve the target student ----
    $routeByRoll = isset($input['__routeByRoll']);
    $rollArg     = $routeByRoll ? $input['__routeByRoll'] : '';
    unset($input['__routeByRoll']);

    if ($routeByRoll) {
        $lookup = $pdo->prepare("SELECT id, roll_no FROM students WHERE roll_no = :r LIMIT 1");
        $lookup->execute(['r' => $rollArg]);
        $found = $lookup->fetch();
        if (!$found) {
            sendJsonError('No student found with that roll number.', 404, ['field' => 'rollNo']);
        }
        $id = (int)$found['id'];
    }

    // Confirm the student exists before validating anything else.
    $exists = $pdo->prepare("SELECT id, roll_no FROM students WHERE id = :id");
    $exists->execute(['id' => $id]);
    $current = $exists->fetch();
    if (!$current) {
        sendJsonError("Student $id not found.", 404, ['field' => 'id']);
    }

    // ---- accept a department/semester as an id, a code, or a name ----
    $departmentId = resolveDepartmentId($pdo, $input);
    $semesterId   = resolveSemesterId($pdo, $input);
    if ($departmentId !== null) $input['departmentId'] = $departmentId;
    if ($semesterId !== null)   $input['semesterId']   = $semesterId;

    // ---- validate every supplied field ----
    $map    = studentFieldMap();
    $sets   = [];
    $params = [];

    foreach ($map as $field => $column) {
        if (!array_key_exists($field, $input)) continue;
        $params[$field] = validateStudentField($field, $input[$field]);
        $sets[] = "`$column` = :$field";
    }

    if (count($sets) === 0) {
        sendJsonError('No updatable fields were provided.', 400);
    }

    // ---- required columns may not be blanked out ----
    foreach (studentRequiredFields() as $req) {
        if (array_key_exists($req, $params) && ($params[$req] === null || $params[$req] === '')) {
            sendJsonError("Field '$req' cannot be empty.", 400, ['field' => $req]);
        }
    }

    // ---- referential integrity ----
    if (isset($params['departmentId'])) {
        $d = $pdo->prepare("SELECT id FROM departments WHERE id = :id");
        $d->execute(['id' => (int)$params['departmentId']]);
        if (!$d->fetch()) sendJsonError("Department {$params['departmentId']} does not exist.", 404, ['field' => 'departmentId']);
    }
    if (isset($params['semesterId'])) {
        $s = $pdo->prepare("SELECT id FROM semesters WHERE id = :id");
        $s->execute(['id' => (int)$params['semesterId']]);
        if (!$s->fetch()) sendJsonError("Semester {$params['semesterId']} does not exist.", 404, ['field' => 'semesterId']);
    }

    // ---- duplicate detection scoped to this student ----
    if (isset($params['rollNo'])) {
        $dup = $pdo->prepare("SELECT id FROM students WHERE roll_no = :r AND id <> :id LIMIT 1");
        $dup->execute(['r' => $params['rollNo'], 'id' => $id]);
        if ($dup->fetch()) {
            sendJsonError("Roll number {$params['rollNo']} is already used by another student.", 409, ['field' => 'rollNo']);
        }
    }
    if (!empty($params['email'])) {
        $dupE = $pdo->prepare("SELECT id FROM students WHERE email = :e AND id <> :id LIMIT 1");
        $dupE->execute(['e' => $params['email'], 'id' => $id]);
        if ($dupE->fetch()) {
            sendJsonError("Email {$params['email']} is already used by another student.", 409, ['field' => 'email']);
        }
    }

    // ---- write ----
    $params['__id'] = $id;
    $stmt = $pdo->prepare("UPDATE students SET " . implode(', ', $sets) . " WHERE id = :__id");
    $stmt->execute($params);

    // ---- return the row as it now exists in the database ----
    $row = fetchStudentRow($pdo, $id);
    if (!$row) {
        sendJsonError('Update succeeded but the student could not be read back.', 500);
    }

    $json = studentRowToJson($row);

    // Attendance is read from attendance_records, never invented.
    $json['attendance'] = computeAttendancePct($pdo, $id, (int)$row['semester_id']);

    sendJsonResponse([
        'success' => true,
        'message' => 'Student updated and saved to the database.',
        'student' => $json
    ]);

} catch (PDOException $e) {
    sendPdoError($e);
}
