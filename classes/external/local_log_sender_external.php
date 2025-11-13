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
 * External functions class.
 *
 * @package   local_log_sender
 * @copyright 2025 Pierre Duverneix {@link https://github.com/Hipjea}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_log_sender\external;

defined('MOODLE_INTERNAL') || die();

use external_function_parameters;
use external_single_structure;
use external_value;
use external_api;

class local_log_sender_external extends external_api {
    public static function receive_lrs_response_parameters() {
        return new external_function_parameters([
            'token' => new external_value(PARAM_TEXT, 'Authentication token'),
            'data'  => new external_value(PARAM_RAW,  'Payload from LRS')
        ]);
    }

    public static function receive_lrs_response($token, $data) {
        global $CFG;

        $params = self::validate_parameters(
            self::receive_lrs_response_parameters(),
            ['token' => $token, 'data' => $data]
        );

        $expectedtoken = get_config('local_log_sender', 'lrs_callback_token');
        if ($token !== $expectedtoken) {
            throw new invalid_parameter_exception('Invalid token');
        }

        $payload = json_decode($data);
        error_log("Received LRS payload: " . $payload);

        return ['status' => 'success'];
    }

    public static function receive_lrs_response_returns() {
        return new external_single_structure([
            'status' => new external_value(PARAM_TEXT, 'Operation result')
        ]);
    }
}
