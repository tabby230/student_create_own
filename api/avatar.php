<?php
// api/avatar.php
// Save a student's avatar (e.g. Cloudinary URL) into the students.avatar column.
// POST with either `id` or `roll_no`, plus `avatar` (URL). Empty avatar clears it.
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonError('Method not allowed. Use POST.', 405);
}

// Merge form fields and a JSON body
$input = [];
if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
    $json = json_decode(file_get_contents('php://input'), true);
    if (is_array($json)) $input = array_merge($input, $json);
}
$input = array_merge($input, $_POST);

$id     = isset($input['id']) ? (int)$input['id'] : 0;
$rollNo = isset($input['roll_no']) ? strtoupper(trim($input['roll_no'])) : '';
$avatar = isset($input['avatar']) ? trim($input['avatar']) : '';

if ($id <= 0 && $rollNo === '') {
    sendJsonError('Provide a student id or roll_no, plus an avatar URL.', 400);
}

// Basic sanity check: any Cloudinary/CDN URL is fine
if ($avatar !== '' && strpos($avatar, '://') === false) {
    sendJsonError('Avatar must be a full URL (e.g. https://res.cloudinary.com/...).', 400);
}

try {
    $pdo = getDBConnection();

    if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE `students` SET `avatar` = :avatar WHERE `id` = :id");
        $stmt->execute(['avatar' => $avatar, 'id' => $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE `students` SET `avatar` = :avatar WHERE `roll_no` = :roll_no");
        $stmt->execute(['avatar' => $avatar, 'roll_no' => $rollNo]);
    }

    if ($stmt->rowCount() === 0) {
        // rowCount can be 0 when the value didn't change; verify the student exists
        if ($id > 0) {
            $count = (int)$pdo->query("SELECT COUNT(*) FROM `students` WHERE `id` = " . (int)$id)->fetchColumn();
        } else {
            $c = $pdo->prepare("SELECT COUNT(*) FROM `students` WHERE `roll_no` = :roll_no");
            $c->execute(['roll_no' => $rollNo]);
            $count = (int)$c->fetchColumn();
        }
        if ($count === 0) {
            sendJsonError('No student found with that id/roll_no.', 404);
        }
    }

    sendJsonResponse(['success' => true, 'message' => 'Avatar updated successfully.']);
} catch (PDOException $e) {
    sendJsonError('Database error during avatar update: ' . $e->getMessage(), 500);
}