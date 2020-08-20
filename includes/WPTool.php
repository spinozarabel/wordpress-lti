<?php
/*
 *  wordpress-lti - WordPress module to add LTI support
 *  Copyright (C) 2020  Simon Booth, Stephen P Vickers
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License along
 *  with this program; if not, write to the Free Software Foundation, Inc.,
 *  51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 *
 *  Contact: s.p.booth@stir.ac.uk
 */

use ceLTIc\LTI;
use ceLTIc\LTI\Tool;
use ceLTIc\LTI\ResourceLink;
use ceLTIc\LTI\Outcome;

/* -------------------------------------------------------------------
 * This function is called when a successful LTI call is made. Is is
 * passed a class (tool_provider) that can access all the details of
 * the LTI call
 *
 * Parameters
 *  tool_provider - intance of of BasicLTI_Tool_Provider
 * ----------------------------------------------------------------- */

class WPTool extends Tool
{

    public function __construct($data_connector)
    {
        parent::__construct($data_connector);
        // --- begin added by Madhu --->
        $this->verbose = true;
        // --- end added by Madhu
        $this->setParameterConstraint('resource_link_id', true, 40, array('basic-lti-launch-request'));
        $this->setParameterConstraint('user_id', true);

        // Get settings and check whether sharing is enabled.
        $this->allowSharing = true;

        $this->signatureMethod = LTI_SIGNATURE_METHOD;
        $this->kid = LTI_KID;
        $this->rsaKey = LTI_PRIVATE_KEY;
        $this->requiredScopes = array(
            LTI\Service\Membership::$SCOPE,
            LTI\Service\Result::$SCOPE,
            LTI\Service\Score::$SCOPE,
            'https://purl.imsglobal.org/spec/lti-ext/scope/outcomes'
        );
    }

