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
