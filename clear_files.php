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
 * Reports clearing script.
 *
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(dirname(__FILE__) . '/../../config.php');
require_once(dirname(__FILE__) . '/locallib.php');

require_login();

global $PAGE, $USER;

$id = required_param('userid', PARAM_INT);
$user = $DB->get_record('user', array('id' => $id), '*', MUST_EXIST);

$personalcontext = context_user::instance($user->id);
if (!has_capability('local/log_sender:view', $personalcontext)) {
    redirect("$CFG->wwwroot/user/profile.php?id=?$user->id");
}

$context = \context_system::instance();

remove_reports_files($context->id, $user->id);
redirect("$CFG->wwwroot/local/log_sender/time_report.php?userid=$user->id");
