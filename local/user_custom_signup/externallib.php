<?php

// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * External Web Service Template
 *
 * @package localwstemplate
 * @copyright 2011 Moodle Pty Ltd (http://moodle.com)
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once ($CFG->libdir . "/externallib.php");

class user_custom_signup_api extends external_api {

    /**
     * Returns description of method parameters
     *
     * @return external_function_parameters
     * @since Moodle 2.2
     */
    public static function user_create_facebook_user_parameters() {
        global $CFG;
		
        return new external_function_parameters(
        	array(
        		'email'       => new external_value(PARAM_EMAIL, 'Email'),
            	'firstname'   => new external_value(PARAM_NOTAGS, 'First Name'),
        	    'lastname'    => new external_value(PARAM_NOTAGS, 'Last Name'),
        		'service'    => new external_value(PARAM_NOTAGS, 'Service Name. example: moodle_mobile_app'),
        		'code'    => new external_value(PARAM_NOTAGS, 'Code')
        	)
        );
    }

    /**
     * Returns welcome message
     * @return string welcome message
     */
    public static function user_create_facebook_user($email, $firstname, $lastname, $serviceshortname, $code) {
    	
        global $CFG, $DB;
        require_once($CFG->dirroot."/lib/weblib.php");
        require_once($CFG->dirroot."/user/lib.php");
        require_once($CFG->dirroot."/user/profile/lib.php"); // Required for customfields related function.

        //Parameter validation
        //REQUIRED
        $params = self::validate_parameters(
        		      self::user_create_facebook_user_parameters(),
		              array(
		              		'email' => $email,
		                  	'firstname' => $firstname,
		                  	'lastname' => $lastname,
		              		'service' => $serviceshortname,
		              		'code' => $code
		              )
		          );
		
        //Context validation
        //OPTIONAL but in most web service it should present
        $context = context_system::instance();
        self::validate_context($context);
        require_capability('moodle/user:create', $context);
        
        //authenticate_user_login() will fail it if it's not 'googleoauth2'
        $user = $DB->get_record('user', array('auth' => 'googleoauth2', 'email' => $email, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id));
		
        if (!empty($user)) {
        	return self::create_token($user, $serviceshortname);
        }
        
        //get following incremented username
        $googleuserprefix = core_text::strtolower(get_config('auth/googleoauth2', 'googleuserprefix'));
        $lastusernumber = get_config('auth/googleoauth2', 'lastusernumber');
        $lastusernumber = empty($lastusernumber)? 1 : $lastusernumber++;
        //check the user doesn't exist
        $nextuser = $DB->record_exists('user', array('username' => $googleuserprefix.$lastusernumber));
        while ($nextuser) {
        	$lastusernumber++;
        	$nextuser = $DB->record_exists('user', array('username' => $googleuserprefix.$lastusernumber));
        }
        set_config('lastusernumber', $lastusernumber, 'auth/googleoauth2');
        $username = $googleuserprefix . $lastusernumber;
        
        // create new user
        $newuser = new stdClass();
        $newuser->email = $email;
        $newuser->firstname =  $firstname;
        $newuser->lastname =  $lastname;
        
        create_user_record($username, '', 'googleoauth2');
        $user = authenticate_user_login($username, null);
        
        if ($user) {
	        $newuser->id = $user->id;
	        $DB->update_record('user', $newuser);
        } else {
        	throw new moodle_exception('couldnotauthenticate', 'auth_googleoauth2');
        }
        
        return self::create_token($user, $serviceshortname);
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function user_create_facebook_user_returns() {
        return 
            new external_single_structure(
                array(
                	'token'       => new external_value(PARAM_TEXT, 'Token after Sign In'),
                )
        	);
    }

    public static function create_token($user, $serviceshortname) {
    	global $DB;
    	
    	//Non admin can not authenticate if maintenance mode
    	$hassiteconfig = has_capability('moodle/site:config', context_system::instance(), $user);
    	if (!empty($CFG->maintenance_enabled) and !$hassiteconfig) {
    		throw new moodle_exception('sitemaintenance', 'admin');
    	}
    	 
    	if (isguestuser($user)) {
    		throw new moodle_exception('noguest');
    	}
    	 
    	if (empty($user->confirmed)) {
    		throw new moodle_exception('usernotconfirmed', 'moodle', '', $user->username);
    	}

    	// let enrol plugins deal with new enrolments if necessary
    	enrol_check_plugins($user);
    	 
    	// setup user session to check capability
    	\core\session\manager::set_user($user);
    	//check if the service exists and is enabled
    	$service = $DB->get_record('external_services', array('shortname' => $serviceshortname, 'enabled' => 1));
    	
    	if (empty($service)) {
    		// will throw exception if no token found
    		throw new moodle_exception('servicenotavailable', 'webservice');
    	}

    	//check if there is any required system capability
    	if ($service->requiredcapability and !has_capability($service->requiredcapability, context_system::instance(), $user)) {
    		throw new moodle_exception('missingrequiredcapability', 'webservice', '', $service->requiredcapability);
    	}
    	 
    	//specific checks related to user restricted service
    	if ($service->restrictedusers) {
    		$authoriseduser = $DB->get_record('external_services_users',
    				array('externalserviceid' => $service->id, 'userid' => $user->id));
    		 
    		if (empty($authoriseduser)) {
    			throw new moodle_exception('usernotallowed', 'webservice', '', $serviceshortname);
    		}
    		 
    		if (!empty($authoriseduser->validuntil) and $authoriseduser->validuntil < time()) {
    			throw new moodle_exception('invalidtimedtoken', 'webservice');
    		}
    		 
    		if (!empty($authoriseduser->iprestriction) and !address_in_subnet(getremoteaddr(), $authoriseduser->iprestriction)) {
    			throw new moodle_exception('invalidiptoken', 'webservice');
    		}
    	}
    	 
    	//Check if a token has already been created for this user and this service
    	//Note: this could be an admin created or an user created token.
    	//      It does not really matter we take the first one that is valid.
    	$tokenssql = "SELECT t.id, t.sid, t.token, t.validuntil, t.iprestriction
              FROM {external_tokens} t
             WHERE t.userid = ? AND t.externalserviceid = ? AND t.tokentype = ?
          ORDER BY t.timecreated ASC";
    	$tokens = $DB->get_records_sql($tokenssql, array($user->id, $service->id, EXTERNAL_TOKEN_PERMANENT));
    	 
    	//A bit of sanity checks
    	foreach ($tokens as $key=>$token) {
    		 
    		/// Checks related to a specific token. (script execution continue)
    		$unsettoken = false;
    		//if sid is set then there must be a valid associated session no matter the token type
    		if (!empty($token->sid)) {
    			if (!\core\session\manager::session_exists($token->sid)){
    				//this token will never be valid anymore, delete it
    				$DB->delete_records('external_tokens', array('sid'=>$token->sid));
    				$unsettoken = true;
    			}
    		}
    		 
    		//remove token if no valid anymore
    		//Also delete this wrong token (similar logic to the web service servers
    		//    /webservice/lib.php/webservice_server::authenticate_by_token())
    		if (!empty($token->validuntil) and $token->validuntil < time()) {
    			$DB->delete_records('external_tokens', array('token'=>$token->token, 'tokentype'=> EXTERNAL_TOKEN_PERMANENT));
    			$unsettoken = true;
    		}
    		 
    		// remove token if its ip not in whitelist
    		if (isset($token->iprestriction) and !address_in_subnet(getremoteaddr(), $token->iprestriction)) {
    			$unsettoken = true;
    		}
    		 
    		if ($unsettoken) {
    			unset($tokens[$key]);
    		}
    	}
    	 
    	// if some valid tokens exist then use the most recent
    	if (count($tokens) > 0) {
    		$token = array_pop($tokens);
    	} else {
    		$hasperm = has_capability('moodle/webservice:createmobiletoken', context_system::instance());
    		if ( (($serviceshortname == MOODLE_OFFICIAL_MOBILE_SERVICE or $serviceshortname == 'local_mobile') 
    				and $hasperm)
    				//Note: automatically token generation is not available to admin (they must create a token manually)
    				or (!is_siteadmin($user) && has_capability('moodle/webservice:createtoken', context_system::instance()))) {
    					// if service doesn't exist, dml will throw exception
    					$service_record = $DB->get_record('external_services', array('shortname'=>$serviceshortname, 'enabled'=>1), '*', MUST_EXIST);
    					 
    					// Create a new token.
    					$token = new stdClass;
    					$token->token = md5(uniqid(rand(), 1));
    					$token->userid = $user->id;
    					$token->tokentype = EXTERNAL_TOKEN_PERMANENT;
    					$token->contextid = context_system::instance()->id;
    					$token->creatorid = $user->id;
    					$token->timecreated = time();
    					$token->externalserviceid = $service_record->id;
    					// MDL-43119 Token valid for 3 months (12 weeks).
    					$token->validuntil = $token->timecreated + 12 * WEEKSECS;
    					$token->id = $DB->insert_record('external_tokens', $token);
    					 
    					$params = array(
    							'objectid' => $token->id,
    							'relateduserid' => $user->id,
    							'other' => array(
    								'auto' => true
    							)
    					);
    					$event = \core\event\webservice_token_created::create($params);
    					$event->add_record_snapshot('external_tokens', $token);
    					$event->trigger();
    				} else {
    					throw new moodle_exception('cannotcreatetoken', 'webservice', '', $serviceshortname);
    				}
    	}
    	 
    	// log token access
    	$DB->set_field('external_tokens', 'lastaccess', time(), array('id'=>$token->id));
    	 
    	$params = array(
    			'objectid' => $token->id,
    	);
    	$event = \core\event\webservice_token_sent::create($params);
    	$event->add_record_snapshot('external_tokens', $token);
    	$event->trigger();
    	 
    	$return_token = array ("token" => $token->token);
    	return $return_token;
    }
}
