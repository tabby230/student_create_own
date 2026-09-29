<?php
// api/student-fields.php
// Shared schema for reading and writing a `students` row.
// Included by create-student.php, update-student.php and student-update.php
// so all three validate identically and always touch the same columns.

/**
 * The complete set of editable student columns, mapped from the JSON field
 * name the client sends to the real database column.
 *
 * Every column present in the `students` table (schema.sql:52 plus the columns
 * added by migration_student_profile.sql) appears here.
 */
function studentFieldMap() {
    return [
        'name'             => 'name',
        'rollNo'           => 'roll_no',
        'email'            => 'email',
        'phone'            => 'phone',
        'registerNo'       => 'register_no',
        'section'          => 'section',
        'dob'              => 'dob',
        'gender'           => 'gender',
        'course'           => 'course',
        'admissionYear'    => 'admission_year',
        'bloodGroup'       => 'blood_group',
        'category'         => 'category',
        'address'          => 'address',
        'languages'        => 'languages_known',
        'guardianName'     => 'guardian_name',
        'guardianPhone'    => 'guardian_phone',
        'emergencyContact' => 'emergency_contact',
        'relationship'     => 'relationship',
        'credits'          => 'credits',
        'backlogs'         => 'backlogs',
        'avatar'           => 'avatar',
        'photo'            => 'photo',
        'departmentId'     => 'department_id',
        'semesterId'       => 'semester_id',
        'year'             => 'year'
    ];
}

/**
 * Columns that must never be NULL. Used to validate creates and to reject an
 * update that would blank out a required value.
 */
function studentRequiredFields() {
    return ['name', 'rollNo', 'departmentId', 'semesterId', 'year'];
}

/**
 * Validate and normalise one field.
 *
 * @param string $field JSON field name
 * @param mixed  $value raw value from the request
 * @return mixed  value ready to bind
 */
