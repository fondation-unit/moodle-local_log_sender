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
 * Strings for component 'log_sender', language 'en'
 *
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Log Sender';
$string['send_log_task'] = 'Send log task';
$string['request_report'] = 'Request report task';
$string['settings:moodle_log_endpoint_url'] = 'LRS endpoint URL to post Moodle logs';
$string['settings:moodle_log_endpoint_url_desc'] = 'Full URL of the external LRS endpoint receiving Moodle logs.';
$string['settings:user_report_endpoint_url'] = 'LRS endpoint URL to request a user\'s report';
$string['settings:user_report_endpoint_url_desc'] = 'Full URL of the external LRS endpoint retrieving the user report.';
$string['settings:log_targets'] = 'Targeted log components';
$string['settings:log_targets_desc'] = 'The target components of the log that must be accounted for in reports.';
$string['settings:lrs_callback_token'] = 'LRS callback authentication token';
$string['settings:lrs_callback_token_desc'] = 'Shared secret token used to authenticate incoming callback requests from the external LRS server.';
$string['time_report'] = 'Time report';
$string['messageprovider:report_created'] = 'Report created';
$string['messageprovider:report_creation'] = 'Report creation';
$string['period'] = 'Period';
$string['cumulative_duration'] = 'Cumulative duration per day';
$string['period_total_time'] = 'Total time for the period';
