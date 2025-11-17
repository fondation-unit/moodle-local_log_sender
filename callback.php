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
$startdate = $data->startdate ?? 1731860711;
$enddate = $data->enddate ?? 1763396714;

if (!$userid) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing userid']);
    exit;
}

// Get user
$user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

// Generate CSV from payload
$delimiter = \csv_import_reader::get_delimiter('comma');
$csventries = array();

// Add header information
$strstartdate = $startdate ? date('d-m-Y', $startdate) : 'N/A';
$strenddate = $enddate ? date('d-m-Y', $enddate) : 'N/A';

$csventries[] = array(get_string('name', 'core'), $user->lastname);
$csventries[] = array(get_string('firstname', 'core'), $user->firstname);
$csventries[] = array(get_string('email', 'core'), $user->email);
$csventries[] = array(get_string('period', 'local_log_sender'), $strstartdate . ' - ' . $strenddate);
$csventries[] = array(''); // Empty row

// Generate CSV string
$csvstring = '';
foreach ($csventries as $entry) {
    $csvstring .= '"' . implode('"' . $delimiter . '"', $entry) . '"' . "\n";
}

// Generate filename
$filename = clean_filename(
    $user->id . '_report_' . $strstartdate . '_' . $strenddate . '.csv'
);

// Store file in Moodle file system
$contextid = context_system::instance()->id;
$fs = get_file_storage();
$fileinfo = array(
    'contextid' => $contextid,
    'component' => 'local_log_sender',
    'filearea' => 'content',
    'itemid' => 0,
    'filepath' => '/',
    'filename' => $filename,
    'userid' => $user->id
);

// Delete old file if exists
$oldfile = $fs->get_file(
    $fileinfo['contextid'],
    $fileinfo['component'],
    $fileinfo['filearea'],
    $fileinfo['itemid'],
    $fileinfo['filepath'],
    $fileinfo['filename']
);

if ($oldfile) {
    $oldfile->delete();
}

// Create new file
$file = $fs->create_file_from_string($fileinfo, $csvstring);

if ($file) {
    // Generate download URL
    $path = "$CFG->wwwroot/pluginfile.php/$contextid/local_log_sender/content/0/$filename";
    $fullmessage = "<p>" . get_string('download', 'core') . " : ";
    $fullmessage .= "<a href=\"$path\" download><i class=\"fa fa-download\"></i>$filename</a></p>";
    $smallmessage = get_string('messageprovider:report_created', 'local_log_sender');

    // Send notification if requestor is specified
    if ($requestorid && $requestorid != $userid) {
        $requestor = $DB->get_record('user', ['id' => $requestorid]);
        if ($requestor) {
            send_report_notification($user, $file, $requestor, $fullmessage, $smallmessage);
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

/**
 * Send notification to user
 */
function send_report_notification($user, $file, $requestor, $fullmessage, $smallmessage) {
    $fullname = fullname($user);
    $contexturl = new moodle_url('/local/log_sender/report.php', array('userid' => $user->id));

    $message = new \core\message\message();
    $message->component = 'local_log_sender';
    $message->name = 'reportcreation';
    $message->userfrom = \core_user::get_noreply_user();
    $message->userto = $requestor;
    $message->subject = get_string('messageprovider:report_creation', 'local_log_sender') . " : " . $fullname;
    $message->fullmessageformat = FORMAT_HTML;
    $message->fullmessage = html_to_text($fullmessage);
    $message->fullmessagehtml = $fullmessage;
    $message->smallmessage = $smallmessage;
    $message->notification = 1;
    $message->contexturl = $contexturl;
    $message->contexturlname = get_string('time_report', 'local_log_sender');
    $message->attachment = $file;

    message_send($message);
}