    public function onLaunch()
    {
        // If multisite support isn't in play, go home
        if (!is_multisite()) {
            $this->message = __('The LTI Plugin requires a Multisite installation of WordPress', 'lti-text');
            $this->ok = false;
            return;
        }

        // Clear any existing connections
        wp_logout();

        // Clear these before use
        $_SESSION[LTI_SESSION_PREFIX . 'return_url'] = '';
        $_SESSION[LTI_SESSION_PREFIX . 'return_name'] = '';

        // Store return URL for later use, if present
        if (!empty($this->returnUrl)) {
            $_SESSION[LTI_SESSION_PREFIX . 'return_url'] = (strpos($this->returnUrl, '?') === false) ? $this->returnUrl . '?' : $this->returnUrl . '&';
            $_SESSION[LTI_SESSION_PREFIX . 'return_name'] = 'Return to VLE';
            if (!empty($this->platform->name)) {
                $_SESSION[LTI_SESSION_PREFIX . 'return_name'] = 'Return to ' . $this->platform->name;
            }
        }

        // Get what we are using as the username (unique_id-consumer_key, i.e. _21_1-stir.ac.uk)
        $options 		= get_site_option('lti_choices');
        $scope_userid   = lti_get_scope($this->platform->getKey());
        $user_login     = $this->userResult->getID($scope_userid);
        // write this back into object for use elsewhere
        $this->user_login = $user_login;

        // ------------- begin madhu added ------->

        // run a function to extract intended site name and id
    	$this->getPathforSiteBasedOnTitle();
        return;
        if (empty($this->blog_id))
    	{
            // couldn't get intended site id, return with error message
    		$this->reason = __('Could not get a valid blog ID from name of launched activity. ' .
    									 'Inform SriToni Admin', 'lti-text');
            $this->ok = false;
            return;
    	}
        // for all payment sites get the Moodle user details to reject users early
        if( preg_match("/\bpayments\b/i", $this->resource_link->title) )
		{
			$this->getFilteredMoodleUserData();

			// if there was an error with the Moodle API exit and return to Moodle with error message
			if ($this->user_moodle_data->error)
			{
				$this->reason = __($this->user_moodle_data->message, 'lti-text');
                $this->ok = false;
                return;
			}

			// check wether user VA needs to be created
			if ($this->user_moodle_data->va_to_be_created)
			{
				// update if existing otherwise create new VA
				$this->update_create_VA();
				// check error message for errors in creation of VA
				if (!empty($this->user_moodle_data->error_va_create))
				{
					$this->reason = __($user_moodle_data->error_va_create, 'lti-text');
                    $this->ok = false;
                    return;
				}
				// No error in creation, Update user data object with newly created VA
				// update user moodle data object to update Moodle user profile as well as WP user meta later on

				// since we might have created or reread VA data lets update the user's profile field
				$this->update_moodle_field_virtualaccounts();
			}
		}

        // ------------- end madhu added section

        // Sanitize username stripping out unsafe characters
        $user_login = sanitize_user($user_login);

        // Apply the function pre_user_login before saving to the DB.
        $user_login = apply_filters('pre_user_login', $user_login);

        // Check if this username, $user_login, is already defined
        $user = get_user_by('login', $user_login);

        if ($user) {
            // If user exists, simply save the current details
            $user->first_name = $this->userResult->firstname;
            $user->last_name = $this->userResult->lastname;
            $user->display_name = $this->userResult->fullname;
            $result = wp_insert_user($user);
        } else {
            // Create username if user provisioning is on
            $result = wp_insert_user(
                array(
                    'user_login' => $user_login,
                    'user_pass' => wp_generate_password(),
                    'user_nicename' => $user_login,
                    'first_name' => $this->userResult->firstname,
                    'last_name' => $this->userResult->lastname,
                    //'user_email'=> $this->userResult->email,
                    //'user_url' => 'http://',
                    'display_name' => $this->userResult->fullname
                )
            );
            // Handle any errors by capturing and returning to the platform
            if (is_wp_error($result)) {
                $this->reason = $result->get_error_message();
                $this->ok = false;
                return;
            } else {
                // Get the new users details
                $user = get_user_by('login', $user_login);
            }
        }

        // Get user ID
        $user_id = $user->ID;

        // Staff or Learner
        $staff = $this->userResult->isStaff() || $this->userResult->isAdmin();
        $learner = $this->userResult->isLearner();

        // set up some useful variables
        $key = $this->resourceLink->getKey();
        $context_id = $this->context->getId();
        $resource_id = $this->resourceLink->getId();

        // Create blog
        $use_context = false;
        if (!empty($context_id)) {
            $use_context = ($this->resourceLink->getSetting('custom_use_context') == 'true') ? true : false;
        }

        if ($use_context) {
            // Create new blog, if does not exist. Note this gives one blog per context, the platform supplies a context_id
            // otherwise it creates a blog per resource_id
            $path = $key . '_' . $context_id;
        } else {
            // Create new blog, if does not exist. Note this gives one blog per resource_id
            $path = $key . $resource_id;
        }

        // Replace any non-allowed characters in WordPress with -
        $path = preg_replace('/[^_0-9a-zA-Z-]+/', '-', $path);

        // Sanity Check: Ensure that path is only _A-Za-z0-9- --- the above should stop this.
        if (preg_match('/[^_0-9a-zA-Z-]+/', $path) == 1) {
            $this->reason = __('No Blog has been created as the name contains non-alphanumeric: (_a-zA-Z0-9-) allowed', 'lti-text');
            $this->ok = false;
            return;
        }

        // Get any folder(s) that WordPress might be living in
        $wppath = parse_url(get_option('siteurl'), PHP_URL_PATH);
        $path = $wppath . '/' . trailingslashit($path);

        // Get the id of the blog, if exists
        $blog_id = domain_exists(DOMAIN_CURRENT_SITE, $path, 1);
        // If Blog does not exist and this is a member of staff and blog provisioning is on, create blog
        if (!$blog_id && $staff) {
            $blog_id = wpmu_create_blog(DOMAIN_CURRENT_SITE, $path, $this->resourceLink->title, $user_id, '', '1');
            update_blog_option($blog_id, 'blogdescription', __('Provisioned by LTI', 'lti-text'));
        }

        // Blog will exist by this point unless this user is student/no role.
        if (!$blog_id) {
            $this->reason = __('No Blog has been created for this context', 'lti-text');
            $this->ok = false;
            return;
        }

        // Update/create blog name
        update_blog_option($blog_id, 'blogname', $this->resourceLink->title);

        $role = 'subscriber';
        if ($staff) {
            $role = 'administrator';
        }
        if ($learner) {
            $role = 'author';
        }

        // Add user to blog and set role
        if (!is_user_member_of_blog($user_id, $blog_id)) {
            add_user_to_blog($blog_id, $user_id, $role);
        }

        // Users added via this route should only have access to this
        // (path) site. Remove from the default blog.
        remove_user_from_blog($user_id, 1);

        // Login the user
        wp_set_current_user($user_id, $user_login);
        wp_set_auth_cookie($user_id);
        do_action('wp_login', $user_login, $user);

        // Switch to blog
        switch_to_blog($blog_id);

        // Note this is an LTI provisioned Blog.
        add_option('ltisite', true);

        // As this is an LTI provisioned Blog we store the consumer key and
        // context id as options with the session meaning we can access elsewhere
        // in the code.
        // Store lti key & context id in $_SESSION variables
        $_SESSION[LTI_SESSION_PREFIX . 'key'] = $key;
        $_SESSION[LTI_SESSION_PREFIX . 'resourceid'] = $resource_id;

        // Store the key/context in case we need to sync shares --- this ensures we return
        // to the correct platform and not the primary platform
        $_SESSION[LTI_SESSION_PREFIX . 'userkey'] = $this->userResult->getResourceLink()->getKey();
        $_SESSION[LTI_SESSION_PREFIX . 'userresourcelink'] = $this->userResult->getResourceLink()->getId();

        // If users role in platform has changed (e.g. staff -> student),
        // then their role in the blog should change
        $user = new WP_User($user_id);
        if ($user->has_cap('administrator') && $role != 'administrator') {
            $user->add_role($role);
            $user->remove_role('administrator');
        }

        if ($user->has_cap('author') && $role != 'author') {
            $user->add_role($role);
            $user->remove_role('author');
        }

        if ($user->has_cap('subscriber') && $role != 'subscriber') {
            $user->add_role($role);
            $user->remove_role('subscriber');
        }

        // Send login time to platform if has outcomes service and can handle freetext
        $context = $this->resourceLink;

        if ($context->hasOutcomesService()) {

            // Presently this is just a demo of the outcome services and updating the menu bar in WordPress
            $outcome = new Outcome();
            $outcome->type = ResourceLink::EXT_TYPE_TEXT;
            $result = $context->doOutcomesService(ResourceLink::EXT_READ, $outcome, $this->userResult);

            // If we have successfully read then update the user metadata
            if ($result) {
                update_user_meta($user_id, 'Last Login', $outcome->getValue());
            }

            $outcome->setValue(date('d-F-Y G:i', time()));
            $context->doOutcomesService(ResourceLink::EXT_WRITE, $outcome, $this->userResult);
        }

        // Return URL for re-direction by Tool Provider class
        $this->redirectUrl = get_bloginfo('url');
    }