function validateStudentField($field, $value) {
    $isText = is_string($value);
    $value  = $isText ? trim($value) : $value;

    // An explicit empty string means "clear this optional column".
    $empty = ($value === '' || $value === null);

    switch ($field) {
        case 'name':
            if ($empty) sendJsonError('name is required.', 400, ['field' => 'name']);
            if (mb_strlen((string)$value) > 120) sendJsonError('name must be 120 characters or fewer.', 400, ['field' => 'name']);
            return (string)$value;

        case 'rollNo':
            if ($empty) sendJsonError('rollNo is required.', 400, ['field' => 'rollNo']);
            $value = strtoupper((string)$value);
            if (!preg_match('/^[A-Z0-9\-\/]{2,25}$/', $value)) {
                sendJsonError('rollNo may contain letters, digits, hyphen and slash only (e.g. 22CS050).', 400, ['field' => 'rollNo']);
            }
            return $value;

        case 'email':
            if ($empty) return null;
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                sendJsonError('Invalid email address.', 400, ['field' => 'email']);
            }
            return strtolower((string)$value);

        case 'phone':
        case 'guardianPhone':
        case 'emergencyContact':
            if ($empty) return null;
            if (!preg_match('/^\+?[0-9\s\-]{8,20}$/', (string)$value)) {
                sendJsonError("Invalid phone number for '$field' (8-20 digits, optional + and spaces).", 400, ['field' => $field]);
            }
            return (string)$value;

        case 'dob':
            if ($empty) return null;
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
                sendJsonError('dob must be in YYYY-MM-DD format.', 400, ['field' => 'dob']);
            }
            [$y, $m, $d] = array_map('intval', explode('-', (string)$value));
            if (!checkdate($m, $d, $y)) {
                sendJsonError('dob is not a real calendar date.', 400, ['field' => 'dob']);
            }
            return sprintf('%04d-%02d-%02d', $y, $m, $d);

        case 'gender':
            if ($empty) return null;
            // Accept any casing from the admin form, but store the canonical
            // value that the rest of the application compares against.
            $gender = ucfirst(strtolower(trim((string)$value)));
            if (!in_array($gender, ['Male', 'Female', 'Other'], true)) {
                sendJsonError('gender must be Male, Female or Other.', 400, ['field' => 'gender']);
            }
            return $gender;

        case 'bloodGroup':
            if ($empty) return null;
            if (!preg_match('/^(A|B|AB|O)[+-]$/', (string)$value)) {
                sendJsonError('bloodGroup must look like O+, A-, AB+.', 400, ['field' => 'bloodGroup']);
            }
            return $value;

        case 'category':
            if ($empty) return null;
            $category = strtoupper(trim((string)$value));
            if (!in_array($category, ['GENERAL', 'OBC', 'BC', 'MBC', 'SC', 'ST', 'EWS'], true)) {
                sendJsonError('category must be one of: General, OBC, BC, MBC, SC, ST, EWS.', 400, ['field' => 'category']);
            }
            return $category === 'GENERAL' ? 'General' : $category;

        case 'section':
            if ($empty) return null;
            // Normalise to upper case first so 'a' and 'A' are both accepted,
            // then validate the canonical form.
            $section = strtoupper(trim((string)$value));
            if (!preg_match('/^[A-Z]{1,3}$/', $section)) {
                sendJsonError('section must be 1-3 letters, e.g. A.', 400, ['field' => 'section']);
            }
            return $section;

        case 'relationship':
            if ($empty) return null;
            if (mb_strlen((string)$value) > 50) sendJsonError('relationship must be 50 characters or fewer.', 400, ['field' => 'relationship']);
            return (string)$value;

        case 'course':
            if ($empty) return null;
            if (mb_strlen((string)$value) > 100) sendJsonError('course must be 100 characters or fewer.', 400, ['field' => 'course']);
            return (string)$value;

        case 'registerNo':
            if ($empty) return null;
            if (mb_strlen((string)$value) > 30) sendJsonError('registerNo must be 30 characters or fewer.', 400, ['field' => 'registerNo']);
            return (string)$value;

        case 'address':
            if ($empty) return null;
            if (mb_strlen((string)$value) > 255) sendJsonError('address must be 255 characters or fewer.', 400, ['field' => 'address']);
            return (string)$value;

        case 'languages':
            if ($empty) return null;
            if (mb_strlen((string)$value) > 100) sendJsonError('languages must be 100 characters or fewer.', 400, ['field' => 'languages']);
            return (string)$value;

        case 'guardianName':
            if ($empty) return null;
            if (mb_strlen((string)$value) > 120) sendJsonError('guardianName must be 120 characters or fewer.', 400, ['field' => 'guardianName']);
            return (string)$value;

        case 'admissionYear':
            if ($empty) return null;
            $y = (int)$value;
            if ($y < 1990 || $y > (int)date('Y') + 1) {
                sendJsonError('admissionYear must be between 1990 and ' . ((int)date('Y') + 1) . '.', 400, ['field' => 'admissionYear']);
            }
            return $y;

        case 'credits':
            if ($empty) return null;
            $c = (int)$value;
            if ($c < 0 || $c > 200) sendJsonError('credits must be between 0 and 200.', 400, ['field' => 'credits']);
            return $c;

        case 'backlogs':
            if ($empty) return 0;
            $b = (int)$value;
            if ($b < 0 || $b > 50) sendJsonError('backlogs must be between 0 and 50.', 400, ['field' => 'backlogs']);
            return $b;

        case 'year':
            if ($empty) sendJsonError('year is required.', 400, ['field' => 'year']);
            $y = (int)$value;
            if ($y < 1 || $y > 4) sendJsonError('year must be between 1 and 4.', 400, ['field' => 'year']);
            return $y;

        case 'departmentId':
        case 'semesterId':
            if ($empty) sendJsonError("$field is required.", 400, ['field' => $field]);
            $i = (int)$value;
            if ($i <= 0) sendJsonError("$field must be a positive integer.", 400, ['field' => $field]);
            return $i;

        case 'avatar':
        case 'photo':
            if ($empty) return null;
            if (!is_string($value) || strlen($value) > 255) {
                sendJsonError("$field must be a URL or path of 255 characters or fewer.", 400, ['field' => $field]);
            }
            return $value;
    }

    return $isText ? $value : $value;
}

