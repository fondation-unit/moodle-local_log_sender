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

require_once(__DIR__ . '/../../locallib.php');

class send_log extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('send_log_task', 'local_log_sender');
    }

    public function execute() {
        $batchsize = 50; // Send 50 logs per HTTP request
        $maxbatches = 20; // Max 50 * 20 logs per run

        $lockfactory = \core\lock\lock_config::get_lock_factory('local_log_sender');
        $lock = $lockfactory->get_lock('send_log_lock', 60);

        if (!$lock) {
            mtrace("Already running, skip.");
            return;
        }

        try {
            $lastsent = (int) get_config('local_log_sender', 'last_sent_id') ?: 0;
            $batchcount = 0;

            while ($batchcount < $maxbatches) {
                $logs = $this->get_next_logs($lastsent, $batchsize);

                if (empty($logs)) {
                    mtrace("No more logs to send.");
                    break;
                }

                mtrace("Sending batch of " . count($logs) . " logs (starting from ID {$logs[0]->id})...");

                // Send entire batch in one request
                $success = $this->post_logs_batch($logs);

                if (!$success) {
                    mtrace("Error sending batch, stopping. Will retry from ID " . ($lastsent + 1));
                    break;
                }

                // Update last_sent_id once per batch
                $lastsent = (int) end($logs)->id;
                set_config('last_sent_id', $lastsent, 'local_log_sender');

                mtrace("✓ Batch sent successfully. Last ID: {$lastsent}");
                $batchcount++;
            }

            if ($batchcount >= $maxbatches) {
                mtrace("Reached max batches ({$maxbatches}), will continue in next run.");
            }
        } finally {
            $lock->release();
        }
    }

    private function get_next_logs($lastid, $limit) {
        global $DB;

        $allowedtargets = log_sender_get_allowed_log_targets();
        $params = ['lastid' => $lastid];
        $targetfilter = '';

        if (!empty($allowedtargets)) {
            list($targetsql, $targetparams) = $DB->get_in_or_equal($allowedtargets, SQL_PARAMS_NAMED, 'tgt');
            $targetfilter = " AND target $targetsql";
            $params += $targetparams;
        }

        $logs = $DB->get_records_select(
            'logstore_standard_log',
            "id > :lastid AND userid IS NOT NULL AND userid <> 0 $targetfilter",
            $params,
            'id ASC',
            '*',
            0,
            $limit
        );

        if (empty($logs)) {
            return [];
        }

        // Get all users
        $userids = array_unique(array_column($logs, 'userid'));
        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $users = $DB->get_records_select('user', "id $usersql", $userparams, '', 'id,email,username');

        // Get all courses
        $courseids = array_unique(array_filter(array_column($logs, 'courseid')));
        if (!empty($courseids)) {
            list($coursesql, $courseparams) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);
            $courses = $DB->get_records_select(
                'course',
                "id $coursesql",
                $courseparams,
                '',
                'id,fullname,shortname,summary'
            );
        } else {
            $courses = [];
        }

        // Attach additional user data
        foreach ($logs as $log) {
            // User data
            $user = $users[$log->userid] ?? null;
            $log->useremail = $user->email ?? null;
            $log->username  = $user->username ?? null;

            // Activity definition
            switch ($log->contextlevel) {
                case CONTEXT_COURSE:
                    $course = $courses[$log->courseid] ?? null;
                    if ($course) {
                        $log->activity_definition = [
                            'type' => 'course',
                            'name' => $course->fullname,
                            'description' => $course->summary,
                        ];
                    }
                    break;

                case CONTEXT_MODULE:
                    break;

                default:
                    // ignore
            }
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
        $endpoint = get_config('local_log_sender', 'moodle_log_endpoint_url');

        if (!$endpoint) {
            mtrace("No endpoint configured.");
            return false;
        }

        // Send as JSON array
        $payload = json_encode($logs);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $endpoint,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30, // Add timeout
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json']
        ]);

        $response = curl_exec($ch);
        $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err || $code < 200 || $code >= 300) {
            mtrace("HTTP error: " . ($err ?: "HTTP $code - Response: " . substr($response, 0, 200)));
            return false;
        }

        return true;
    }
}