    /*
    *  Based on activity name, the path is extracted and written to object
    *  The blog_id of intended site is also extracted and written to object
    */
    public function getPathforSiteBasedOnTitle()
    {
        // calling activity name from Moodle
        $activity_name = $this->resourceLink->title;

        // extract the name of the site from the activity name, all text after the colon.
    	$site_name = substr($activity_name, strpos($activity_name, ":") + 1);

        // strip leading and lagging spaces if ANY
    	$site_name = trim($site_name);

        // write this back to the object for use elsewhere
        $this->site_name = $site_name;

    	($this->verbose ?  error_log('extracted site name is: ' . $site_name) : false);

        // Get any folder(s) that WordPress might be living in
    	$wppath = parse_url(get_option('siteurl'), PHP_URL_PATH);
    	$fullpath = $wppath . '/' . trailingslashit($site_name);

    	// Get the id of the blog, if exists
    	$blog_id = domain_exists(DOMAIN_CURRENT_SITE, $fullpath, 1);

        // write this back to the object for use when returned
        $this->blog_id = $blog_id ?? null;

    	return;
    }

    /**
    * Gets data from Moodle Server for the user given by the Moodle user ID using Moodle REST API
    * The user data is fetched.
    * Based on calling course, a groupingid is preset and group is fetched.
    * If values for studentcat field don't match permissible values user is returned with error message
    * If values for group don't match permissible values user is returned with error message
    * All required Moodle data is packaged into an aobject and returned
    */
    public function getFilteredMoodleUserData()
    {
    		require_once(__DIR__."/madhu_added_api/MoodleRest.php");	// API to acess Moodle

            // extract variable from object to make references short
            $user_login = $this->user_login;
            $blog_id    = $this->blog_id;
            $site_name  = $this->site_name;

    		// null the $user_moodle_data object to start with
    		$user_moodle_data	= new stdClass;

    		// initialize VA vreation flag to false up front
    		$user_moodle_data->va_to_be_created = false;

    		// these are the possible values of student category that should be defined in Moodle.
    		$studentcat_possible	= explode( "," , get_blog_option($blog_id, 'sritoni_settings')['studentcat_possible'] );

    		// array of possible groups. User must be in one of these
    		$group_possible			= explode( "," , get_blog_option($blog_id, 'sritoni_settings')['group_possible'] );

    		// array of whitelisted users for whom no checks are done
    		$whitelist_idnumbers	= explode( "," , get_blog_option($blog_id, 'sritoni_settings')['whitelist_idnumbers'] );

    		// get the string of course ID - Grouping ID comma separated list from settings
    		$setting_courseid_groupingid = get_blog_option($blog_id, 'sritoni_settings')['courseid_groupingid'];
    		$chunks = array_chunk(preg_split('/(-|,)/', $setting_courseid_groupingid), 2);
    		$courseid_groupingid_arr = array_combine(array_column($chunks, 0), array_column($chunks, 1));

    		// get beneficiary name from settings
    		$beneficiary_name	= get_blog_option( $blog_id, "sritoni_settings")["beneficiary_name"];
    		$user_moodle_data->beneficiary_name	= $beneficiary_name;

    		// prepare the Moodle Rest API object
    		$MoodleRest 	= new MoodleRest();
    		// read in base url of sritoni server from settings and append the webservice extesion to it
    		$sritoni_url	= get_blog_option( $blog_id, "sritoni_settings")["sritoni_url"] . "/webservice/rest/server.php";
    		//$MoodleRest->setServerAddress("https://hset.in/sritoni/webservice/rest/server.php");
    		$MoodleRest->setServerAddress($sritoni_url);

    		// get sritoni token from specific site's settings: we use hset_epayments which has blog_id of 12.
    		$sritoni_token 	= get_blog_option( $blog_id, "sritoni_settings")["sritoni_token"];
    		$MoodleRest->setToken( $sritoni_token );

    		// Array is default. You can use RETURN_JSON or RETURN_XML too.
    		$MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY);

