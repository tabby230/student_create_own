<?php
// api/import.php
require_once __DIR__ . '/config.php';

// Accept only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonError('Method not allowed. Use POST.', 405);
}

// Check if a file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    // Also check if raw CSV data was passed in body or json
    $rawInput = file_get_contents('php://input');
    if (empty($rawInput) && !isset($_FILES['file'])) {
        sendJsonError('Please upload a CSV file with field name "file" or provide CSV content.', 400);
    }
}

$tempFilePath = null;
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $tempFilePath = $_FILES['file']['tmp_name'];
}

try {
    $pdo = getDBConnection();

    // Cache departments map
    $deptStmt = $pdo->query("SELECT id, name, code FROM `departments`");
    $deptRows = $deptStmt->fetchAll();
    $deptMap = [];
    foreach ($deptRows as $d) {
        $deptMap[strtolower($d['name'])] = (int)$d['id'];
        $deptMap[strtolower($d['code'])] = (int)$d['id'];
    }

    // Cache semesters
    $semStmt = $pdo->query("SELECT id, semester_number FROM `semesters`");
    $semRows = $semStmt->fetchAll();
    $semMap = [];
    foreach ($semRows as $s) {
        $semMap[(int)$s['semester_number']] = (int)$s['id'];
    }

    // Parse CSV
    $handle = $tempFilePath ? fopen($tempFilePath, 'r') : fopen('data://text/plain;base64,' . base64_encode($rawInput), 'r');
    if (!$handle) {
        sendJsonError('Unable to open CSV stream.', 400);
    }

    $header = fgetcsv($handle);
    if (!$header) {
        sendJsonError('CSV file is empty.', 400);
    }

    // Normalize headers (trim, lowercase, remove quotes/BOM)
    $cleanHeaders = array_map(function($h) {
        return strtolower(trim(preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h)));
    }, $header);

    // Look for column indices
    $colMap = [
        'name' => -1,
        'roll_no' => -1,
        'department' => -1,
        'year' => -1,
        'semester' => -1,
        'email' => -1,
        'phone' => -1,
        'attendance' => -1,
        'marks' => -1,
        'avatar' => -1
    ];

    foreach ($cleanHeaders as $idx => $col) {
        if (strpos($col, 'name') !== false && strpos($col, 'student') !== false) $colMap['name'] = $idx;
        elseif ($col === 'name' && $colMap['name'] === -1) $colMap['name'] = $idx;
        elseif (strpos($col, 'roll') !== false) $colMap['roll_no'] = $idx;
        elseif (strpos($col, 'dept') !== false || strpos($col, 'department') !== false) $colMap['department'] = $idx;
        elseif (strpos($col, 'year') !== false) $colMap['year'] = $idx;
        elseif (strpos($col, 'sem') !== false) $colMap['semester'] = $idx;
        elseif (strpos($col, 'email') !== false) $colMap['email'] = $idx;
        elseif (strpos($col, 'phone') !== false || strpos($col, 'mobile') !== false) $colMap['phone'] = $idx;
        elseif (strpos($col, 'att') !== false) $colMap['attendance'] = $idx;
        elseif (strpos($col, 'mark') !== false || strpos($col, 'score') !== false) $colMap['marks'] = $idx;
        elseif (strpos($col, 'avatar') !== false || strpos($col, 'photo') !== false || strpos($col, 'image') !== false) $colMap['avatar'] = $idx;
    }

    // Optional REAL per-session attendance columns, e.g. "att:2024-06-03" or
    // "attendance_2024-06-03". Each one becomes a real attendance_sessions row
    // plus one attendance_records row per imported student.
    //
    // A single summary "Attendance %" column is deliberately NOT expanded into
    // sessions: doing so would mean inventing 100 attendance events per student
    // to hit a percentage. That value is kept verbatim in the legacy summary
    // table instead, and per-session attendance is entered through the
    // attendance API/UI where each status is a real observation.
    $sessionColumns = [];
    foreach ($cleanHeaders as $sIdx => $sCol) {
        if (preg_match('/^(?:att|attend|attendance)[\s_:|-]*(\d{4}-\d{2}-\d{2})$/i', $sCol, $sMatch)) {
            $sessionColumns[$sMatch[1]] = $sIdx;
        }
    }
    ksort($sessionColumns);

    if ($colMap['name'] === -1 || $colMap['roll_no'] === -1) {
        sendJsonError('CSV must include "Name" and "Roll Number" columns.', 422, ['detected_columns' => $cleanHeaders]);
    }

    $pdo->beginTransaction();

    $studentInsertStmt = $pdo->prepare("
        INSERT INTO `students` (`roll_no`, `name`, `email`, `phone`, `department_id`, `year`, `semester_id`, `avatar`)
        VALUES (:roll_no, :name, :email, :phone, :dept_id, :year, :sem_id, :avatar)
        ON DUPLICATE KEY UPDATE 
            `name` = VALUES(`name`),
            `email` = VALUES(`email`),
            `phone` = VALUES(`phone`),
            `department_id` = VALUES(`department_id`),
            `year` = VALUES(`year`),
            `semester_id` = VALUES(`semester_id`),
            `avatar` = VALUES(`avatar`)
    ");

    $attInsertStmt = $pdo->prepare("
        INSERT INTO `attendance` (`student_id`, `semester_id`, `percentage`)
        VALUES (:student_id, :sem_id, :percentage)
        ON DUPLICATE KEY UPDATE `percentage` = VALUES(`percentage`)
    ");

    $sessionInsertStmt = $pdo->prepare("
        INSERT INTO `attendance_sessions` (`session_key`, `semester_id`, `session_date`, `source`, `note`)
        VALUES (:session_key, :sem_id, :session_date, 'import', :note)
        ON DUPLICATE KEY UPDATE `semester_id` = VALUES(`semester_id`), `note` = VALUES(`note`)
    ");
    $sessionIdStmt = $pdo->prepare("
        SELECT id FROM `attendance_sessions` WHERE `session_key` = :session_key
    ");
    $recordUpsertStmt = $pdo->prepare("
        INSERT INTO `attendance_records` (`session_id`, `student_id`, `status`)
        VALUES (:session_id, :student_id, :status)
        ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), `marked_at` = CURRENT_TIMESTAMP
    ");

    $marksInsertStmt = $pdo->prepare("
        INSERT INTO `marks` (`student_id`, `subject_id`, `semester_id`, `marks`)
        VALUES (:student_id, :subject_id, :sem_id, :marks)
        ON DUPLICATE KEY UPDATE `marks` = VALUES(`marks`)
    ");

    // Helper subject fetcher
    $subjStmt = $pdo->prepare("SELECT id FROM `subjects` WHERE `department_id` = :dept_id AND `semester_id` = :sem_id LIMIT 1");

    $importedCount = 0;
    $updatedCount = 0;
    $rowNumber = 1;
    $attendanceRecordCount = 0;
    $skippedStatusCount = 0;

    while (($row = fgetcsv($handle)) !== false) {
        $rowNumber++;
        if (empty(array_filter($row))) continue; // skip blank rows

        $name = trim($row[$colMap['name']] ?? '');
        $rollNo = strtoupper(trim($row[$colMap['roll_no']] ?? ''));
        if (empty($name) || empty($rollNo)) continue;

        // Resolve Department
        $deptStr = strtolower(trim($row[$colMap['department']] ?? 'computer science'));
        $deptId = $deptMap[$deptStr] ?? 1; // Default to 1 (Computer Science)

        // Resolve Year & Semester
        $yearVal = isset($row[$colMap['year']]) ? (int)$row[$colMap['year']] : 1;
        if ($yearVal < 1 || $yearVal > 4) $yearVal = 1;

        $semVal = isset($row[$colMap['semester']]) ? (int)$row[$colMap['semester']] : ($yearVal * 2);
        if ($semVal < 1 || $semVal > 8) $semVal = 1;
        $semId = $semMap[$semVal] ?? $semVal;

        // Email & Phone
        $email = $colMap['email'] !== -1 && !empty($row[$colMap['email']]) 
            ? trim($row[$colMap['email']]) 
            : strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $rollNo)) . '@edutrack.edu';
        $phone = $colMap['phone'] !== -1 ? trim($row[$colMap['phone']]) : null;

        // Avatar (e.g. Cloudinary URL)
        $avatar = $colMap['avatar'] !== -1 && !empty($row[$colMap['avatar']])
            ? trim($row[$colMap['avatar']])
            : null;

        // Execute Student insert/update
        $studentInsertStmt->execute([
            'roll_no' => $rollNo,
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'dept_id' => $deptId,
            'year' => $yearVal,
            'sem_id' => $semId,
            'avatar' => $avatar
        ]);

        // Get student ID
        $getStudentStmt = $pdo->prepare("SELECT id FROM `students` WHERE `roll_no` = :roll_no");
        $getStudentStmt->execute(['roll_no' => $rollNo]);
        $studentId = (int)$getStudentStmt->fetchColumn();

        // Optional Attendance percentage (summary only, kept verbatim in the
        // legacy table - never expanded into invented sessions).
        if ($colMap['attendance'] !== -1 && isset($row[$colMap['attendance']])) {
            $attVal = min(100, max(0, (float)$row[$colMap['attendance']]));
            $attInsertStmt->execute([
                'student_id' => $studentId,
                'sem_id' => $semId,
                'percentage' => $attVal
            ]);
        }

        // Real per-session attendance records from dated columns.
        foreach ($sessionColumns as $sessionDate => $sessionColIdx) {
            if (!isset($row[$sessionColIdx])) continue;

            $rawStatus = strtolower(trim((string)$row[$sessionColIdx]));
            if ($rawStatus === '') continue;
            if (!in_array($rawStatus, ['present', 'absent', 'late', 'excused'], true)) {
                $skippedStatusCount++;
                continue;
            }

            $sessionKey = 'import|' . $semId . '|' . $sessionDate;
            $sessionInsertStmt->execute([
                'session_key' => $sessionKey,
                'sem_id'      => $semId,
                'session_date' => $sessionDate,
                'note'        => 'CSV import'
            ]);
            $sessionIdStmt->execute(['session_key' => $sessionKey]);
            $sessionId = (int)$sessionIdStmt->fetchColumn();
            if ($sessionId <= 0) {
                throw new RuntimeException("Could not resolve attendance session {$sessionKey}");
            }

            $recordUpsertStmt->execute([
                'session_id' => $sessionId,
                'student_id' => $studentId,
                'status'     => $rawStatus
            ]);
            $attendanceRecordCount++;
        }

        // Optional Marks
        if ($colMap['marks'] !== -1 && isset($row[$colMap['marks']])) {
            $marksVal = min(100, max(0, (float)$row[$colMap['marks']]));
            $subjStmt->execute(['dept_id' => $deptId, 'sem_id' => $semId]);
            $subjId = $subjStmt->fetchColumn();
            if ($subjId) {
                $marksInsertStmt->execute([
                    'student_id' => $studentId,
                    'subject_id' => $subjId,
                    'sem_id' => $semId,
                    'marks' => $marksVal
                ]);
            }
        }

        $importedCount++;
    }

    fclose($handle);
    $pdo->commit();

    $importMessage = "Successfully imported/updated $importedCount student records.";
    if ($attendanceRecordCount > 0) {
        $importMessage .= " Wrote $attendanceRecordCount real attendance record(s) across "
            . count($sessionColumns) . ' dated session(s).';
    }
    if ($skippedStatusCount > 0) {
        $importMessage .= " Skipped $skippedStatusCount unrecognised attendance status value(s).";
    }

    sendJsonResponse([
        'success' => true,
        'message' => $importMessage,
        'imported_count' => $importedCount,
        'attendance_records_written' => $attendanceRecordCount,
        'attendance_sessions_detected' => count($sessionColumns),
        'invalid_status_skipped' => $skippedStatusCount
    ]);

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendJsonError('Database error during import: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    sendJsonError('Import processing error: ' . $e->getMessage(), 400);
}
