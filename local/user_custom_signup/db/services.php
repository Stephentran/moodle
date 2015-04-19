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
 * External functions and service definitions.
 *
 * @package    local_mobile
 * @copyright  2014 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

$functions = array(
	'user_create_facebook_user' => array(
			'classname'   => 'user_custom_signup_api',
			'methodname'  => 'user_create_facebook_user',
			'classpath'   => 'local/user_custom_signup/externallib.php',
			'description' => 'Create facebook user from auth token',
			'type'        => 'write',
	),
);

$services = array(
   'Custom Facebook Signup/Signin Service'  => array(
        'functions' => array (
        	'user_create_facebook_user',
        ),
        'enabled' => 1,
        'restrictedusers' => 0,
        'shortname' => 'user_custom_signup',
        'downloadfiles' => 1,
        'uploadfiles' => 1
    ),
);