    		//$MoodleRest->setDebug(); //using this is giving problems with headers altrady sent so commented out
    		// get moodle user details associated with this completed order from SriToni
    		// note that $user_login is same as Moodle user id, in fact derived from it.
    		$parameters 	= array("criteria" => array(array("key" => "id", "value" => $user_login )));

    		// get moodle user satisfying above criteria
    		$moodle_users 	= $MoodleRest->request('core_user_get_users', $parameters, MoodleRest::METHOD_GET);
    		//
    		if ( !( $moodle_users["users"][0] ) )
    		{
    			// failed to communicate effectively to moodle server since no users returned
    			$user_moodle_data->error = true;
    			$user_moodle_data->message = "Couldn't contact SriToni server from Payment Site, please try later or contact Admin";
                $this->user_moodle_data = $user_moodle_data;
                return;
    		}

    		$phone 				  = $moodle_users["users"][0]['phone1'];
    		$sritoni_username	  = $moodle_users["users"][0]['username'];
    		$sritoni_id			  = $moodle_users["users"][0]['idnumber'];
    		$sritoni_institution  = $moodle_users["users"][0]['institution'] ?? 'not set';

    		// get custom fields associative array
    		$custom_fields 		  = $moodle_users["users"][0]["customfields"];

    		//initialize to null, will hold payment gateway's Virtual accounts later on
    		$accounts 			  = null ;

