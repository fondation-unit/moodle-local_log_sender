<?php

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/locallib.php');
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
$userid = $data->userid ?? null;
$requestorid = $data->requestorid ?? null;

if (!$userid || !$requestorid) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing userid or requestorid']);
    exit;
}

$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
$requestor = $DB->get_record('user', ['id' => $requestorid]);
$startdate = $data->startdate ?? null;
$enddate = $data->enddate ?? time();

if (!$startdated) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing startdate']);
    exit;
}

// Create new file
$file = log_sender_create_csv($user, $requestorid, $payload, $startdate, $enddate);

if ($file) {
    $filename = $file->get_filename();
    $context = context_system::instance();
    $contextid = $context->id;

    // Send notification if requestor is specified
    if ($requestor && $requestor->id != $user->id) {
        $filepath = "$CFG->wwwroot/pluginfile.php/$contextid/local_log_sender/content/0/$filename";
        $fullmessage = "<p>" . get_string('download', 'core') . " : ";
        $fullmessage .= "<a href=\"$filepath\" download><i class=\"fa fa-download\"></i>$filename</a></p>";
        $smallmessage = get_string('messageprovider:report_created', 'local_log_sender');

        log_sender_report_notification($user, $file, $requestor, $fullmessage, $smallmessage);
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
