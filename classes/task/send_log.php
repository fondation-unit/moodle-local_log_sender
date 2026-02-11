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

    private function fetch_logs(int $lastid, int $limit): array {
        global $DB;

        $allowedtargets = log_sender_get_allowed_log_targets();
        $params = ['lastid' => $lastid];
        $whereclause = "id > :lastid AND userid IS NOT NULL AND userid <> 0";

        if (!empty($allowedtargets)) {
            list($sql, $sqlparams) = $DB->get_in_or_equal($allowedtargets, SQL_PARAMS_NAMED, 'tgt');
            $whereclause .= " AND target $sql";
            $params += $sqlparams;
        }

        return $DB->get_records_select(
            'logstore_standard_log',
            $whereclause,
            $params,
            'id ASC',
            '*',
            0,
            $limit
        );
    }

    private function get_next_logs(int $lastid, int $limit): array {
        $logs = $this->fetch_logs($lastid, $limit);
        if (empty($logs)) {
            return [];
        }

        $users    = $this->load_users($logs);
        $courses  = $this->load_courses($logs);
        $cms      = $this->load_course_modules($logs);
        $modules  = $this->load_module_instances($cms);

        $this->attach_enriched_data($logs, $users, $courses, $modules);

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
        unset($ch);

        if ($err || $code < 200 || $code >= 300) {
            mtrace("HTTP error: " . ($err ?: "HTTP $code - Response: " . substr($response, 0, 200)));
            return false;
        }

        return true;
    }

    private function attach_enriched_data(
        array &$logs,
        array $users,
        array $courses,
        array $modules
    ): void {
        // Get Moodle's default lang to generate language maps.
        $lang = get_config('moodle', 'lang');

        foreach ($logs as $log) {
            $user = $users[$log->userid] ?? null;
            $log->useremail = $user->email ?? null;
            $log->username  = $user->username ?? null;

            $log->activity_definition = $this->build_activity_definition(
                $log,
                $courses,
                $modules,
                $lang
            );
        }
    }

    /**
     * Build the activity definition part of an xAPI statement from a log record.
     *
     * This functions creates an activity definition depending on the log context level.
     *
     * Expected structure:
     *
     * $courses:
     * [
     *     courseid => \stdClass {
     *         id,
     *         fullname,
     *         summary
     *     },
     *     ...
     * ]
     * $modules:
     * [
     *     cmid => [
     *         'cmid'    => int,
     *         'modname' => string,
     *         'name'    => string,
     *         'intro'   => string,
     *         'url'     => string|null
     *     ],
     *     ...
     * ]
     *
     * @param \stdClass $log A log record from {logstore_standard_log}.
     * @param array<int, \stdClass> $courses Courses indexed by course ID.
     * @param array<int, array> $modules Module data indexed by course module ID (cmid).
     * @param string $lang Language code used as key for languages maps.
     *
     * @return array<string, mixed>|null Activity definition array or null if not resolvable.
     */
    private function build_activity_definition(
        \stdClass $log,
        array $courses,
        array $modules,
        string $lang
    ): ?array {
        switch ($log->contextlevel) {
            case CONTEXT_COURSE:
                $course = $courses[$log->courseid] ?? null;

                return $course ? [
                    'type' => 'course',
                    'name' => [$lang => $course->fullname],
                    'description' => [$lang => $course->summary],
                ] : null;

            case CONTEXT_MODULE:
                $cmid = $log->contextinstanceid;
                $module = $modules[$cmid] ?? null;

                if (!$module) {
                    return null;
                }

                $intro = $module['intro'] ?? '';

                return [
                    'type' => 'module',
                    'name' => [$lang => $module['name']],
                    'description' => [$lang => trim(strip_tags($intro))],
                    'moreInfo' => $module['url'],
                ];
        }

        return null;
    }

    /**
     * Load user records referenced by the given logs from {logstore_standard_log}.
     *
     * Returned structure:
     * [
     *     userid => \stdClass {
     *         id,
     *         email,
     *         username
     *     },
     *     ...
     * ]
     *
     * @param array<int, \stdClass> $logs Array of log records.
     *
     * @return array<int, \stdClass> Array of user records indexed by user ID.
     */
    private function load_users(array $logs): array {
        global $DB;

        $userids = array_unique(array_column($logs, 'userid'));
        list($sql, $params) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        return $DB->get_records_select(
            'user',
            "id $sql",
            $params,
            '',
            'id,email,username'
        );
    }

    /**
     * Load course records referenced by the given logs from {logstore_standard_log}.
     *
     * Returned structure:
     * [
     *     courseid => \stdClass {
     *         id,
     *         fullname,
     *         summary
     *     },
     *     ...
     * ]
     *
     * @param array<int, \stdClass> $logs Array of log records.
     *
     * @return array<int, \stdClass> Array of course records indexed by course ID.
     */
    private function load_courses(array $logs): array {
        global $DB;

        $courseids = array_unique(array_filter(array_column($logs, 'courseid')));
        if (empty($courseids)) {
            return [];
        }

        list($sql, $params) = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED);

        return $DB->get_records_select(
            'course',
            "id $sql",
            $params,
            '',
            'id,fullname,summary'
        );
    }

    /**
     * Extract course module IDs from {logstore_standard_log} logs and group them by course.
     *
     * This function filters the provided logs with CONTEXT_MODULE, resolves their 
     * corresponding course_modules records and returns a structure grouped by course ID.
     *
     * Returned structure: [courseid => [cmid1, cmid2, ...], ...]
     *
     * @param array<int, \stdClass> $logs Array of log records.
     *
     * @return array<int, int[]> Array of cmids indexed by course ID.
     */
    private function load_course_modules(array $logs): array {
        global $DB;

        $cmids = array_unique(array_filter(array_map(
            fn($log) => $log->contextlevel == CONTEXT_MODULE ? $log->contextinstanceid : null,
            $logs
        )));

        if (empty($cmids)) {
            return [];
        }

        list($sql, $params) = $DB->get_in_or_equal($cmids, SQL_PARAMS_NAMED);

        $cms = $DB->get_records_sql("
            SELECT
                cm.id AS cmid,
                cm.course
            FROM {course_modules} cm
            WHERE cm.id $sql
        ", $params);

        $cmidsbycourse = [];

        foreach ($cms as $cm) {
            $cmidsbycourse[$cm->course][] = $cm->cmid;
        }

        return $cmidsbycourse;
    }

    /**
     * Load course module instances using get_fast_modinfo.
     * 
     * This function resolves a set of course module IDs (cmid) grouped by 
     * course into a flat array indexed by cmid.
     *
     * Returned structure:
     * [
     *     cmid => [
     *         'cmid'    => int,         // Course module ID
     *         'modname' => string,      // Module type
     *         'name'    => string,      // Activity name
     *         'intro'   => string,      // Activity intro
     *         'url'     => string|null, // Activity URL
     *     ],
     *     ...
     * ]
     *
     * @param array<int, int[]> $cmidsbycourse Array indexed by course ID containing cmids.
     *
     * @return array<int, array{
     *     cmid:int,
     *     modname:string,
     *     name:string,
     *     intro:string,
     *     url:?string
     * }>
     */
    private function load_module_instances(array $cmidsbycourse): array {
        $instances = [];

        foreach ($cmidsbycourse as $courseid => $cmids) {
            $modinfo = get_fast_modinfo($courseid);

            foreach ($cmids as $cmid) {
                try {
                    $cminfo = $modinfo->get_cm($cmid);

                    $instances[$cmid] = [
                        'cmid'    => $cminfo->id,
                        'modname' => $cminfo->modname,
                        'name'    => $cminfo->name,
                        'intro'   => $cminfo->intro ?? '',
                        'url'     => $cminfo->url ? $cminfo->url->out(false) : null,
                    ];
                } catch (Exception $e) {
                    // Ignore missing cm.
                }
            }
        }

        return $instances;
    }
}