    		// search for index key of our field having shortname as virtualaccounts
    		// process and derive our needed data from custom fields array

    		// initialize flag to false, true indicates that field virtualaccounts was processed
    		$field_va_processed = false;

    		foreach ($custom_fields as $key => $field )
    		{
    			// $field is an array for an individual user profile field with 4 elements in this array
    			switch (true)
    			{
    				case ( $field["shortname"] == "virtualaccounts" ):
    					// set flag that we processed this field
    					$field_va_processed	= true;

    					if ($field["value"])
    					{
    						// we stored accounts as an json encoded string
    						// strip off html and other tags that got added on somehow before json_decode
    						$string_without_tags 	= strip_tags($field["value"]);

    						// decode json encoded string into an associative array
    						$accounts				= json_decode($string_without_tags, true);

    						// this is the key for the VAs field in custom fields in moodle user data
    						$field_va_key			= $key;

    						// check if account data for this site exists in $accounts
    						if ( empty($accounts[$site_name]) 	||
    							 ($accounts[$site_name]['account_number'] == '0000') )
    						{
    							// Virtual account data non-existent in user profile field, set flag
    							$user_moodle_data->va_to_be_created = true;
    							($this->verbose ?  error_log('VA for this site non-existent or invalid account_number') : false);

    						}

    					}
    					else
    					{
    						// Virtual account data non-existent in user profile field, set flag
    						$user_moodle_data->va_to_be_created = true;
    						($this->verbose ?  error_log('VA data non-existent in profile field') : false);
    					}

    				break;


    				case ( $field["shortname"] == "studentcat" ):

    					if ($field["value"])
    					{
    						// strip off html and other tags, change to lower case and trim whitespace at beginning and end.
    						$studentcat 			= trim( strtolower(strip_tags($field["value"])) );
    					}
    					$field_studentcat_key		= $key;  // this is the key for the studentcat field

    				break;

    				case ($field["shortname"] == "class"):

    					if ($field["value"])
    					{
    						// strip off html and other tags, change to lower case and trim whitespace at beginning and end.
    						$student_class 			= trim( strtolower(strip_tags($field["value"])) );
    					}
    					$field_class_key			= $key;  // this is the key for the class field

    				break;

    				case ($field["shortname"] == "fees"):

    					if ($field["value"])
    					{
    						// strip off html and other tags
    						$fees_json_read 	= strip_tags($field["value"]);
    						error_log("fees JSON string read: $fees_json_read");

    						// decode fees JSON  string to array
    						$fees_arr			= json_decode($fees_json_read, true);
    						error_log(print_r($fees_arr, true));
    						// process the fees array to extract current and arrears and add to user_moodle_data
    						$this->process_fees_array($fees_arr, $user_moodle_data);
    					}

    				break;

    			} 	// end of switch statement
    		}		// end of foreach loop processing all of the custom fields

    		// after foreach loop to break further reference
    		unset ($field);

