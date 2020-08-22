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
        if (!is_multisite())
        {
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
        if (!empty($this->returnUrl))
        {
            $_SESSION[LTI_SESSION_PREFIX . 'return_url'] = (strpos($this->returnUrl, '?') === false) ? $this->returnUrl . '?' : $this->returnUrl . '&';
            $_SESSION[LTI_SESSION_PREFIX . 'return_name'] = 'Return to VLE';
            if (!empty($this->platform->name))
            {
                $_SESSION[LTI_SESSION_PREFIX . 'return_name'] = 'Return to ' . $this->platform->name;
            }
        }

        // Get what we are using as the username (unique_id-consumer_key, i.e. _21_1-stir.ac.uk)
        $options 		= get_site_option('lti_choices');
        $scope_userid   = lti_get_scope($this->platform->getKey());
        $user_login     = $this->userResult->getID($scope_userid);

        // ------------- begin madhu added ------->
        // instantiate our user data object
        $user_data	     = new stdClass;
        $this->user_data = $user_data;
        // Sanitize username stripping out unsafe characters
    	$user_login 	 = sanitize_user($user_login);

        // write this back into object for use elsewhere. This is the Moodle id in table user
        $this->user_data->user_login = $user_login;

        // run a function to extract intended site name and blog_id
    	$this->getPathforSiteBasedOnTitle();

        // if we cannot find a valid existing site then return with error
        if (empty($this->user_data->blog_id))
    	{
            // couldn't get intended site id, return with error message
    		$this->reason = __('Could not get a valid blog ID from name of launched activity. ' .
    									 'Inform SriToni Admin', 'lti-text');
            $this->ok = false;
            return;
    	}
        // for all payment sites get the Moodle user details to reject users early
        if( preg_match("/\bpayments\b/i", $this->resourceLink->title) )
		{
            // read in the options using get_blog_option
            $this->getMoodleOptions();

            // get user data from Moodle instance using Moodle REST API
            $this->getFilteredMoodleUserData();

			// if there was an error with the Moodle API exit and return to Moodle with error message
			if ($this->user_data->error)
			{
				$this->reason = __($this->user_data->message, 'lti-text');
                $this->ok = false;
                return;
			}

			// check wether user VA needs to be created
			if ($this->user_data->va_to_be_created)
			{
				// update if existing otherwise create new VA
				$this->update_create_VA();
				// check error message for errors in creation of VA
				if (!empty($this->user_data->error_va_create))
				{
					$this->reason = __($user_data->error_va_create, 'lti-text');
                    $this->ok = false;
                    return;
				}
				// No error in creation, Update user data object with newly created VA
				// update user moodle data object to update Moodle user profile as well as WP user meta later on

				// since we might have created or reread VA data lets update the user's profile field
				$this->update_moodle_field_virtualaccounts();
			}
		}

        // ------------- end madhu added section ------------->

        // Sanitize username stripping out unsafe characters
        // $user_login = sanitize_user($user_login); // alreay done line 99

        // Apply the function pre_user_login before saving to the DB.
        $user_login = apply_filters('pre_user_login', $user_login);

        // Check if this username, $user_login, is already defined
        $user = get_user_by('login', $user_login);

        if ($user)
        {
            // If user exists, simply save the current details
            $user->first_name   = $this->userResult->firstname;
            $user->last_name    = $this->userResult->lastname;
            $user->display_name = $this->userResult->fullname;
            $user->user_email   = $this->userResult->email;
            $result = wp_insert_user($user);
        }
        else
        {
            // Create username if user provisioning is on
            $result = wp_insert_user(
                array(
                    'user_login'    => $user_login,
                    'user_pass'     => wp_generate_password(),
                    'user_nicename' => $user_login,
                    'first_name'    => $this->userResult->firstname,
                    'last_name'     => $this->userResult->lastname,
                    'user_email'    => $this->userResult->email,
                    //'user_url' => 'http://',
                    'display_name'  => $this->userResult->fullname
                )
            );
            // Handle any errors by capturing and returning to the platform
            if (is_wp_error($result))
            {
                $this->reason = $result->get_error_message();
                $this->ok = false;
                return;
            }
            else
            {
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
        $key            = $this->resourceLink->getKey();
        $context_id     = $this->context->getId();
        $resource_id    = $this->resourceLink->getId();
        $activity_name 	= $this->resourceLink->title;

        // Create blog
        $use_context = false;
        if (!empty($context_id))
        {
            $use_context = ($this->resourceLink->getSetting('custom_use_context') == 'true') ? true : false;
        }

        if ($use_context)
        {
            // Create new blog, if does not exist. Note this gives one blog per context, the platform supplies a context_id
            // otherwise it creates a blog per resource_id
            $path = $key . '_' . $context_id;
        }
        else
        {
            // Create new blog, if does not exist. Note this gives one blog per resource_id
            $path = $key . $resource_id;
        }

        // ----- section added by Madhu -----?
        $path = $this->site_name;   // will override all the $path stuff above
        $role 	= "subscriber";		// default role for all users
        // ----- end section added by Madhu
        /*
        // Replace any non-allowed characters in WordPress with -
        $path = preg_replace('/[^_0-9a-zA-Z-]+/', '-', $path);

        // Sanity Check: Ensure that path is only _A-Za-z0-9- --- the above should stop this.
        if (preg_match('/[^_0-9a-zA-Z-]+/', $path) == 1)
        {
            $this->reason = __('No Blog has been created as the name contains non-alphanumeric: (_a-zA-Z0-9-) allowed', 'lti-text');
            $this->ok = false;
            return;
        }
        */

        // Get any folder(s) that WordPress might be living in
        $wppath = parse_url(get_option('siteurl'), PHP_URL_PATH);
        $path = $wppath . '/' . trailingslashit($path);

        // Get the id of the blog, we already know it exists
        $blog_id = $this->blog_id;

        /*
        // If Blog does not exist and this is a member of staff and blog provisioning is on, create blog
        if (!$blog_id && $staff)
        {
            $blog_id = wpmu_create_blog(DOMAIN_CURRENT_SITE, $path, $this->resourceLink->title, $user_id, '', '1');
            update_blog_option($blog_id, 'blogdescription', __('Provisioned by LTI', 'lti-text'));
        }
        */
        // Blog will exist by this point unless this user is student/no role.
        if (!$blog_id)
        {
            $this->reason = __('No Blog has been created for this context', 'lti-text');
            $this->ok = false;
            return;
        }
/*
        // Update/create blog name
        update_blog_option($blog_id, 'blogname', $this->resourceLink->title);

        $role = 'subscriber';
        if ($staff)
        {
            $role = 'administrator';
        }
        if ($learner) {
            $role = 'author';
        }
*/
        // Add user to blog and set role
        if (!is_user_member_of_blog($user_id, $blog_id))
        {
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

        // --- begin section added by Madhu -->
        // update user meta for payments account related only
    	if( preg_match("/\bpayments\b/i", $activity_name) )
		{
			$roles = (array) $user->roles;  // this is an array of all roles that this loggedin user has

            // add the roles of user to data object
            $this->roles = $roles;

            // update the user meta with data from data object
            $this->updateUserMeta();
		}
        // --- end section added by Madhu
        /*
        if ($user->has_cap('administrator') && $role != 'administrator')
        {
            $user->add_role($role);
            $user->remove_role('administrator');
        }

        if ($user->has_cap('author') && $role != 'author')
        {
            $user->add_role($role);
            $user->remove_role('author');
        }

        if ($user->has_cap('subscriber') && $role != 'subscriber')
        {
            $user->add_role($role);
            $user->remove_role('subscriber');
        }
        */
        // Send login time to platform if has outcomes service and can handle freetext
        $context = $this->resourceLink;

        if ($context->hasOutcomesService())
        {

            // Presently this is just a demo of the outcome services and updating the menu bar in WordPress
            $outcome = new Outcome();
            $outcome->type = ResourceLink::EXT_TYPE_TEXT;
            $result = $context->doOutcomesService(ResourceLink::EXT_READ, $outcome, $this->userResult);

            // If we have successfully read then update the user metadata
            if ($result)
            {
                update_user_meta($user_id, 'Last Login', $outcome->getValue());
            }

            $outcome->setValue(date('d-F-Y G:i', time()));
            $context->doOutcomesService(ResourceLink::EXT_WRITE, $outcome, $this->userResult);
        }

        // Return URL for re-direction by Tool Provider class
        $this->redirectUrl = get_bloginfo('url');
    }   // end of function onLaunch

    /**
    *  Based on activity name:
    *  the path is extracted and written to $this
    *  The blog_id of intended site is extracted and written to $this
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
        $this->user_data->site_name = $site_name;

    	($this->verbose ?  error_log('extracted site name is: ' . $site_name) : false);

        // Get any folder(s) that WordPress might be living in
    	$wppath = parse_url(get_option('siteurl'), PHP_URL_PATH);
    	$fullpath = $wppath . '/' . trailingslashit($site_name);

    	// Get the id of the blog, if exists. Write this value to the object.
    	$this->user_data->blog_id = domain_exists(DOMAIN_CURRENT_SITE, $fullpath, 1) ?? null;

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
            $user_login = $this->user_data->user_login;
            $blog_id    = $this->user_data->blog_id;
            $site_name  = $this->user_data->site_name;

    		// initialize VA vreation flag to false up front
    		$this->user_data->va_to_be_created = false;


    		$chunks = array_chunk(preg_split('/(-|,)/', $setting_courseid_groupingid), 2);
    		$courseid_groupingid_arr = array_combine(array_column($chunks, 0), array_column($chunks, 1));

    		// extract beneficiary name from object
    		$beneficiary_name	= $this->user_data->beneficiary_name;

    		// prepare the Moodle Rest API object
    		$MoodleRest 	= new MoodleRest();

    		// read in base url of sritoni server from settings and append the webservice extesion to it
    		$sritoni_url	= $this->user_data->sritoni_url;

    		//$MoodleRest->setServerAddress("https://hset.in/sritoni/webservice/rest/server.php");
    		$MoodleRest->setServerAddress($sritoni_url);

    		// get sritoni token from user_data read in from site settings
    		$sritoni_token 	= $this->user_data->sritoni_token;

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
    			$this->user_data->error = true;
    			$this->user_data->message = "Couldn't contact SriToni server from Payment Site, please try later or contact Admin";
                return;
    		}

    		$phone 				  = $moodle_users["users"][0]['phone1'];
    		$sritoni_username	  = $moodle_users["users"][0]['username'];
    		$sritoni_id			  = $moodle_users["users"][0]['idnumber'];
    		$sritoni_institution  = $moodle_users["users"][0]['institution'] ?? 'not set';

    		// get custom fields associative array for this user
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
    							$this->user_data->va_to_be_created = true;
    							($this->verbose ?  error_log('VA for this site non-existent or invalid account_number') : false);

    						}

    					}
    					else
    					{
    						// Virtual account data non-existent in user profile field, set flag
    						$this->user_data->va_to_be_created = true;
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
    						$fees_arr			        = json_decode($fees_json_read, true);

                            $this->user_data->fees_arr = $fees_arr;

    						// process the fees array to extract current and arrears and add to user_moodle_data
    						$this->process_fees_array();
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
    			$this->user_data->va_to_be_created = true;
    			($this->verbose ?  error_log('didnt encounter profile field virtualaccounts so set flag to create VA: '
    			                        . $this->user_data->va_to_be_created) : false);
    		}

    		// add the data to the user object we created so we can return it later
    		$this->user_data->student_class	    = $student_class;
    		$this->user_data->studentcat		= $studentcat;
    		// accounts data extracted from Moodle. This may be empty or default data.
    		$this->user_data->accounts			= $accounts;
    		$this->user_data->sritoni_username	= $sritoni_username;
    		$this->user_data->sritoni_id		= $sritoni_id;
    		$this->user_data->sritoni_institution		= $sritoni_institution;
            // added these for creation of VA later on
    		$this->user_data->phone			    = $phone;
    		$this->user_data->fullname			= $this->userResult->fullname;
    		$this->user_data->email   			= $this->userResult->email;
    		$this->user_data->moodleuserid		= $this->$user_login;

    		// check if user is to be permitted to site based on group, id, studentcat, etc.
    		switch (true)
    		{
    			// if user is in white list then no checks, also no creating new VA's so set flag to false
    			case (in_array($sritoni_id, $this->user_data->whitelist_idnumbers)) :
    				$this->user_data->error             = false;
    				$this->user_data->va_to_be_created  = false;
    			break;

    			// student has valid category, accept with no errors
    			case ( in_array($studentcat, $this->user_data->studentcat_possible) ) :
    				$this->user_data->error             = false;
    			break;

    			// user doesn't have valid studentcat so reject with error message
    			case ( !in_array($studentcat, $this->user_data->studentcat_possible) ) :
    				$this->user_data->error = true;
    				$this->user_data->message = "Your student category: " . $studentcat . " is not valid, you cannot access This site";
                    return;
    		}

    		// extract course ID needed to query Moodle for groups in that course later on
    		$courseid		= $this->resourceLink->lti_context_id;

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
    public function updateUserMeta()
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

    /**
    *
    *
    */
    public function update_create_VA()
    {
    	require_once(__DIR__."/madhu_added_api/cfAutoCollect.inc.php");	// API to acess CashFree

        $site_name          = $this->site_name;
        $user_moodle_data   = $this->user_moodle_data;
        $blog_id            = $this->blog_id;

    	// setup gateway api instance only if sutename is not blank
        if (!empty($site_name))
        {
            try
                {
                  // creates a new API instance, autheticates using ID and secret and generates token
                  // token is valid for only 5 minutes so make sure this API is done by then
                  $pg_api = new CfAutoCollect($site_name, $blog_id);    // create a new API instance
                }
            catch (Exception $e)
                {
                  error_log("Error creating cashfree_api instance for: " . $user_moodle_data->sritoni_username
    			  														 . " " . $e->getMessage());
    			  $user_moodle_data->error_va_create = "Error creating cashfree_api instance for: "
    			  										. $site_name . " " . $e->getMessage();
                  $this->user_moodle_data = $user_moodle_data;
                  return;
                }
        }
    	// API instance created successfully

    	// get user info to pass onto CashFree for new VA if needed
    	$employeenumber 	= $user_moodle_data->sritoni_id;	// this is the unique sritoni idnumber assigned by school
    	$fullname 			= $user_moodle_data->fullname;		// full name in SriToni
    	$email				= $user_moodle_data->email;
    	$phone				= $user_moodle_data->phone;			// main phone as in SriToni
    	$moodleusername 	= $user_moodle_data->sritoni_username; // sritoni username issued by school
    	$beneficiary_name 	= $user_moodle_data->beneficiary_name; // beneficiary name for this site account
    	$moodleuserid		= $user_moodle_data->moodleuserid;

    	if (strlen($phone) !=10)
    	{
    		$phone  = "1234567890";     // phone dummy number
    	}

    	// pad moodleuserid with 0's to get vAccountId
    	$vAccountId = str_pad($moodleuserid, 4, "0", STR_PAD_LEFT);

    	// does this user's VA exist?
    	try
    	{
    		$vA =  $pg_api->getvAccountGivenId($vAccountId);
    	}
    	catch (Exception $e)
    	{
    		error_log("Error while checking for VA, CashFree API" . " " . $e->getMessage());
    		$error_va_create = "Error while checking for VA with CashFree for " . $moodleusername . " " . $e->getMessage();
    		$user_moodle_data->error_va_create = $error_va_create;
            $this->user_moodle_data = $user_moodle_data;
    		return;
    	}
    	// check using API successfull
    	if ($vA)
    	{
    		// This account exists so lets populate the array to be returned
    		error_log("VA exists but Moodle user field doesnt contain this: "
    					. $site_name . " for this username: " . $moodleusername);
    		$account_number         = $vA->virtualAccountNumber;
    		$ifsc                   = $vA->ifsc;

    		$account =    array  (
    								"beneficiary_name"  => $beneficiary_name ,
    								"va_id"             => $vA->vAccountId ,
    								"account_number"    => $account_number ,
    								"va_ifsc_code"      => $ifsc ,
    							  );
    	}
    	else
    	{
    		// Create new VA since it doesn't exist for sure
    		try
    		{
    			$vA 	= $pg_api->createVirtualAccount($vAccountId, $fullname, $phone, $email);
    		}
    		catch (Exception $e)
    		{
    			error_log( $e->getMessage() );
    			$error_va_create = "Error while Creating VA with CashFree " . $site_name . " " . $e->getMessage();
    			$user_moodle_data->error_va_create = $error_va_create;
                $this->user_moodle_data = $user_moodle_data;
    			return;
    		}
    		error_log("VA Doesnt exist, so, created for:" . $site_name . " for username: " . $moodleusername);
    		// successful in creating VA for this site
    		$account_number         = $vA->accountNumber;
    		$ifsc                   = $vA->ifsc;

    		$account =    array	(
    								"beneficiary_name"  => $beneficiary_name ,
    								"va_id"             => $vAccountId ,
    								"account_number"    => $account_number ,
    								"va_ifsc_code"      => $ifsc ,
    							  );
    	}
        // add the newly created account into the data object for use later on
        $user_moodle_data->accounts[$site_name] = $account;
        $this->user_moodle_data                 = $user_moodle_data;
    	return;
    }

    /**
    *
    * This function uses Moodle REST API to update virtualaccounts profile field with JSON encoded data
    */
    function update_moodle_field_virtualaccounts()
    {
    	require_once(__DIR__."/madhu_added_api/MoodleRest.php");	// API to acess Moodle

        // is the ID used in USER tables in Moodle, passed in as $user_login
        $moodle_user_id = $this->user_login;
        $blog_id        = $this->blog_id;
        $accounts       = $this->user_moodle_data->accounts;

    	// prepare the Moodle Rest API object
    	$MoodleRest 		= new MoodleRest();

    	//$MoodleRest->setServerAddress("https://hset.in/sritoni/webservice/rest/server.php");
    	$MoodleRest->setServerAddress($sritoni_url);

    	$MoodleRest->setToken( $sritoni_token );
    	// Array is default. You can use RETURN_JSON or RETURN_XML too.
    	$MoodleRest->setReturnFormat(MoodleRest::RETURN_ARRAY);
    	// JSON encode the accounts array holding VA information newly created for this site
    	$accounts_json = json_encode($accounts);

    	// create the users array in format needed for Moodle RSET API
    	$users = array("users" => array(array(	"id" 			=> $moodle_user_id,
    											"customfields" 	=> array(array(	"type"	=>	"virtualaccounts",
    																			"value"	=>	$accounts_json,
    																		  )
    																    )
    										 )
    								   )
    				  );
    	// now to update the user's profiel field virtualaccounts with latest completed payment
    	$ret = $MoodleRest->request('core_user_update_users', $users, MoodleRest::METHOD_POST);

    	return $ret;;
    }

    /**
    *  The fees_arr is processed to extract current_fees and arrears_amount
    *  The resulting data is written back to user_moodle_data
    */
    public function process_fees_array()
    {
        $fees_arr = $this->fees_arr;

    	// 1st not paid item is current_fees, all other not paid fees are arrears_amount
    	foreach ($fees_arr as $key => $fees)
    	{
    		// check if fees is unpaid and payee belongs to site name for ex: Head Start Educational Trust
    		if ($fees["status"] == "not paid" && $fees["payee"] == $user_moodle_data->beneficiary_name)
    		{
    			// this is unpaid and belongs to this payee matches beneficiary of this site
    			// extract 1st unpaid amount as well as grade to be paid for
    			$current_fees	 			= $fees["amount"];
    			$grade_for_current_fees 	= $fees["fees_for"];

    			// json encode this fee item to store into WP user meta later
    			$current_fee_description = json_encode($fees);

    			// break out of foreach loop we found our 1st not paid fee payment item
    			break;
    		}
    	}
    	// determine arrears if any for the payee of this site based on fees array
    	$arrears_amount = 0;
    	$arrears_arr 	= [];
    	$arrears_description = "";

    	foreach ($fees_arr as $key => $fees)
    	{
    		// we are excluding current fees but including all other unpaid fees as arrears
    		if ($fees["status"] == "not paid" 							&&
    		    $fees["payee"] == $user_moodle_data->beneficiary_name 	&&
    			$fees["fees_for"] != $grade_for_current_fees) // don't include current fees in this
    		{
    			// accumulate the arrears amount for all arrears fee items
    			$arrears_amount += $fees["amount"];
    			// we add the not paid item into the arrears array
    			$arrears_arr[]	= $fees;
    			// form the description for the current fee item to be displayed before add to cart button
    			//$arrears_description .= ($key + 1) . "Unpaid dues: Fee for " .  $fees['fees_for'] . " AY:" . $fees['ay'] . " of "
    			//							       . get_woocommerce_currency_symbol() .  number_format($fees["amount"]) . ", ";
    		}
    	}
    	// enode all unpaid items except for 1st one already taken as current fee item
    	$arrears_description = json_encode($arrears_arr);
    	// we need to update the data object with the processed data
    	// data extracted from fees user field to be passed on to WP user meta
    	$user_moodle_data->current_fees				= $current_fees;
    	$user_moodle_data->grade_for_current_fees	= $grade_for_current_fees;
    	$user_moodle_data->current_fee_description	= $current_fee_description;

    	$user_moodle_data->arrears_amount			= $arrears_amount;
    	$user_moodle_data->arrears_description		= $arrears_description;
    }

    /**
    *
    */
    public function getMoodleOptions()
    {
        $this->user_data->beneficiary_name      = get_blog_option( $blog_id, "sritoni_settings")["beneficiary_name"];

        // read in base url of sritoni server from settings and append the webservice extesion to it
    	$this->user_data->sritoni_url	        = get_blog_option( $blog_id, "sritoni_settings")["sritoni_url"] . "/webservice/rest/server.php";

        // get sritoni token from specific site's settings: we use hset_epayments which has blog_id of 12.
    	$this->user_data->sritoni_token         = get_blog_option( $blog_id, "sritoni_settings")["sritoni_token"];

        // these are the possible values of student category that should be defined in Moodle.
        $this->user_data->studentcat_possible	= explode( "," , get_blog_option($blog_id, 'sritoni_settings')['studentcat_possible'] );

        // array of possible groups. User must be in one of these
        $this->user_data->group_possible		= explode( "," , get_blog_option($blog_id, 'sritoni_settings')['group_possible'] );

        // array of whitelisted users for whom no checks are done
        $this->user_data->whitelist_idnumbers	= explode( "," , get_blog_option($blog_id, 'sritoni_settings')['whitelist_idnumbers'] );

        // get the string of course ID - Grouping ID comma separated list from settings
        $this->user_data->setting_courseid_groupingid = get_blog_option($blog_id, 'sritoni_settings')['courseid_groupingid'];
    }

}   // end of class definition

?>