/**
 * Resolve a client-supplied department (id, code or name) to a department id.
 * Lets the admin panel send "Computer Science" instead of a raw id.
 *
 * @param PDO $pdo
 * @param array $input
 * @return int|null
 */
function resolveDepartmentId(PDO $pdo, array $input) {
    foreach (['departmentId', 'department_id'] as $k) {
        if (isset($input[$k]) && $input[$k] !== '') return (int)$input[$k];
    }
    if (isset($input['department']) && $input['department'] !== '') {
        $st = $pdo->prepare("SELECT id FROM departments WHERE name = :v OR code = :v2 LIMIT 1");
        $st->execute(['v' => trim((string)$input['department']), 'v2' => trim((string)$input['department'])]);
        $id = $st->fetchColumn();
        if ($id === false) {
            sendJsonError("No department matches '{$input['department']}'.", 400, ['field' => 'department']);
        }
        return (int)$id;
    }
    return null;
}

/**
 * Resolve a client-supplied semester (id or number) to a semester id.
 *
 * @param PDO $pdo
 * @param array $input
 * @return int|null
 */
function resolveSemesterId(PDO $pdo, array $input) {
    foreach (['semesterId', 'semester_id'] as $k) {
        if (isset($input[$k]) && $input[$k] !== '') return (int)$input[$k];
    }
    if (isset($input['semester']) && $input['semester'] !== '') {
        $st = $pdo->prepare("SELECT id FROM semesters WHERE semester_number = :n LIMIT 1");
        $st->execute(['n' => (int)$input['semester']]);
        $id = $st->fetchColumn();
        if ($id === false) {
            sendJsonError("Semester {$input['semester']} does not exist.", 400, ['field' => 'semester']);
        }
        return (int)$id;
    }
    return null;
}

/**
 * Load one student row joined to its department and semester.
 *
 * @param PDO $pdo
 * @param int $id
 * @return array|null
 */
function fetchStudentRow(PDO $pdo, $id) {
    $st = $pdo->prepare(
        "SELECT s.*, d.name AS dept, d.code AS dept_code, sem.semester_number
         FROM students s
         INNER JOIN departments d ON d.id = s.department_id
         INNER JOIN semesters sem ON sem.id = s.semester_id
         WHERE s.id = :id
         LIMIT 1"
    );
    $st->execute(['id' => (int)$id]);
    return $st->fetch() ?: null;
}

/**
 * Map a raw database row to the JSON shape the frontend already consumes.
 * The database remains the only source of every value here.
 *
 * @param array $row
 * @return array
 */
function studentRowToJson(array $row) {
    return [
        'id'               => (int)$row['id'],
        'name'             => $row['name'],
        'rollNo'           => $row['roll_no'],
        'email'            => $row['email'],
        'phone'            => $row['phone'],
        'dept'             => $row['dept'],
        'deptCode'         => $row['dept_code'],
        'departmentId'     => (int)$row['department_id'],
        'year'             => (int)$row['year'],
        'semester'         => (int)$row['semester_number'],
        'semesterId'       => (int)$row['semester_id'],
        'registerNo'       => $row['register_no'],
        'section'          => $row['section'],
        'dob'              => $row['dob'],
        'gender'           => $row['gender'],
        'course'           => $row['course'],
        'admissionYear'    => $row['admission_year'] === null ? null : (int)$row['admission_year'],
        'bloodGroup'       => $row['blood_group'],
        'category'         => $row['category'],
        'address'          => $row['address'],
        'languages'        => $row['languages_known'],
        'guardianName'     => $row['guardian_name'],
        'guardianPhone'    => $row['guardian_phone'],
        'emergencyContact' => $row['emergency_contact'],
        'relationship'     => $row['relationship'],
        'credits'          => $row['credits'] === null ? null : (int)$row['credits'],
        'backlogs'         => (int)$row['backlogs'],
        'avatar'           => $row['avatar'],
        'photo'            => $row['photo'],
        'createdAt'        => $row['created_at']
    ];
}