    		// extracted data from all fields relevant to us.
    		if (!$field_va_processed)
    		{
    			// so we have not encountered this field means that VA doesnt exist need to create
    			$user_moodle_data->va_to_be_created = true;
    			($this->verbose ?  error_log('didnt encounter profile field virtualaccounts so set flag to create VA: '
    			                        . $user_moodle_data->va_to_be_created) : false);
    		}

    		// add the data to the user object we created so we can return it later
    		$user_moodle_data->student_class	= $student_class;
    		$user_moodle_data->studentcat		= $studentcat;
    		// accounts data extracted from Moodle. This may be empty or default data.
    		$user_moodle_data->accounts			= $accounts;
    		$user_moodle_data->sritoni_username	= $sritoni_username;
    		$user_moodle_data->sritoni_id		= $sritoni_id;
    		$user_moodle_data->sritoni_institution		= $sritoni_institution;

    		// check if user is to be permitted to site based on group, id, studentcat, etc.
    		switch (true)
    		{
    			// if user is in white list then no checks, also no creating new VA's so set flag to false
    			case (in_array($sritoni_id, $whitelist_idnumbers)) :
    				$user_moodle_data->error = false;
    				$user_moodle_data->va_to_be_created = false;
    			break;

    			// student has valid category, accept with no errors
    			case ( in_array($studentcat, $studentcat_possible) ) :
    				$user_moodle_data->error = false;
    			break;

    			// user doesn't have valid studentcat so reject with error message
    			case ( !in_array($studentcat, $studentcat_possible) ) :
    				$user_moodle_data->error = true;
    				$user_moodle_data->message = "Your student category: " . $studentcat . " is not valid, you cannot access This site";
                    $this->user_moodle_data = $user_moodle_data;
                    return;
    		}

    		// extract course ID needed to query Moodle for groups in that course later on
    		$courseid		= $this->resource_link->lti_context_id;

    		// based on couserid value assign grouingid. We will use this to get groups in a grouping in a course later
    		$groupingid		= $courseid_groupingid_arr[$courseid];

    		// Extract this user's Groups, from courseid = 116, groupingid=24 for example
    		$parameters 	= 	array(
    								"courseid"		=> $courseid,
    								"userid"		=> $user_login,
    								"groupingid"	=> $groupingid,
    							 );
    		$user_groups 	= $MoodleRest->request('core_group_get_course_user_groups', $parameters, MoodleRest::METHOD_GET);


    		$groupname		= $user_groups["groups"][0]["name"] ?? "group not set";
    		// this should correspond to categories of products to be shown to user
    		$user_moodle_data->groupname	= $groupname;

    		if ( !in_array($sritoni_id, $whitelist_idnumbers))	// do this check only for non-whitelsited users
    		{	// check that user's group is in permissible list
    			if (in_array($grade_for_current_fees, $group_possible) ||  in_array($groupname, $group_possible))
    			{
    				$user_moodle_data->error = false;
    			}
    			else
    			{
    				$user_moodle_data->error = true;
    				$user_moodle_data->message = "Your grade to make payment for: " . $grade_for_current_fees .
    							" or current grade: " . $groupname . " is not in permitted list, inform admin";
                    $this->user_moodle_data = $user_moodle_data;
                    return;
    			}
    		}

    		if ($this->verbose)
    		{
    			error_log('SriToni course ID : ' . 					$courseid			);
    			error_log('Sritoni user name : ' . 					$sritoni_username	);
    			error_log('Sritoni ID Number : ' . 					$sritoni_id			);
    			error_log('Sritoni Student Category : ' . 			$studentcat			);
    			error_log('Sritoni institution : ' . 				$sritoni_institution);
    			error_log('Sritoni Student Class : ' . 				$student_class		);
    			error_log('Sritoni present Group : ' . 				$groupname			);
    			error_log('Sritoni User Phone : ' . 				$phone				);
    			error_log('SriToni Virtual Account details : '							);
    			error_log(print_r($accounts[$site_name],	true));
    			error_log('VA create Flag Value : ' . 				$user_moodle_data->va_to_be_created);
    			error_log('Current fees due amount : ' . 			$current_fees);
    			error_log('Grade for which Current fees due : ' . 	$grade_for_current_fees);
    			error_log('Arrears fees due amount : ' . 			$arrears_amount);
    		}

