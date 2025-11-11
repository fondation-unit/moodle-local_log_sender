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

class send_log extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('send_log_task', 'local_log_sender');
    }

    public function execute() {
        $lastsent = (int) get_config('local_log_sender', 'last_sent', 0);
        $limit = 50;

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_log_sender');
        $lock = $lockfactory->get_lock('send_log_lock', 10);

        if (!$lock) {
            mtrace("Another instance of send_log is already running. Skipping this run.");
            return;
        }

        try {
            do {
                $logs = $this->get_last_logs($lastsent, $limit);
                $count = count($logs);

                if ($count === 0) {
                    mtrace("No new logs to send.");
                    break;
                }

                mtrace("Sending batch of {$count} logs as array...");

                $response = $this->post_logs_batch($logs);

                if ($response === false) {
                    mtrace("Batch failed to send.");
                    break;
                }

                mtrace("Batch sent successfully.");

                // Update last_sent to the last log in the batch
                $lastlog = end($logs);
                $lastsent = $lastlog->timecreated;
                set_config('last_sent', $lastsent, 'local_log_sender');
                mtrace("Updated last_sent to: " . userdate($lastsent));
            } while ($count === $limit);
        } finally {
            $lock->release();
        }
    }

    private function get_last_logs($lastsent, $limit) {
        global $DB;

        $allowedtargets = get_allowed_log_targets();

        // Build SQL filter for allowed targets if any are configured
        $targetfilter = '';
        $params = ['last' => $lastsent];

        if (!empty($allowedtargets)) {
            list($targetsql, $targetparams) = $DB->get_in_or_equal($allowedtargets, SQL_PARAMS_NAMED, 'target');
            $targetfilter = " AND target $targetsql";
            $params = array_merge($params, $targetparams);
        }

        // Fetch logs
        $logs = $DB->get_records_select(
            'logstore_standard_log',
            "timecreated > :last AND userid IS NOT NULL AND userid <> 0 $targetfilter",
            $params,
            'timecreated ASC',
            '*',
            0,
            $limit
        );

        foreach ($logs as $log) {
            $user = $DB->get_record('user', ['id' => $log->userid], 'id, email, username');
            $log->useremail = $user->email ?? null;
            $log->username = $user->username ?? null;
        }

        return array_values($logs);
    }

    /**
     * Send all logs as a single JSON array.
     *
     * @param array $logs Array of stdClass log objects
     * @return mixed Response content or false on failure
     */
    private function post_logs_batch(array $logs) {
        $endpoint = get_config('local_log_sender', 'endpoint_url');
        if (empty($endpoint)) {
            mtrace("Error: endpoint_url is not configured in admin settings.");
            return false;
        }

        $payload = json_encode(array_values($logs));

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $httpCode < 200 || $httpCode >= 300) {
            mtrace("Error sending batch: " . ($err ?: "HTTP $httpCode"));
            mtrace("Response: " . $response);
            return false;
        }

        return $response;
    }
}
