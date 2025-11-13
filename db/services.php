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
 * Resource external functions and service definitions.
 *
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_log_sender_receive_lrs_response' => [
        'classname'   => 'local_log_sender_external',
        'methodname'  => 'receive_lrs_response',
        'classpath'   => 'local/log_sender/externallib.php',
        'description' => 'Receives a response from the LRS server and processes it.',
        'type'        => 'write',
        'ajax'        => true,
        'services'    => ['log_sender_service']
    ],
];

$services = [
    'log_sender_service' => [
        'functions' => ['local_log_sender_receive_lrs_response'],
        'restrictedusers' => 0,
        'enabled' => 1
    ]
];
