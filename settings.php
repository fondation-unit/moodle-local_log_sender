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
 * Settings page
 *
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(dirname(__FILE__) . '/locallib.php');

// Admin only can access
if ($hassiteconfig) {
    $settings = new admin_settingpage('local_log_sender', get_string('pluginname', 'local_log_sender'));

    // Configure the remote log endpoint
    $settings->add(new admin_setting_configtext(
        'local_log_sender/endpoint_url',
        get_string('settings:endpoint_url', 'local_log_sender'),
        get_string('settings:endpoint_url_desc', 'local_log_sender'),
        'http://localhost:8089/moodle_log',
        PARAM_URL
    ));

    // Log targets
    $targets = get_log_targets();
    $defaulttargets = array_keys($targets);

    $settings->add(new admin_setting_configmultiselect(
        'local_log_sender/log_targets',
        get_string('settings:log_targets', 'local_log_sender'),
        get_string('settings:log_targets_desc', 'local_log_sender'),
        $defaulttargets,
        $targets
    ));

    $ADMIN->add('localplugins', $settings);
}
