<?php
/**
 * EduTrack Central API Configuration & Database Connection
 * PDO Connection, CORS, JSON Response Headers & Centralized Error Handling
 */

// Prevent PHP errors from leaking into JSON response output
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Set JSON and CORS headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle preflight OPTIONS requests immediately
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Database Connection Settings.
// Precedence: environment variable > config.local.php > built-in default.
// Environment variables are applied FIRST so they take priority over
// config.local.php, whose own define() calls are guarded and therefore only
// fill in what is still undefined. This also removes the "Constant already
// defined" warning that used to be emitted on every single request.
$edutrackEnvHost = getenv('DB_HOST');
$edutrackEnvPort = getenv('DB_PORT');
$eduttrackEnvName = getenv('DB_NAME');
$eduttrackEnvUser = getenv('DB_USER');
$eduttrackEnvPass = getenv('DB_PASS');

if (isset($edutrackEnvHost) && $edutrackEnvHost !== false && $edutrackEnvHost !== '' && !defined('DB_HOST')) {
    define('DB_HOST', $edutrackEnvHost);
}
if (isset($edutrackEnvPort) && $edutrackEnvPort !== false && $edutrackEnvPort !== '' && !defined('DB_PORT')) {
    define('DB_PORT', $edutrackEnvPort);
}
if (isset($eduttrackEnvName) && $eduttrackEnvName !== false && $eduttrackEnvName !== '' && !defined('DB_NAME')) {
    define('DB_NAME', $eduttrackEnvName);
}
if (isset($eduttrackEnvUser) && $eduttrackEnvUser !== false && $eduttrackEnvUser !== '' && !defined('DB_USER')) {
    define('DB_USER', $eduttrackEnvUser);
}
if (isset($eduttrackEnvPass) && $eduttrackEnvPass !== false && $eduttrackEnvPass !== '' && !defined('DB_PASS')) {
    define('DB_PASS', $eduttrackEnvPass);
}

if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

$edutrackDbConfig = defined('EDUTRACK_DB_CONFIG') ? EDUTRACK_DB_CONFIG : [];

if (!defined('DB_HOST')) define('DB_HOST', $edutrackDbConfig['host'] ?? '127.0.0.1');
if (!defined('DB_PORT')) define('DB_PORT', $edutrackDbConfig['port'] ?? '3307');
if (!defined('DB_NAME')) define('DB_NAME', $edutrackDbConfig['name'] ?? 'edutrack');
if (!defined('DB_USER')) define('DB_USER', $edutrackDbConfig['user'] ?? 'root');
if (!defined('DB_PASS')) define('DB_PASS', $edutrackDbConfig['pass'] ?? '');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

/**
 * Get PDO Database Connection
 * @return PDO
 * @throws PDOException
 */
function getDBConnection() {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

/**
 * Send JSON Success Response
 * @param mixed $data
 * @param int $statusCode
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Send JSON Error Response
 * @param string $message
 * @param int $statusCode
 * @param array $extra
 */
function sendJsonError($message, $statusCode = 500, $extra = []) {
    http_response_code($statusCode);
    $response = array_merge([
        'error' => true,
        'message' => $message
    ], $extra);
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

/**
 * Send a 503 "database unavailable" response.
 * The application NEVER substitutes invented data when the database is
 * unreachable - it reports the real failure so the database stays the
 * single source of truth.
 *
 * @param PDOException $e
 */
function sendDbUnavailable(PDOException $e) {
    error_log('[EduTrack] DB unavailable: ' . $e->getMessage());
    sendJsonError(
        'Database unavailable. No data is being served because this system has no fallback dataset.',
        503,
        ['code' => 'DB_UNAVAILABLE']
    );
}

/**
 * Map a PDOException to the correct HTTP status.
 * Duplicate-key -> 409, missing row -> 404, everything else -> 503.
 *
 * @param PDOException $e
 * @param string $context
 */
function sendPdoError(PDOException $e, $context = 'Database request failed.') {
    $sqlState = (string)$e->getCode();
    $driverCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;

    // 23000 = integrity constraint violation, 1452 = duplicate entry
    if ($sqlState === '23000' && $driverCode === 1062) {
        sendJsonError('A record with that unique value already exists.', 409, ['code' => 'DUPLICATE']);
    }
    if ($sqlState === '23000' && $driverCode === 1451) {
        sendJsonError('This record is still referenced by other records and cannot be changed.', 409, ['code' => 'FK_RESTRICTED']);
    }
    if ($sqlState === '23000' && $driverCode === 1452) {
        sendJsonError('Referenced record does not exist.', 409, ['code' => 'FK_MISSING']);
    }
    sendDbUnavailable($e);
}

/**
 * Send a 500 response for a query that ran but failed (bad SQL, missing
 * table/column, ONLY_FULL_GROUP_BY, ...).
 *
 * This is deliberately distinct from sendDbUnavailable(): a failing query is
 * NOT the same as an unreachable database, and reporting it as "database
 * unavailable" hides the real cause. The frontend renders `message` verbatim,
 * so a broken query shows up as a visible error instead of a silently empty
 * table.
 *
 * @param PDOException $e
 * @param string $context
 */
function sendQueryError(PDOException $e, $context = 'Query failed.') {
    error_log('[EduTrack] ' . $context . ' ' . $e->getMessage());
    sendJsonError(
        $context . ' ' . $e->getMessage(),
        500,
        ['code' => 'QUERY_FAILED']
    );
}

/**
 * Run a PDO statement, converting any failure into a 500 JSON error that
 * carries the real MySQL message.
 *
 * @param PDO $pdo
 * @param string $sql
 * @param array $params
 * @param string $context
 * @return PDOStatement
 */
function runQuery(PDO $pdo, $sql, array $params = [], $context = 'Query failed.') {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        sendQueryError($e, $context);
    }
}

/**
 * Read the request body. Accepts JSON or form-encoded input and always
 * returns an associative array.
 *
 * @return array
 */
function readRequestInput() {
    $input = [];

    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) $input = $json;
        }
    }

    return array_merge($input, $_POST);
}

