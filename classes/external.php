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
 * Log Sender plugin's external functions file.
 *
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_log_sender;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . "/externallib.php");

use coding_exception;
use external_api;
use external_description;
use external_function_parameters;
use external_single_structure;
use external_value;

class external extends external_api {

    /**
     * Describes the parameters for submit_create_group_form webservice.
     * @return external_function_parameters
     */
    public static function generate_time_report_parameters() {
        return new external_function_parameters([
            'jsonformdata' => new external_value(PARAM_RAW, 'The context level data, encoded as a json array')
        ]);
    }

    /**
     * Submit the report creation.
     *
     * @param string $jsonformdata The data from the form, encoded as a json array.
     * @return int new group id.
     */
    public static function generate_time_report($jsonformdata) {
        require_once(dirname(__FILE__) . '/../locallib.php');

        $params = self::validate_parameters(self::generate_time_report_parameters(), [
            'jsonformdata' => $jsonformdata
        ]);

        $serialiseddata = json_decode($params['jsonformdata'], true);

        if (!$serialiseddata['contextid'] || !$serialiseddata['userid']) {
            throw new \coding_exception('Missing contextid or userid parameters');
        }

        $strstartdate = log_sender_date_from_jstimestamp($serialiseddata['start']);
        $strenddate = log_sender_date_from_jstimestamp($serialiseddata['end']);

        $fs = get_file_storage();
        $file = $fs->get_file(
            $serialiseddata['contextid'],
            'local_log_sender',
            'content',
            '0',
            '/',
            log_sender_generate_file_name($serialiseddata['username'], $strstartdate, $strenddate)
        );

        if ($file) {
            // Delete the old file first.
            $file->delete();
        }

        $idletime = get_config('local_log_sender', 'idletime') / MINSECS;
        $borrowedtime = get_config('local_log_sender', 'borrowedtime') / MINSECS;

        $task = new \local_log_sender\task\request_report();
        $task->set_custom_data((object)[
            'requestorid' => $serialiseddata['requestorid'],
            'userid' => $serialiseddata['userid'],
            'startdate' => $serialiseddata['startdate'],
            'enddate' => $serialiseddata['enddate'],
            'idletime' => intval($idletime),
            'borrowedtime' => intval($borrowedtime)
        ]);

        // Queue the task and check for existing (bool).
        \core\task\manager::queue_adhoc_task($task, true);

        return true;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function generate_time_report_returns() {
        return new external_value(PARAM_BOOL, 'Success');
    }


    /**
     * @return external_function_parameters
     */
    public static function poll_report_file_parameters() {
        return new external_function_parameters([
            'jsonformdata' => new external_value(PARAM_RAW, 'The context level data, encoded as a json array')
        ]);
    }

    /**
     * Submit the report creation.
     *
     * @param string $jsonformdata The data from the form, encoded as a json array.
     * @return int new group id.
     */
    public static function poll_report_file($jsonformdata) {
        global $CFG, $DB;

        require_once(dirname(__FILE__) . '/../locallib.php');

        $params = self::validate_parameters(self::poll_report_file_parameters(), [
            'jsonformdata' => $jsonformdata
        ]);

        $serialiseddata = json_decode($params['jsonformdata'], true);

        if (!$serialiseddata['contextid'] || !$serialiseddata['userid']) {
            throw new \coding_exception('Missing contextid or userid parameters');
        }

        $return = new \stdClass();
        $return->path = '';
        $return->status = false;

        $contextid = $serialiseddata['contextid'];
        $userid = $serialiseddata['userid'];

        $strstartdate = log_sender_date_from_jstimestamp($serialiseddata['start']);
        $strenddate = log_sender_date_from_jstimestamp($serialiseddata['end']);

        $user = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
        if ($user) {
            $fs = get_file_storage();
            $filename = log_sender_generate_file_name(fullname($user), $strstartdate, $strenddate);
            $file = $fs->get_file($contextid, 'local_log_sender', 'content', '0', '/', $filename);

            if ($file) {
                $return->path = "$CFG->wwwroot/pluginfile.php/$contextid/local_log_sender/content/0/$filename";
                $return->status = true;
            }
        } else {
            \core\notification::error('Utilisateur non existant (id : ' . $userid . ')');
        }

        return $return;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_description
     * @since Moodle 3.0
     */
    public static function poll_report_file_returns() {
        return new \external_single_structure(
            array(
                'path' => new external_value(PARAM_TEXT, 'File path'),
                'status' => new external_value(PARAM_BOOL, 'File exists or not')
            )
        );
    }
}
