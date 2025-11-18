<?php

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

header('Content-Type: application/json');

global $CFG, $DB;

// Get raw input
$raw = file_get_contents('php://input');

if (empty($raw)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

$data = json_decode($raw);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON: ' . json_last_error_msg()]);
    exit;
}

// Verify token
$token = $data->token ?? '';
$expected = get_config('local_log_sender', 'lrs_callback_token');

if (empty($expected)) {
    http_response_code(500);
    echo json_encode(['error' => 'No token configured']);
    exit;
}

if ($token !== $expected) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

// Get payload
$payload = $data->data ?? null;

if (empty($payload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing data field']);
    exit;
}

// Decode data if it's a string
if (is_string($payload)) {
    $payload = json_decode($payload);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data JSON: ' . json_last_error_msg()]);
        exit;
    }
}

// Extract metadata from payload
$userid = 2;
$requestorid = 32;
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
$requestor = $DB->get_record('user', ['id' => $requestorid]);
$startdate = $data->startdate ?? 1731860711;
$enddate = $data->enddate ?? 1763396714;

if (!$userid) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing userid']);
    exit;
}

// Create new file
$file = log_sender_create_csv($user, $requestorid, $payload, $contextid, $startdate, $enddate);

if ($file) {
    // Send notification if requestor is specified
    if ($requestorid && $requestorid != $userid) {
        $requestor = $DB->get_record('user', ['id' => $requestorid]);
        if ($requestor) {
            log_sender_report_notification($user, $file, $requestor, $fullmessage, $smallmessage);
        }
    }

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'file' => $filename,
        'download_url' => $path
    ]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create file']);
}
