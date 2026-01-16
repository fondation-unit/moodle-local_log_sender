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
 * Task class.
 *
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_log_sender\task;

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__) . '/../../locallib.php');

class request_report extends \core\task\adhoc_task {
    public function get_name(): string {
        return get_string('request_report', 'local_log_sender');
    }

    public function execute(): void {
        $data = $this->get_custom_data();

        if (empty($data->userid)) {
            mtrace('Missing userid');
        }
        if (empty($data->requestorid)) {
            mtrace('Missing requestorid');
        }
        if (empty($data->startdate)) {
            mtrace('Missing startdate');
        }
        if (empty($data->enddate)) {
            mtrace('Missing enddate');
        }

        mtrace("Sending userid {$data->userid} to the LRS...");

        $this->send_to_lrs($data->userid, $data->requestorid, $data->startdate, $data->enddate, $data->idletime, $data->borrowedtime);
    }

    /**
     * Sends the userid to the LRS server via POST
     */
    private function send_to_lrs(int $userid, int $requestorid, int $startdate, int $enddate, int $idletime = 0, int $borrowedtime = 0): void {
        global $CFG;

        $endpoint = get_config('local_log_sender', 'user_report_endpoint_url');
        if (empty($endpoint)) {
            mtrace("Error: endpoint_url is not configured in admin settings.");
            return;
        }

        $payload = json_encode([
            'token' => get_config('local_log_sender', 'log_server_callback_token'),
            'userid' => $userid,
            'requestorid' => $requestorid,
            'startdate' => $startdate,
            'enddate' => $enddate,
            'idletime' => $idletime,
            'borrowedtime' => $borrowedtime,
            'callbackurl' => $CFG->wwwroot . '/local/log_sender/callback.php'
        ]);

        $curl = new \curl();
        $options = [
            'CURLOPT_POST' => true,
            'CURLOPT_POSTFIELDS' => $payload,
            'CURLOPT_HTTPHEADER' => ['Content-Type: application/json'],
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 30,
        ];

        $response = $curl->post($endpoint, $payload, $options);

        if ($response === false) {
            mtrace("Failed to send userid to the LRS.");
        } else {
            mtrace("LRS response: " . $response);
        }
    }
}
