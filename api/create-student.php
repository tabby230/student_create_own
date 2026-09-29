<?php
// api/create-student.php
// POST - insert a new student row and return the complete saved record.
//
// 201 on success, 400 invalid input, 404 unknown department/semester,
// 409 duplicate roll number or email, 503 database unavailable.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/student-fields.php';

requireMethod('POST');

$input = readRequestInput();

try {
    $pdo = dbOrFail();

    // ---- accept a department/semester as an id, a code, or a name ----
    $departmentId = resolveDepartmentId($pdo, $input);
    $semesterId   = resolveSemesterId($pdo, $input);

    if ($departmentId !== null) $input['departmentId'] = $departmentId;
    if ($semesterId !== null)   $input['semesterId']   = $semesterId;

    $map = studentFieldMap();

    $columns = [];
    $params  = [];

    foreach ($map as $field => $column) {
        if (!array_key_exists($field, $input)) continue;
        $params[$field]            = validateStudentField($field, $input[$field]);
        $columns[$column]          = ':' . $field;
    }

    // Every required field must be present on create.
    $missing = [];
    foreach (studentRequiredFields() as $req) {
        if (!array_key_exists($req, $params)) $missing[] = $req;
    }
    if (count($missing) > 0) {
        sendJsonError('Missing required field(s): ' . implode(', ', $missing) . '.', 400, ['fields' => $missing]);
    }

    if (count($columns) === 0) {
        sendJsonError('No student fields were provided.', 400);
    }

    // ---- referential integrity checks with clear errors ----
    $d = $pdo->prepare("SELECT id FROM departments WHERE id = :id");
    $d->execute(['id' => (int)$params['departmentId']]);
    if (!$d->fetch()) sendJsonError("Department {$params['departmentId']} does not exist.", 404, ['field' => 'departmentId']);

    $s = $pdo->prepare("SELECT id FROM semesters WHERE id = :id");
    $s->execute(['id' => (int)$params['semesterId']]);
    if (!$s->fetch()) sendJsonError("Semester {$params['semesterId']} does not exist.", 404, ['field' => 'semesterId']);

    // ---- explicit duplicate detection, so the caller gets a clean 409 ----
    $dup = $pdo->prepare("SELECT id FROM students WHERE roll_no = :r LIMIT 1");
    $dup->execute(['r' => $params['rollNo']]);
    if ($dup->fetch()) {
        sendJsonError("Roll number {$params['rollNo']} is already used by another student.", 409, ['field' => 'rollNo']);
    }

    if (!empty($params['email'])) {
        $dupE = $pdo->prepare("SELECT id FROM students WHERE email = :e LIMIT 1");
        $dupE->execute(['e' => $params['email']]);
        if ($dupE->fetch()) {
            sendJsonError("Email {$params['email']} is already used by another student.", 409, ['field' => 'email']);
        }
    }

    $colSql = implode(', ', array_map(function ($c) { return "`$c`"; }, array_keys($columns)));

    $stmt = $pdo->prepare("INSERT INTO students ($colSql) VALUES (" . implode(', ', array_values($columns)) . ")");
    $stmt->execute($params);

    $newId = (int)$pdo->lastInsertId();

    $row = fetchStudentRow($pdo, $newId);
    if (!$row) {
        sendJsonError('Student was inserted but could not be read back.', 500);
    }

    sendJsonResponse([
        'success' => true,
        'message' => 'Student created and saved to the database.',
        'student' => studentRowToJson($row)
    ], 201);

} catch (PDOException $e) {
    sendPdoError($e);
}
