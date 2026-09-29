<?php
// api/student-photo.php
// POST (multipart/form-data): uploads a student profile photo.
// Fields: photo (file, jpg/png/webp <= 2MB), plus id OR roll number.
// Saves the file into assets/uploads/students/ and stores the path
// in students.photo, then returns the new URL.
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonError('Method not allowed. Use POST (multipart/form-data).', 405);
    return;
}

// Multipart only - no JSON body for file uploads
$input = $_POST;

$id     = isset($input['id']) ? (int)$input['id'] : 0;
$rollNo = isset($input['roll']) ? strtoupper(trim($input['roll'])) : '';

if ($id <= 0 && $rollNo === '') {
    sendJsonError('Provide a student id or roll number, plus a photo file.', 400);
    return;
}

if (!isset($_FILES['photo'])) {
    sendJsonError('No file uploaded. Use a field named "photo".', 400);
    return;
}

$file = $_FILES['photo'];

if ($file['error'] !== UPLOAD_ERR_OK) {
    $msg = ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)
        ? 'Uploaded file is too large.'
        : 'File upload failed.';
    sendJsonError($msg, 400);
    return;
}

if ($file['size'] > 2 * 1024 * 1024) {
    sendJsonError('Photo must be 2MB or smaller.', 400);
    return;
}

// Validate the actual MIME type with finfo (not the client-supplied type)
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

if (!extension_loaded('fileinfo')) {
    sendJsonError('The fileinfo extension is required for photo uploads.', 500);
    return;
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($file['tmp_name']);

if (!isset($allowed[$mime])) {
    sendJsonError('Invalid image type. Allowed: jpg, png, webp.', 400);
    return;
}

// Random filename + guaranteed upload directory
$ext      = $allowed[$mime];
$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$dir      = __DIR__ . '/../assets/uploads/students';
$dest     = $dir . '/' . $filename;

if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    sendJsonError('Could not create the upload directory.', 500);
    return;
}

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    sendJsonError('Could not move the uploaded file.', 500);
    return;
}

$photoUrl = 'assets/uploads/students/' . $filename;

try {
    $pdo = dbOrFail();

    // Inner try: a failing UPDATE is a query error, not an unreachable
    // database. The uploaded file is removed either way, so this must not
    // rely on runQuery() (which exits before the outer catch can unlink).
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE `students` SET `photo` = :photo WHERE `id` = :id");
            $stmt->execute(['photo' => $photoUrl, 'id' => $id]);
            $routeColumn = 'id';
            $routeValue  = $id;
        } else {
            $stmt = $pdo->prepare("UPDATE `students` SET `photo` = :photo WHERE `roll_no` = :roll_no");
            $stmt->execute(['photo' => $photoUrl, 'roll_no' => $rollNo]);
            $routeColumn = 'roll_no';
            $routeValue  = $rollNo;
        }

        if ($stmt->rowCount() === 0) {
            // rowCount can be 0 when nothing changed; verify the student exists
            $c = $pdo->prepare("SELECT COUNT(*) FROM `students` WHERE `$routeColumn` = :v");
            $c->execute(['v' => $routeValue]);
            if ((int)$c->fetchColumn() === 0) {
                @unlink($dest);
                sendJsonError('No student found with that id/roll number.', 404);
                return;
            }
        }
    } catch (PDOException $qe) {
        @unlink($dest);
        sendQueryError($qe, 'Photo update query failed.');
        return;
    }

    sendJsonResponse([
        'success' => true,
        'message' => 'Photo uploaded successfully.',
        'id'      => $id,
        'rollNo'  => $rollNo,
        'photo'   => $photoUrl
    ]);
} catch (PDOException $e) {
    // Reached only when the CONNECTION itself failed. Remove the orphan file
    // and report the real cause.
    @unlink($dest);
    sendDbUnavailable($e);
    return;
}