/**
 * Require an HTTP method, else respond 405.
 *
 * @param string $expected
 */
function requireMethod($expected) {
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== $expected) {
        header('Allow: ' . $expected);
        sendJsonError("Method not allowed. Use $expected.", 405);
    }
}

/**
 * Fetch a PDO connection, converting any failure into a 503 response.
 *
 * @return PDO
 */
function dbOrFail() {
    try {
        return getDBConnection();
    } catch (PDOException $e) {
        sendDbUnavailable($e);
    }
}

/**
 * Percentage of attended sessions, computed from real attendance_records.
 *
 * Counting rule:
 *   attended   = present + late
 *   denominator= present + late + absent   (excused is excluded entirely)
 *   no records  -> NULL (not 0, not a guess)
 *
 * @param PDO $pdo
 * @param int $studentId
 * @param int|null $semesterId  null = all semesters
 * @return float|null
 */
function computeAttendancePct(PDO $pdo, $studentId, $semesterId = null) {
    $sql = "SELECT
                SUM(ar.status IN ('present','late')) AS attended,
                SUM(ar.status IN ('present','late','absent')) AS countable
            FROM `attendance_records` ar
            INNER JOIN `attendance_sessions` ses ON ses.id = ar.session_id
            WHERE ar.student_id = :sid";
    $params = ['sid' => (int)$studentId];

    if ($semesterId !== null) {
        $sql .= " AND ses.semester_id = :semid";
        $params['semid'] = (int)$semesterId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    $countable = (int)$row['countable'];
    if ($countable === 0) return null;

    return round($row['attended'] * 100.0 / $countable, 2);
}

/**
 * Build the attendance series (per semester and per month) for one student
 * straight from attendance_records. No arithmetic synthesis anywhere.
 *
 * @param PDO $pdo
 * @param int $studentId
 * @return array{bySemester:array, byMonth:array, overall:float|null}
 */
function attendanceSeries(PDO $pdo, $studentId) {
    $stmt = $pdo->prepare(
        "SELECT ses.semester_id,
                sem.semester_number,
                SUM(ar.status IN ('present','late')) AS attended,
                SUM(ar.status IN ('present','late','absent')) AS countable
         FROM `attendance_records` ar
         INNER JOIN `attendance_sessions` ses ON ses.id = ar.session_id
         INNER JOIN `semesters` sem ON sem.id = ses.semester_id
         WHERE ar.student_id = :sid
         GROUP BY ses.semester_id, sem.semester_number
         ORDER BY sem.semester_number ASC"
    );
    $stmt->execute(['sid' => (int)$studentId]);

    $bySemester = [];
    $totalAttended = 0;
    $totalCountable = 0;
    foreach ($stmt->fetchAll() as $r) {
        $countable = (int)$r['countable'];
        $attended  = (int)$r['attended'];
        $pct = $countable > 0 ? round($attended * 100.0 / $countable, 1) : null;

        $bySemester[] = [
            'semester'   => (int)$r['semester_number'],
            'percentage' => $pct,
            'attended'   => $attended,
            'total'      => $countable
        ];
        $totalAttended  += $attended;
        $totalCountable += $countable;
    }

    $mstmt = $pdo->prepare(
        "SELECT DATE_FORMAT(ses.session_date, '%Y-%m') AS ym,
                SUM(ar.status IN ('present','late')) AS attended,
                SUM(ar.status IN ('present','late','absent')) AS countable
         FROM `attendance_records` ar
         INNER JOIN `attendance_sessions` ses ON ses.id = ar.session_id
         WHERE ar.student_id = :sid
         GROUP BY ym
         ORDER BY ym ASC"
    );
    $mstmt->execute(['sid' => (int)$studentId]);

    $byMonth = [];
    foreach ($mstmt->fetchAll() as $r) {
        $countable = (int)$r['countable'];
        $byMonth[] = [
            'month'      => $r['ym'],
            'percentage' => $countable > 0 ? round((int)$r['attended'] * 100.0 / $countable, 1) : null,
            'attended'   => (int)$r['attended'],
            'total'      => $countable
        ];
    }

    return [
        'bySemester' => $bySemester,
        'byMonth'    => $byMonth,
        'overall'    => $totalCountable > 0 ? round($totalAttended * 100.0 / $totalCountable, 2) : null
    ];
}
