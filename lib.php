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
 * Log Sender plugin's lib file.
 *
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use core_user\output\myprofile\category;
use core_user\output\myprofile\node;
use core_user\output\myprofile\tree;

/**
 * Add nodes to myprofile page.
 *
 * @param tree $tree Tree object
 * @param stdClass $user User object
 * @param bool $iscurrentuser
 * @param stdClass $course Course object
 * @return bool
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function local_log_sender_myprofile_navigation(tree $tree, $user, $iscurrentuser, $course) {
    global $USER;

    // Context.
    $context = context_system::instance();
    if ($course->id != 0) {
        $context = context_course::instance($course->id);
    }

    if (!array_key_exists('reports', $tree->__get('categories'))) {
        // Create a new category.
        $categoryname = get_string('time_report', 'local_log_sender');
        $category = new category('time_report', $categoryname, 'time_report');
        $tree->add_category($category);
    } else {
        // Get the existing category.
        $category = $tree->__get('categories')['reports'];
    }

    if (isset($course->id)) {
        $url = new moodle_url('/local/log_sender/time_report.php', ['userid' => $user->id, 'course' => $course->id]);
    } else {
        $url = new moodle_url('/local/log_sender/time_report.php', ['userid' => $user->id]);
    }

    $admins = get_admins();
    $isadmin = in_array($USER->id, array_keys($admins));
    $hascapability = has_capability('local/log_sender:view', $context);

    // Add the node if the user is admin or has the capability.
    if ($isadmin || $hascapability) {
        $istargetadmin = in_array($user->id, array_keys($admins));
        $availableonadmins = get_config('local_log_sender', 'available_on_admins');

        if (($istargetadmin && $availableonadmins) || !$istargetadmin) {
            $pluginname = get_string('time_report', 'local_log_sender');
            $node = new node('reports', 'local_log_sender', $pluginname, null, $url);
            $category->add_node($node);
        }
    }

    return true;
}

/**
 * Serve the files from the local_log_sender file areas
 *
 * @param stdClass $course the course object
 * @param stdClass $cm the course module object
 * @param stdClass $context the context
 * @param string $filearea the name of the file area
 * @param array $args extra arguments (itemid, path)
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if the file not found, just send the file otherwise and do not return anything
 */
function local_log_sender_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = array()) {
    // Check the contextlevel is as expected - if your plugin is a block, this becomes CONTEXT_BLOCK, etc.
    if ($context->contextlevel != CONTEXT_SYSTEM) {
        return false;
    }

    // Make sure the filearea is one of those used by the plugin.
    if ($filearea !== 'content' && $filearea !== 'time_csv') {
        return false;
    }

    // Make sure the user is logged in and has access to the module.
    require_login($course, true, $cm);

    // Check the relevant capabilities - these may vary depending on the filearea being accessed.
    if (!has_capability('local/log_sender:view', $context)) {
        return false;
    }

    // Leave this line out if you set the itemid to null in make_pluginfile_url (set $itemid to 0 instead).
    $itemid = array_shift($args); // The first item in the $args array.

    // Use the itemid to retrieve any relevant data records and perform any security checks to see if the
    // user really does have access to the file in question.

    // Extract the filename / filepath from the $args array.
    $filename = array_pop($args); // The last item in the $args array.
    if (!$args) {
        $filepath = '/';
    } else {
        $filepath = '/' . implode('/', $args) . '/';
    }

    // Retrieve the file from the Files API.
    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'local_log_sender', $filearea, $itemid, $filepath, $filename);
    if (!$file) {
        return false; // The file does not exist.
    }

    // We can now send the file back to the browser - in this case with a cache lifetime of 1 day and no filtering.
    send_stored_file($file, 86400, 0, $forcedownload, $options);
}
