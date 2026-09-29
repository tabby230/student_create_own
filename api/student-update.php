<?php
// api/student-update.php
// Backwards-compatible entry point. The admin panel's profile editor still
// calls this endpoint; it now delegates to update-student.php so every write
// goes through the same complete validation, the same whitelisted column set,
// and returns the row re-read from the database.
//
// Identical request and response shape. Use update-student.php directly for
// new code.
require_once __DIR__ . '/update-student.php';