    		$this->user_moodle_data = $user_moodle_data;
            return;
    }  // end of function getFilteredMoodleUserData

    /**
    * Update user meta only for payment sites. Account information needs info of specific payment site
    * you should come here only for payment sites
    */
    function updateUserMeta()
    {
        // extract wordpress user ID for shorter reference
        $user_id            = $this->user_id;

        // extract user_moodle_data for shorter reference
        $user_moodle_data   = $this->user_moodle_data;

        // extract site_name
        $site_name          = $this->site_name;

        // extract roles of user
        $roles              = $this->roles;

    	update_user_meta( $user_id, 'nickname', 				$user_moodle_data->sritoni_id);
    	update_user_meta( $user_id, 'sritoni_username', 		$user_moodle_data->sritoni_username);
    	update_user_meta( $user_id, 'sritoni_institution', 		$user_moodle_data->sritoni_institution);

    	update_user_meta( $user_id, 'grade_for_current_fees',	$user_moodle_data->grade_for_current_fees);
    	update_user_meta( $user_id, 'current_fees',				$user_moodle_data->current_fees);
    	update_user_meta( $user_id, 'current_fee_description',	$user_moodle_data->current_fee_description);

    	update_user_meta( $user_id, 'arrears_amount',			$user_moodle_data->arrears_amount);
    	update_user_meta( $user_id, 'arrears_description',		$user_moodle_data->arrears_description);


    	if ( 	!(in_array( 'administrator', $roles )) &&
    					!(in_array( 'shop_manager',  $roles ))		)
    						{
    							// don't change meta for administrator and shop manager, but for all others
    							update_user_meta( $user_id, 'sritoni_student_category', $user_moodle_data->studentcat);
    							update_user_meta( $user_id, 'grade_or_class', $user_moodle_data->groupname);
    						}

    	$va_id 				= $user_moodle_data->accounts[$site_name]["va_id"] ?? "Account Not Created Yet";
    	$account_number		= $user_moodle_data->accounts[$site_name]["account_number"] ?? "0000";
    	$va_ifsc_code		= $user_moodle_data->accounts[$site_name]["va_ifsc_code"] ?? "Account Not Created Yet";
    	$beneficiary_name 	= $user_moodle_data->accounts[$site_name]["beneficiary_name"] ?? "Account Not Created Yet";

    	update_user_meta( $user_id, 'va_id', 			$va_id );
    	update_user_meta( $user_id, 'beneficiary_name', $beneficiary_name );
    	update_user_meta( $user_id, 'va_ifsc_code', 	$va_ifsc_code );
    	update_user_meta( $user_id, 'account_number', 	$account_number );


    	// if verbose print out the confirmation of updated user meta
    	if ($this->verbose)
    	{
    		// get user meta for this user and print them out
    		error_log('User meta read out in function updateusermeta: ');
    		error_log('Virtual Account ID : ' 	. 			get_user_meta( $user_id, 'va_id' , 				true )	);
    		error_log('VA Account Number : '  	. 			get_user_meta( $user_id, 'account_number' , 	true )	);
    		error_log('VA Beneficiary Name : '  . 			get_user_meta( $user_id, 'beneficiary_name' , 	true )	);
    		error_log('VA IFSC Code : ' 		. 			get_user_meta( $user_id, 'va_ifsc_code' , 		true )	);
    		error_log('current_fee_description : ' 		. 	get_user_meta( $user_id, 'current_fee_description' , 		true )	);
    		error_log('arrears_description' 		. 		get_user_meta( $user_id, 'arrears_description' , true )	);

    	}

    	return;
    } // end of function updateUserMeta


}   // end of class definition

?>
