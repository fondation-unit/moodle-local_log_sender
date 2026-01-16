<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */


/**
 * Get all unique targets from the logstore_standard_log table.
 *
 * @return Array of string
 */
function log_sender_get_log_targets() {
    global $DB;

    $records = $DB->get_records_sql('SELECT DISTINCT target FROM {logstore_standard_log}');
    return array_map(fn($r) => $r->target, $records);
}

/**
 * Get allowed log targets based on admin settings.
 *
 * @return string[] List of allowed targets.
 */
function log_sender_get_allowed_log_targets(): array {
    $config = trim(get_config('local_log_sender', 'log_targets') ?? '');
    if ($config === '') {
        return [];
    }

    $allowed = array_map('trim', explode(',', $config));
    $all = log_sender_get_log_targets();

    return array_values(array_intersect($all, $allowed));
}

/**
 * Send notification to user
 */
function log_sender_report_notification($user, $file, $requestor, $fullmessage, $smallmessage) {
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

    // Set the file attachment.
    if ($file) {
        $message->attachment = $file;
    }

    message_send($message);
}

/**
 * Generates a snake cased username.
 *
 * @param  string $str
 * @param  string $glue (optional)
 * @return string
 */
function log_sender_str_to_snake_case($str, $glue = '_') {
    $str = preg_replace('/\s+/', '', $str);

    return ltrim(
        preg_replace_callback('/[A-Z]/', function ($matches) use ($glue) {
            return $glue . strtolower($matches[0]);
        }, $str),
        $glue
    );
}

/**
 * Generates the filename.
 *
 * @param  string $startdate
 * @param  string $enddate
 * @return string
 */
function log_sender_generate_file_name($username, $startdate, $enddate) {
    if (!$username) {
        throw new \coding_exception('Missing username');
    }

    return strtolower(get_string('report', 'core'))
        . '__' . log_sender_str_to_snake_case($username)
        . '__' . $startdate . '_' . $enddate . '.csv';
}

function log_sender_create_csv($user, $data, $startdate, $enddate) {
    global $CFG;
    require_once($CFG->libdir . '/csvlib.class.php');

    $strstartdate = date('d-m-Y', $startdate);
    $strenddate = date('d-m-Y', $enddate);

    // Generate CSV from data
    $delimiter = \csv_import_reader::get_delimiter('comma');
    $csventries = array(array());
    $last = end($data);

    // Add header information
    $csventries[] = array(get_string('name', 'core'), $user->lastname);
    $csventries[] = array(get_string('firstname', 'core'), $user->firstname);
    $csventries[] = array(get_string('email', 'core'), $user->email);
    $csventries[] = array(get_string('period', 'local_log_sender'), $strstartdate . ' - ' . $strenddate);
    $csventries[] = array(get_string('period_total_time', 'local_log_sender'), $last->cumulativeduration);
    $csventries[] = array();
    $csventries[] = array(get_string('date'), get_string('cumulative_duration', 'local_log_sender'));

    $filecontent = '';
    $len = count($data);
    $shift = count($csventries);

    for ($i = 0; $i < $len; $i++) {
        $obj = $data[$i]; // stdClass with date and duration
        $csventries[$i + $shift] = array($obj->date, $obj->duration);
    }

    foreach ($csventries as $entry) {
        $filecontent .= '"' . implode('"' . $delimiter . '"', $entry) . '"' . "\n";
    }

    $filename = log_sender_generate_file_name(fullname($user), $strstartdate, $strenddate);

    return log_sender_write_new_file($filecontent, $filename, $user);
}

function log_sender_write_new_file($content, $filename, $user) {
    $context = context_system::instance();
    $contextid = $context->id;

    $fs = get_file_storage();
    $fileinfo = array(
        'contextid' => $contextid,
        'component' => 'local_log_sender',
        'filearea' => 'content',
        'itemid' => $user->id,
        'filepath' => '/',
        'filename' => $filename
    );

    $file = $fs->get_file(
        $fileinfo['contextid'],
        $fileinfo['component'],
        $fileinfo['filearea'],
        $fileinfo['itemid'],
        $fileinfo['filepath'],
        $fileinfo['filename']
    );

    if ($file) {
        $file->delete(); // Delete the old file first.
    }

    $fs->create_file_from_string($fileinfo, $content);

    return $file;
}

/**
 * Retrives the files of existing reports
 *
 * @return Array of moodle_url
 */
function log_sender_get_reports_files($contextid, $userid) {
    global $DB;

    $conditions = array('contextid' => $contextid, 'component' => 'local_log_sender', 'filearea' => 'content', 'userid' => $userid);
    $filerecords = $DB->get_records('files', $conditions);
    return $filerecords;
}

/**
 * Retrives the moodle_url of existing reports
 *
 * @return Array of moodle_url
 */
function log_sender_get_reports_urls($contextid, $userid) {
    $files = log_sender_get_reports_files($contextid, $userid);
    $out = array();

    foreach ($files as $file) {
        if ($file->filename != '.') {
            $path = '/' . $file->contextid . '/local_log_sender/content/' . $file->itemid . $file->filepath . $file->filename;
            $url = moodle_url::make_file_url('/pluginfile.php', $path);
            array_push($out, array('url' => $url, 'filename' => $file->filename));
        }
    }

    return $out;
}

/**
 * Returns a 'd-m-Y' date from Javascript timestamp format.
 *
 * @return String
 */
function log_sender_date_from_jstimestamp($timestamp) {
    return date('d-m-Y', (int) ($timestamp / 1000));
}
