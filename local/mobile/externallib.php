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
 * External functions backported.
 *
 * @package    local_mobile
 * @copyright  2014 Juan Leyva <juan@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

require_once("$CFG->libdir/externallib.php");
require_once("$CFG->dirroot/local/mobile/futurelib.php");

class local_mobile_external extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     * @since Moodle 2.9
     */
    public static function core_user_remove_user_device_parameters() {
        return new external_function_parameters(
            array(
                'uuid'  => new external_value(PARAM_RAW, 'the device UUID'),
                'appid' => new external_value(PARAM_NOTAGS,
                                                'the app id, if empty devices matching the UUID for the user will be removed',
                                                VALUE_DEFAULT, ''),
            )
        );
    }

    /**
     * Remove a user device from the Moodle database (for PUSH notifications usually).
     *
     * @param string $uuid The device UUID.
     * @param string $appid The app id, opitonal parameter. If empty all the devices fmatching the UUID or the user will be removed.
     * @return array List of possible warnings and removal status.
     * @since Moodle 2.9
     */
    public static function core_user_remove_user_device($uuid, $appid = "") {
        global $CFG;
        require_once($CFG->dirroot . "/user/lib.php");

        $params = self::validate_parameters(self::core_user_remove_user_device_parameters(), array('uuid' => $uuid, 'appid' => $appid));

        $context = context_system::instance();
        self::validate_context($context);

        // Warnings array, it can be empty at the end but is mandatory.
        $warnings = array();

        $removed = user_remove_user_device($params['uuid'], $params['appid']);

        if (!$removed) {
            $warnings[] = array(
                'item' => $params['uuid'],
                'warningcode' => 'devicedoesnotexist',
                'message' => 'The device doesn\'t exists in the database'
            );
        }

        $result = array(
            'removed' => $removed,
            'warnings' => $warnings
        );

        return $result;
    }

    /**
     * Returns description of method result value.
     *
     * @return external_multiple_structure
     * @since Moodle 2.9
     */
    public static function core_user_remove_user_device_returns() {
        return new external_single_structure(
            array(
                'removed' => new external_value(PARAM_BOOL, 'True if removed, false if not removed because it didn\'t exists'),
                'warnings' => new external_warnings(),
            )
        );
    }

    /**
     * Describes the parameters for get_grades_table.
     *
     * @return external_external_function_parameters
     * @since Moodle 2.8
     */
    public static function gradereport_user_get_grades_table_parameters() {
        return new external_function_parameters (
            array(
                'courseid' => new external_value(PARAM_INT, 'Course Id', VALUE_REQUIRED),
                'userid'   => new external_value(PARAM_INT, 'Return grades only for this user (optional)', VALUE_DEFAULT, 0)
            )
        );
    }

    /**
     * Returns a list of grades tables for users in a course.
     *
     * @param int $courseid Course Id
     * @param int $userid   Only this user (optional)
     *
     * @return array the grades tables
     * @since Moodle 2.8
     */
    public static function gradereport_user_get_grades_table($courseid, $userid = 0) {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/group/lib.php');
        require_once($CFG->libdir  . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/lib.php');
        require_once($CFG->dirroot . '/grade/report/user/lib.php');

        $warnings = array();

        // Validate the parameter.
        $params = self::validate_parameters(self::gradereport_user_get_grades_table_parameters(),
            array(
                'courseid' => $courseid,
                'userid' => $userid)
            );

        // Compact/extract functions are not recommended.
        $courseid = $params['courseid'];
        $userid   = $params['userid'];

        // Function get_course internally throws an exception if the course doesn't exist.
        $course = get_course($courseid);

        $context = context_course::instance($courseid);
        self::validate_context($context);

        // Specific capabilities.
        require_capability('gradereport/user:view', $context);

        $user = null;

        if (empty($userid)) {
            require_capability('moodle/grade:viewall', $context);
        } else {
            $user = core_user::get_user($userid, '*', MUST_EXIST);
        }

        $access = false;

        if (has_capability('moodle/grade:viewall', $context)) {
            // Can view all course grades.
            $access = true;
        } else if ($userid == $USER->id and has_capability('moodle/grade:view', $context) and $course->showgrades) {
            // View own grades.
            $access = true;
        } else if (has_capability('moodle/grade:viewall', context_user::instance($userid)) and $course->showgrades) {
            // Can view grades of this user, parent most probably.
            $access = true;
        }

        if (!$access) {
            throw new moodle_exception('nopermissiontoviewgrades', 'error',  $CFG->wwwroot.  '/course/view.php?id=' . $courseid);
        }

        $gpr = new grade_plugin_return(
            array(
                'type' => 'report',
                'plugin' => 'user',
                'courseid' => $courseid,
                'userid' => $userid)
            );

        $tables = array();

        // Just one user.
        if ($user) {
            $report = new grade_report_user($courseid, $gpr, $context, $userid);
            $report->fill_table();

            // Notice that we use array_filter for deleting empty elements in the array.
            // Those elements are items or category not visible by the user.
            $tables[] = array(
                'courseid'      => $courseid,
                'userid'        => $user->id,
                'userfullname'  => fullname($user),
                'maxdepth'      => $report->maxdepth,
                'tabledata'     => $report->tabledata
            );

        } else {
            $defaultgradeshowactiveenrol = !empty($CFG->grade_report_showonlyactiveenrol);
            $showonlyactiveenrol = get_user_preferences('grade_report_showonlyactiveenrol', $defaultgradeshowactiveenrol);
            $showonlyactiveenrol = $showonlyactiveenrol || !has_capability('moodle/course:viewsuspendedusers', $context);

            $gui = new graded_users_iterator($course);
            $gui->require_active_enrolment($showonlyactiveenrol);
            $gui->init();

            while ($userdata = $gui->next_user()) {
                $currentuser = $userdata->user;
                $report = new grade_report_user($courseid, $gpr, $context, $currentuser->id);
                $report->fill_table();

                // Notice that we use array_filter for deleting empty elements in the array.
                // Those elements are items or category not visible by the user.
                $tables[] = array(
                    'courseid'      => $courseid,
                    'userid'        => $currentuser->id,
                    'userfullname'  => fullname($currentuser),
                    'maxdepth'      => $report->maxdepth,
                    'tabledata'     => $report->tabledata
                );
            }
            $gui->close();
        }

        $result = array();
        $result['tables'] = $tables;
        $result['warnings'] = $warnings;
        return $result;
    }

    /**
     * Creates a table column structure
     *
     * @return array
     * @since  Moodle 2.8
     */
    private static function grades_table_column() {
        return array (
            'class'   => new external_value(PARAM_RAW, 'class'),
            'content' => new external_value(PARAM_RAW, 'cell content'),
            'headers' => new external_value(PARAM_RAW, 'headers')
        );
    }

    /**
     * Describes tget_grades_table return value.
     *
     * @return external_single_structure
     * @since Moodle 2.8
     */
    public static function gradereport_user_get_grades_table_returns() {
        return new external_single_structure(
            array(
                'tables' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'courseid' => new external_value(PARAM_INT, 'course id'),
                            'userid'   => new external_value(PARAM_INT, 'user id'),
                            'userfullname' => new external_value(PARAM_TEXT, 'user fullname'),
                            'maxdepth'   => new external_value(PARAM_INT, 'table max depth (needed for printing it)'),
                            'tabledata' => new external_multiple_structure(
                                new external_single_structure(
                                    array(
                                        'itemname' => new external_single_structure(
                                            array (
                                                'class' => new external_value(PARAM_RAW, 'file name'),
                                                'colspan' => new external_value(PARAM_INT, 'mime type'),
                                                'content'  => new external_value(PARAM_RAW, ''),
                                                'celltype'  => new external_value(PARAM_RAW, ''),
                                                'id'  => new external_value(PARAM_ALPHANUMEXT, '')
                                            ), 'The item returned data', VALUE_OPTIONAL
                                        ),
                                        'leader' => new external_single_structure(
                                            array (
                                                'class' => new external_value(PARAM_RAW, 'file name'),
                                                'rowspan' => new external_value(PARAM_INT, 'mime type')
                                            ), 'The item returned data', VALUE_OPTIONAL
                                        ),
                                        'weight' => new external_single_structure(
                                            self::grades_table_column(), 'weight column', VALUE_OPTIONAL
                                        ),
                                        'grade' => new external_single_structure(
                                            self::grades_table_column(), 'grade column', VALUE_OPTIONAL
                                        ),
                                        'range' => new external_single_structure(
                                            self::grades_table_column(), 'range column', VALUE_OPTIONAL
                                        ),
                                        'percentage' => new external_single_structure(
                                            self::grades_table_column(), 'percentage column', VALUE_OPTIONAL
                                        ),
                                        'lettergrade' => new external_single_structure(
                                            self::grades_table_column(), 'lettergrade column', VALUE_OPTIONAL
                                        ),
                                        'rank' => new external_single_structure(
                                            self::grades_table_column(), 'rank column', VALUE_OPTIONAL
                                        ),
                                        'average' => new external_single_structure(
                                            self::grades_table_column(), 'average column', VALUE_OPTIONAL
                                        ),
                                        'feedback' => new external_single_structure(
                                            self::grades_table_column(), 'feedback column', VALUE_OPTIONAL
                                        ),
                                        'contributiontocoursetotal' => new external_single_structure(
                                            self::grades_table_column(), 'contributiontocoursetotal column', VALUE_OPTIONAL
                                        ),
                                    ), 'table'
                                )
                            )
                        )
                    )
                ),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Search contacts parameters description.
     *
     * @return external_function_parameters
     * @since 2.5
     */
    public static function core_message_search_contacts_parameters() {
        return new external_function_parameters(
            array(
                'searchtext' => new external_value(PARAM_CLEAN, 'String the user\'s fullname has to match to be found'),
                'onlymycourses' => new external_value(PARAM_BOOL, 'Limit search to the user\'s courses',
                    VALUE_DEFAULT, false)
            )
        );
    }

    /**
     * Search contacts.
     *
     * @param string $searchtext query string.
     * @param bool $onlymycourses limit the search to the user's courses only.
     * @return external_description
     * @since 2.5
     */
    public static function core_message_search_contacts($searchtext, $onlymycourses = false) {
        global $CFG, $USER;
        require_once($CFG->libdir . '/enrollib.php');
        require_once($CFG->dirroot . "/local/mobile/locallib.php");
        require_once($CFG->dirroot . "/user/lib.php");

        $params = array('searchtext' => $searchtext, 'onlymycourses' => $onlymycourses);
        $params = self::validate_parameters(self::core_message_search_contacts_parameters(), $params);
        // Extra validation, we do not allow empty queries.
        if ($params['searchtext'] === '') {
            throw new moodle_exception('querystringcannotbeempty');
        }
        $courseids = array();
        if ($params['onlymycourses']) {
            $mycourses = enrol_get_my_courses(array('id'));
            foreach ($mycourses as $mycourse) {
                $courseids[] = $mycourse->id;
            }
        } else {
            $courseids[] = SITEID;
        }
        // Retrieving the users matching the query.
        $users = message_search_users($courseids, $params['searchtext']);

        $results = array();
        foreach ($users as $user) {
            $results[$user->id] = $user;
        }
        // Reorganising information.
        foreach ($results as &$user) {
            $newuser = array(
                'id' => $user->id,
                'fullname' => fullname($user)
            );
            // Avoid undefined property notice as phone not specified.
            $user->phone1 = null;
            $user->phone2 = null;
            // Try to get the user picture, but sometimes this method can return null.
            $userdetails = user_get_user_details($user, null, array('profileimageurl', 'profileimageurlsmall'));
            if (!empty($userdetails)) {
                $newuser['profileimageurl'] = $userdetails['profileimageurl'];
                $newuser['profileimageurlsmall'] = $userdetails['profileimageurlsmall'];
            }
            $user = $newuser;
        }
        return $results;
    }
    /**
     * Search contacts return description.
     *
     * @return external_description
     * @since 2.5
     */
    public static function core_message_search_contacts_returns() {
        return new external_multiple_structure(
            new external_single_structure(
                array(
                    'id' => new external_value(PARAM_INT, 'User ID'),
                    'fullname' => new external_value(PARAM_NOTAGS, 'User full name'),
                    'profileimageurl' => new external_value(PARAM_URL, 'User picture URL', VALUE_OPTIONAL),
                    'profileimageurlsmall' => new external_value(PARAM_URL, 'Small user picture URL', VALUE_OPTIONAL)
                )
            ),
            'List of contacts'
        );
    }


    /**
     * Get blocked users parameters description.
     *
     * @return external_function_parameters
     * @since 2.9
     */
    public static function core_message_get_blocked_users_parameters() {
        return new external_function_parameters(
            array(
                'userid' => new external_value(PARAM_INT,
                                'the user whose blocked users we want to retrieve',
                                VALUE_REQUIRED),
            )
        );
    }

    /**
     * Retrieve a list of users blocked
     *
     * @param  int $userid the user whose blocked users we want to retrieve
     * @return external_description
     * @since 2.9
     */
    public static function core_message_get_blocked_users($userid) {
        global $CFG, $USER;
        require_once($CFG->dirroot . "/message/lib.php");

        // Warnings array, it can be empty at the end but is mandatory.
        $warnings = array();

        // Validate params.
        $params = array(
            'userid' => $userid
        );
        $params = self::validate_parameters(self::core_message_get_blocked_users_parameters(), $params);
        $userid = $params['userid'];

        // Validate context.
        $context = context_system::instance();
        self::validate_context($context);

        // Check if private messaging between users is allowed.
        if (empty($CFG->messaging)) {
            throw new moodle_exception('disabled', 'message');
        }

        $user = core_user::get_user($userid, 'id', MUST_EXIST);

        // Check if we have permissions for retrieve the information.
        if ($userid != $USER->id and !has_capability('moodle/site:readallmessages', $context)) {
            throw new moodle_exception('accessdenied', 'admin');
        }

        // Now, we can get safely all the blocked users.
        $users = message_get_blocked_users($user);

        $blockedusers = array();
        foreach ($users as $user) {
            $newuser = array(
                'id' => $user->id,
                'fullname' => fullname($user),
            );
            $newuser['profileimageurl'] = moodle_url::make_pluginfile_url(
                context_user::instance($user->id)->id, 'user', 'icon', null, '/', 'f1')->out(false);

            $blockedusers[] = $newuser;
        }

        $results = array(
            'users' => $blockedusers,
            'warnings' => $warnings
        );
        return $results;
    }

    /**
     * Get blocked users return description.
     *
     * @return external_single_structure
     * @since 2.9
     */
    public static function core_message_get_blocked_users_returns() {
        return new external_single_structure(
            array(
                'users' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'User ID'),
                            'fullname' => new external_value(PARAM_NOTAGS, 'User full name'),
                            'profileimageurl' => new external_value(PARAM_URL, 'User picture URL', VALUE_OPTIONAL)
                        )
                    ),
                    'List of blocked users'
                ),
                'warnings' => new external_warnings()
            )
        );
    }

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.9
     */
    public static function core_group_get_course_user_groups_parameters() {
        return new external_function_parameters(
            array(
                'courseid' => new external_value(PARAM_INT, 'id of course'),
                'userid' => new external_value(PARAM_INT, 'id of user')
            )
        );
    }

    /**
     * Get all groups in the specified course for the specified user
     *
     * @param int $courseid id of course
     * @param int $userid id of user
     * @return array of group objects (id, name ...)
     * @since Moodle 2.9
     */
    public static function core_group_get_course_user_groups($courseid, $userid) {
        global $USER;

        // Warnings array, it can be empty at the end but is mandatory.
        $warnings = array();

        $params = array(
            'courseid' => $courseid,
            'userid' => $userid
        );
        $params = self::validate_parameters(self::core_group_get_course_user_groups_parameters(), $params);
        $courseid = $params['courseid'];
        $userid = $params['userid'];

        // Validate course and user. get_course throws an exception if the course does not exists.
        $course = get_course($courseid);
        $user = core_user::get_user($userid, 'id', MUST_EXIST);

        // Security checks.
        $context = context_course::instance($courseid);
        self::validate_context($context);

         // Check if we have permissions for retrieve the information.
        if ($userid != $USER->id) {
            if (!has_capability('moodle/course:managegroups', $context)) {
                throw new moodle_exception('accessdenied', 'admin');
            }
            // Validate if the user is enrolled in the course.
            if (!is_enrolled($context, $userid)) {
                // We return a warning because the function does not fail for not enrolled users.
                $warning['item'] = 'course';
                $warning['itemid'] = $courseid;
                $warning['warningcode'] = '1';
                $warning['message'] = "User $userid is not enrolled in course $courseid";
                $warnings[] = $warning;
            }
        }

        $usergroups = array();
        if (empty($warnings)) {
            $groups = groups_get_all_groups($courseid, $userid, 0, 'g.id, g.name, g.description, g.descriptionformat');

            foreach ($groups as $group) {
                list($group->description, $group->descriptionformat) =
                    external_format_text($group->description, $group->descriptionformat,
                            $context->id, 'group', 'description', $group->id);
                $usergroups[] = (array)$group;
            }
        }

        $results = array(
            'groups' => $usergroups,
            'warnings' => $warnings
        );
        return $results;
    }

    /**
     * Returns description of method result value
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function core_group_get_course_user_groups_returns() {
        return new external_single_structure(
            array(
                'groups' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'id' => new external_value(PARAM_INT, 'group record id'),
                            'name' => new external_value(PARAM_TEXT, 'multilang compatible name, course unique'),
                            'description' => new external_value(PARAM_RAW, 'group description text'),
                            'descriptionformat' => new external_format_value('description')
                        )
                    )
                ),
                'warnings' => new external_warnings(),
            )
        );
    }
    
   /**
     * Returns description of method parameters.
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function course_get_courses_by_category_parameters() {
    	global $CFG;
    	
    	return new external_function_parameters(
    			array(
    					'categoryid'       => new external_value(PARAM_INT, 'ID of Category', VALUE_REQUIRED),
    			)
    	);
    }
    
    /**
     * 
     */
    public static function course_get_courses_by_category($categoryid) {
    	
    	global $CFG, $DB;
    	require_once($CFG->dirroot . "/course/lib.php");
    	require_once($CFG->libdir. '/coursecatlib.php');
    	
    	//validate parameter
    	$params = self::validate_parameters(self::course_get_courses_by_category_parameters(),
    			array('categoryid' => $categoryid));
    	
    	$courses = $DB->get_records_list('course', 'category', array( $categoryid ));
    	
    	//create return value
    	$coursesinfo = array();
    	foreach ($courses as $course) {
    	
    		// now security checks
    		$context = context_course::instance($course->id, IGNORE_MISSING);
    		$courseformatoptions = course_get_format($course)->get_format_options();
    		try {
    			self::validate_context($context);
    		} catch (Exception $e) {
    			$exceptionparam = new stdClass();
    			$exceptionparam->message = $e->getMessage();
    			$exceptionparam->courseid = $course->id;
    			throw new moodle_exception('errorcoursecontextnotvalid', 'webservice', '', $exceptionparam);
    		}
    		require_capability('moodle/course:view', $context);
    	
    		$courseinfo = array();
    		$courseinfo['id'] = $course->id;
    		$courseinfo['fullname'] = $course->fullname;
    		$courseinfo['shortname'] = $course->shortname;
    		$courseinfo['categoryid'] = $course->category;
    		list($courseinfo['summary'], $courseinfo['summaryformat']) =
    		external_format_text($course->summary, $course->summaryformat, $context->id, 'course', 'summary', 0);
    		$courseinfo['format'] = $course->format;
    		$courseinfo['startdate'] = $course->startdate;
    		if (array_key_exists('numsections', $courseformatoptions)) {
    			// For backward-compartibility
    			$courseinfo['numsections'] = $courseformatoptions['numsections'];
    		}
    	
    		//some field should be returned only if the user has update permission
    		$courseadmin = has_capability('moodle/course:update', $context);
    		if ($courseadmin) {
    			$courseinfo['categorysortorder'] = $course->sortorder;
    			$courseinfo['idnumber'] = $course->idnumber;
    			$courseinfo['showgrades'] = $course->showgrades;
    			$courseinfo['showreports'] = $course->showreports;
    			$courseinfo['newsitems'] = $course->newsitems;
    			$courseinfo['visible'] = $course->visible;
    			$courseinfo['maxbytes'] = $course->maxbytes;
    			if (array_key_exists('hiddensections', $courseformatoptions)) {
    				// For backward-compartibility
    				$courseinfo['hiddensections'] = $courseformatoptions['hiddensections'];
    			}
    			$courseinfo['groupmode'] = $course->groupmode;
    			$courseinfo['groupmodeforce'] = $course->groupmodeforce;
    			$courseinfo['defaultgroupingid'] = $course->defaultgroupingid;
    			$courseinfo['lang'] = $course->lang;
    			$courseinfo['timecreated'] = $course->timecreated;
    			$courseinfo['timemodified'] = $course->timemodified;
    			$courseinfo['forcetheme'] = $course->theme;
    			$courseinfo['enablecompletion'] = $course->enablecompletion;
    			$courseinfo['completionnotify'] = $course->completionnotify;
    			$courseinfo['courseformatoptions'] = array();
    			foreach ($courseformatoptions as $key => $value) {
    				$courseinfo['courseformatoptions'][] = array(
    						'name' => $key,
    						'value' => $value
    				);
    			}
    		}
    	
    		if ($courseadmin or $course->visible
    				or has_capability('moodle/course:viewhiddencourses', $context)) {
    					$coursesinfo[] = $courseinfo;
    		}
    	}
    	
    	return $coursesinfo;
    }
    
    /**
     * Returns description of method result value.
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function course_get_courses_by_category_returns() {
    	return new external_multiple_structure(
    			new external_single_structure(
    					array(
    							'id' => new external_value(PARAM_INT, 'course id'),
    							'shortname' => new external_value(PARAM_TEXT, 'course short name'),
    							'categoryid' => new external_value(PARAM_INT, 'category id'),
    							'categorysortorder' => new external_value(PARAM_INT,
    									'sort order into the category', VALUE_OPTIONAL),
    							'fullname' => new external_value(PARAM_TEXT, 'full name'),
    							'idnumber' => new external_value(PARAM_RAW, 'id number', VALUE_OPTIONAL),
    							'summary' => new external_value(PARAM_RAW, 'summary'),
    							'summaryformat' => new external_format_value('summary'),
    							'format' => new external_value(PARAM_PLUGIN,
    									'course format: weeks, topics, social, site,..'),
    							'showgrades' => new external_value(PARAM_INT,
    									'1 if grades are shown, otherwise 0', VALUE_OPTIONAL),
    							'newsitems' => new external_value(PARAM_INT,
    									'number of recent items appearing on the course page', VALUE_OPTIONAL),
    							'startdate' => new external_value(PARAM_INT,
    									'timestamp when the course start'),
    							'numsections' => new external_value(PARAM_INT,
    									'(deprecated, use courseformatoptions) number of weeks/topics',
    									VALUE_OPTIONAL),
    							'maxbytes' => new external_value(PARAM_INT,
    									'largest size of file that can be uploaded into the course',
    									VALUE_OPTIONAL),
    							'showreports' => new external_value(PARAM_INT,
    									'are activity report shown (yes = 1, no =0)', VALUE_OPTIONAL),
    							'visible' => new external_value(PARAM_INT,
    									'1: available to student, 0:not available', VALUE_OPTIONAL),
    							'hiddensections' => new external_value(PARAM_INT,
    									'(deprecated, use courseformatoptions) How the hidden sections in the course are displayed to students',
    									VALUE_OPTIONAL),
    							'groupmode' => new external_value(PARAM_INT, 'no group, separate, visible',
    									VALUE_OPTIONAL),
    							'groupmodeforce' => new external_value(PARAM_INT, '1: yes, 0: no',
    									VALUE_OPTIONAL),
    							'defaultgroupingid' => new external_value(PARAM_INT, 'default grouping id',
    									VALUE_OPTIONAL),
    							'timecreated' => new external_value(PARAM_INT,
    									'timestamp when the course have been created', VALUE_OPTIONAL),
    							'timemodified' => new external_value(PARAM_INT,
    									'timestamp when the course have been modified', VALUE_OPTIONAL),
    							'enablecompletion' => new external_value(PARAM_INT,
    									'Enabled, control via completion and activity settings. Disbaled,
                                        not shown in activity settings.',
    									VALUE_OPTIONAL),
    							'completionnotify' => new external_value(PARAM_INT,
    									'1: yes 0: no', VALUE_OPTIONAL),
    							'lang' => new external_value(PARAM_SAFEDIR,
    									'forced course language', VALUE_OPTIONAL),
    							'forcetheme' => new external_value(PARAM_PLUGIN,
    									'name of the force theme', VALUE_OPTIONAL),
    							'courseformatoptions' => new external_multiple_structure(
    									new external_single_structure(
    											array('name' => new external_value(PARAM_ALPHANUMEXT, 'course format option name'),
    													'value' => new external_value(PARAM_RAW, 'course format option value')
    											)),
    									'additional options for particular course format', VALUE_OPTIONAL
    							),
    					), 'course'
    			)
    	);
    }
	
	// SEARCH COURSES
	/**
     * Returns description of method parameters.
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function course_search_courses_parameters() {
    	global $CFG;
    	
    	return new external_function_parameters(
    			array(
    					'keyword'       => new external_value(PARAM_TEXT, 'KEYWORD', VALUE_REQUIRED),
    			)
    	);
    }
    
    /**
     * 
     */
    public static function course_search_courses($keyword) {
    	
    	global $CFG, $DB;
    	require_once($CFG->dirroot . "/course/lib.php");
    	require_once($CFG->libdir. '/coursecatlib.php');
    	
    	//validate parameter
    	$params = self::validate_parameters(self::course_search_courses_parameters(),
    			array('keyword' => $keyword));
    	
		// search head
    	// $courses = $DB->get_records_list('course', 'category', array( $categoryid ));
		$searchcriteria = array( 'search' => $keyword );
		$displayoptions = array(
            'recursive' => true,
            'sort' => array('fullname' => 1)
		);

		$courses = coursecat::search_courses($searchcriteria, $displayoptions);
    	
    	//create return value
    	$coursesinfo = array();
    	foreach ($courses as $course) {
    	
    		// now security checks
    		$context = context_course::instance($course->id, IGNORE_MISSING);
    		$courseformatoptions = course_get_format($course)->get_format_options();
    		try {
    			self::validate_context($context);
    		} catch (Exception $e) {
    			$exceptionparam = new stdClass();
    			$exceptionparam->message = $e->getMessage();
    			$exceptionparam->courseid = $course->id;
    			throw new moodle_exception('errorcoursecontextnotvalid', 'webservice', '', $exceptionparam);
    		}
    		require_capability('moodle/course:view', $context);
    	
    		$courseinfo = array();
    		$courseinfo['id'] = $course->id;
    		$courseinfo['fullname'] = $course->fullname;
    		$courseinfo['shortname'] = $course->shortname;
    		$courseinfo['categoryid'] = $course->category;
    		list($courseinfo['summary'], $courseinfo['summaryformat']) =
    		external_format_text($course->summary, $course->summaryformat, $context->id, 'course', 'summary', 0);
    		$courseinfo['format'] = $course->format;
    		$courseinfo['startdate'] = $course->startdate;
    		if (array_key_exists('numsections', $courseformatoptions)) {
    			// For backward-compartibility
    			$courseinfo['numsections'] = $courseformatoptions['numsections'];
    		}
    	
    		//some field should be returned only if the user has update permission
    		$courseadmin = has_capability('moodle/course:update', $context);
    		if ($courseadmin) {
    			$courseinfo['categorysortorder'] = $course->sortorder;
    			$courseinfo['idnumber'] = $course->idnumber;
    			$courseinfo['showgrades'] = $course->showgrades;
    			$courseinfo['showreports'] = $course->showreports;
    			$courseinfo['newsitems'] = $course->newsitems;
    			$courseinfo['visible'] = $course->visible;
    			$courseinfo['maxbytes'] = $course->maxbytes;
    			if (array_key_exists('hiddensections', $courseformatoptions)) {
    				// For backward-compartibility
    				$courseinfo['hiddensections'] = $courseformatoptions['hiddensections'];
    			}
    			$courseinfo['groupmode'] = $course->groupmode;
    			$courseinfo['groupmodeforce'] = $course->groupmodeforce;
    			$courseinfo['defaultgroupingid'] = $course->defaultgroupingid;
    			$courseinfo['lang'] = $course->lang;
    			$courseinfo['timecreated'] = $course->timecreated;
    			$courseinfo['timemodified'] = $course->timemodified;
    			$courseinfo['forcetheme'] = $course->theme;
    			$courseinfo['enablecompletion'] = $course->enablecompletion;
    			$courseinfo['completionnotify'] = $course->completionnotify;
    			$courseinfo['courseformatoptions'] = array();
    			foreach ($courseformatoptions as $key => $value) {
    				$courseinfo['courseformatoptions'][] = array(
    						'name' => $key,
    						'value' => $value
    				);
    			}
    		}
    	
    		if ($courseadmin or $course->visible
    				or has_capability('moodle/course:viewhiddencourses', $context)) {
    					$coursesinfo[] = $courseinfo;
    		}
    	}
    	
    	return $coursesinfo;
    }
    
    /**
     * Returns description of method result value.
     *
     * @return external_description
     * @since Moodle 2.9
     */
    public static function course_search_courses_returns() {
    	return new external_multiple_structure(
			new external_single_structure(
				array(
					'id' => new external_value(PARAM_INT, 'course id'),
					'shortname' => new external_value(PARAM_TEXT, 'course short name'),
					'categoryid' => new external_value(PARAM_INT, 'category id'),
					'categorysortorder' => new external_value(PARAM_INT,
							'sort order into the category', VALUE_OPTIONAL),
					'fullname' => new external_value(PARAM_TEXT, 'full name'),
					'idnumber' => new external_value(PARAM_RAW, 'id number', VALUE_OPTIONAL),
					'summary' => new external_value(PARAM_RAW, 'summary'),
					'summaryformat' => new external_format_value('summary'),
					'format' => new external_value(PARAM_PLUGIN,
							'course format: weeks, topics, social, site,..'),
					'showgrades' => new external_value(PARAM_INT,
							'1 if grades are shown, otherwise 0', VALUE_OPTIONAL),
					'newsitems' => new external_value(PARAM_INT,
							'number of recent items appearing on the course page', VALUE_OPTIONAL),
					'startdate' => new external_value(PARAM_INT,
							'timestamp when the course start'),
					'numsections' => new external_value(PARAM_INT,
							'(deprecated, use courseformatoptions) number of weeks/topics',
							VALUE_OPTIONAL),
					'maxbytes' => new external_value(PARAM_INT,
							'largest size of file that can be uploaded into the course',
							VALUE_OPTIONAL),
					'showreports' => new external_value(PARAM_INT,
							'are activity report shown (yes = 1, no =0)', VALUE_OPTIONAL),
					'visible' => new external_value(PARAM_INT,
							'1: available to student, 0:not available', VALUE_OPTIONAL),
					'hiddensections' => new external_value(PARAM_INT,
							'(deprecated, use courseformatoptions) How the hidden sections in the course are displayed to students',
							VALUE_OPTIONAL),
					'groupmode' => new external_value(PARAM_INT, 'no group, separate, visible',
							VALUE_OPTIONAL),
					'groupmodeforce' => new external_value(PARAM_INT, '1: yes, 0: no',
							VALUE_OPTIONAL),
					'defaultgroupingid' => new external_value(PARAM_INT, 'default grouping id',
							VALUE_OPTIONAL),
					'timecreated' => new external_value(PARAM_INT,
							'timestamp when the course have been created', VALUE_OPTIONAL),
					'timemodified' => new external_value(PARAM_INT,
							'timestamp when the course have been modified', VALUE_OPTIONAL),
					'enablecompletion' => new external_value(PARAM_INT,
							'Enabled, control via completion and activity settings. Disbaled,
							not shown in activity settings.',
							VALUE_OPTIONAL),
					'completionnotify' => new external_value(PARAM_INT,
							'1: yes 0: no', VALUE_OPTIONAL),
					'lang' => new external_value(PARAM_SAFEDIR,
							'forced course language', VALUE_OPTIONAL),
					'forcetheme' => new external_value(PARAM_PLUGIN,
							'name of the force theme', VALUE_OPTIONAL),
					'courseformatoptions' => new external_multiple_structure(
							new external_single_structure(
									array('name' => new external_value(PARAM_ALPHANUMEXT, 'course format option name'),
											'value' => new external_value(PARAM_RAW, 'course format option value')
									)),
							'additional options for particular course format', VALUE_OPTIONAL
					),
				), 'course'
			)
    	);
    }
}