<?php
/**
 * Actions & filters relating to user authentication.
 */

/**
 * Filter the login redirect to send users to the frontend rather than the dashboard.
 *
 * @param string $location
 * @return string Modified url
 */
function hcommons_remove_admin_redirect( $location ) {
	remove_filter( 'login_redirect', 'buddyboss_redirect_previous_page', 10, 3 );

	if ( false !== strpos( $location, 'wp-admin' ) ) {
		$location = get_site_url();
	}

	return $location;
}
// priority 5 to run before buddyboss_redirect_previous_page
add_filter( 'login_redirect', 'hcommons_remove_admin_redirect', 5 );
// TODO is wp_safe_redirect_fallback still necessary?
//add_filter( 'wp_safe_redirect_fallback', array( $this, 'hcommons_remove_admin_redirect' ) );






function hcommons_set_user_member_types( $user ) {

	$user_id = $user->ID;

	$shib_session_id = get_user_meta( $user_id, 'shib_session_id', true );
/*
		if ( $shib_session_id == Humanities_Commons::$shib_session_id ) {
			hcommons_write_error_log( 'info', '****SET_USER_MEMBER_TYPES_OUT****-' . var_export( $shib_session_id, true ) );
			return;
		}
 */
	$memberships = $this->hcommons_get_user_memberships();
	hcommons_write_error_log( 'info', '****RETURNED_MEMBERSHIPS****-' . $_SERVER['HTTP_HOST'] . '-' . var_export( $user->user_login, true ) . '-' . var_export( $memberships, true ) );
	$member_societies = (array) bp_get_member_type( $user_id, false );
	hcommons_write_error_log( 'info', '****PRE_SET_USER_MEMBER_TYPES****-' . var_export( $member_societies, true ) );
	$result = bp_set_member_type( $user_id, '' ); // Clear existing types, if any.
	$append = true;
	foreach( $memberships['societies'] as $member_type ) {
		$result = bp_set_member_type( $user_id, $member_type, $append );
		hcommons_write_error_log( 'info', '****SET_EACH_MEMBER_TYPE****-' . $user_id . '-' . $member_type . '-' . var_export( $result, true ) );
	}

	//If site is a society we are mapping groups for and the user is member of the society, map any groups from comanage to wp.
	//TODO add logic to remove groups the user is no longer a member of
	if ( in_array( Humanities_Commons::$society_id, array( 'ajs', 'aseees', 'caa', 'mla', 'up' ) ) &&
		in_array( Humanities_Commons::$society_id, $memberships['societies'] ) ) {
		foreach( $memberships['groups'][Humanities_Commons::$society_id] as $group_name ) {
			$group_id = $this->hcommons_lookup_society_group_id( Humanities_Commons::$society_id, $group_name );
			if ( ! groups_is_user_member( $user_id, $group_id ) ) {
				$success = groups_join_group( $group_id, $user_id );
				hcommons_write_error_log( 'info', '****ADD_GROUP_MEMBERSHIP***-' . $group_id . '-' . $user_id );
			}
		}
	}

}
add_action( 'shibboleth_set_user_roles', array( $this, 'hcommons_set_user_member_types' ) );

function hcommons_maybe_set_user_role_for_site( $user ) {

	//TODO Can we find WP functions that avoid messing directly with usermeta for a user that has not yet signed in?
	global $wpdb;
	$prefix = $wpdb->get_blog_prefix();
	$user_id = $user->ID;
	$site_caps = get_user_meta( $user_id, $prefix . 'capabilities', true );
	$site_caps_array = maybe_unserialize( $site_caps );
	$memberships = $this->hcommons_get_user_memberships();
	$is_site_member = in_array( Humanities_Commons::$society_id, $memberships['societies'] );

	if ( $is_site_member ) {
		//TODO Copy role check logic from hcommons_check_user_site_membership().
		$site_role_found = false;
		foreach( $site_caps_array as $key=>$value ) {
			if ( in_array( $key, array( 'subscriber', 'contributor', 'author', 'editor', 'administrator' ) ) ) {
				$site_role_found = true;
				break;
			}
		}
		if ( $is_site_member && ! $site_role_found ) {
			$site_caps_array['subscriber'] = true;
			$site_caps_updated = maybe_serialize( $site_caps_array );
			$result = update_user_meta( $user_id, $prefix . 'capabilities', $site_caps_updated );
			$user->init_caps();
			hcommons_write_error_log( 'info', '****MAYBE_SET_USER_ROLE_FOR_SITE***-'.var_export( $result, true ).'-'.var_export( $is_site_member, true ).'-'.var_export( $site_caps_updated, true ).'-'.var_export( $prefix, true ).'-'.var_export( $user_id, true ) );
		}
	} else {
		if ( ! empty( $site_caps ) ) {
			delete_user_meta( $user_id, $prefix . 'capabilities' );
			delete_user_meta( $user_id, $prefix . 'user_level' );
		}
	}
}
add_action( 'shibboleth_set_user_roles', array( $this, 'hcommons_maybe_set_user_role_for_site' ) );

/**
 * Capture shibboleth data in user meta once per shibboleth session
 *
 * @since HCommons
 *
 * @param object $user
 */
function hcommons_set_shibboleth_based_user_meta( $user ) {

	$user_id = $user->ID;
	$shib_session_id = get_user_meta( $user_id, 'shib_session_id', true );

	if ( $shib_session_id == Humanities_Commons::$shib_session_id ) {
		return;
	}

	hcommons_write_error_log( 'info', '****SHIB_BASED_USER_META****-' . var_export( Humanities_Commons::$shib_session_id, true ) );
	$login_host = $_SERVER['HTTP_X_FORWARDED_HOST'];
	$result = update_user_meta( $user_id, 'shib_session_id', Humanities_Commons::$shib_session_id );
	$result = update_user_meta( $user_id, 'shib_login_host', $login_host );

	$shib_orcid = $_SERVER['HTTP_EDUPERSONORCID'];
	if ( ! empty( $shib_orcid ) ) {
		if ( false === strpos( $shib_orcid, ';' ) ) {
			$shib_orcid_updated = str_replace( array( 'https://orcid.org/', 'http://orcid.org/' ), '', $shib_orcid );
			$result = update_user_meta( $user_id, 'shib_orcid', $shib_orcid_updated );
		} else {
			$shib_orcid_updated = array();
			$shib_orcids = explode( ';', $shib_orcid );
			foreach( $shib_orcids as $each_orcid ) {
				if ( ! empty( $each_orcid ) ) {
					$shib_orcid_updated[] = str_replace( array( 'https://orcid.org/', 'http://orcid.org/' ), '', $each_orcid );
				}
			}
			$result = update_user_meta( $user_id, 'shib_orcid', $shib_orcid_updated[0] );
		}
	}

	$shib_org = $_SERVER['HTTP_O'];
	if ( false === strpos( $shib_org, ';' ) ) {
		$shib_org_updated = $shib_org;
		if ( 'Humanities Commons' === $shib_org_updated ) {
			$shib_org_updated = '';
		}
	} else {
		$shib_org_updated = array();
		$shib_orgs = explode( ';', $shib_org );
		foreach( $shib_orgs as $shib_org ) {
			if ( 'Humanities Commons' !== $shib_org && ! empty( $shib_org ) ) {
				$shib_org_updated[] = $shib_org;
			}
		}
	}
	$result = update_user_meta( $user_id, 'shib_org', maybe_serialize( $shib_org_updated ) );

	$shib_title = $_SERVER['HTTP_TITLE'];
	if ( false === strpos( $shib_title, ';' ) ) {
		$shib_title_updated = $shib_title;
	} else {
		$shib_title_updated = explode( ';', $shib_title );
	}
	$result = update_user_meta( $user_id, 'shib_title', maybe_serialize( $shib_title_updated ) );

	$shib_uid = $_SERVER['HTTP_UID'];
	if ( false === strpos( $shib_uid, ';' ) ) {
		$shib_uid_updated = $shib_uid;
	} else {
		$shib_uid_updated = explode( ';', $shib_uid );
	}
	$result = update_user_meta( $user_id, 'shib_uid', maybe_serialize( $shib_uid_updated ) );

	$shib_ismemberof = $_SERVER['HTTP_ISMEMBEROF'];
	if ( false === strpos( $shib_ismemberof, ';' ) ) {
		$shib_ismemberof_updated = $shib_ismemberof;
	} else {
		$shib_ismemberof_updated = explode( ';', $shib_ismemberof );
	}
	$result = update_user_meta( $user_id, 'shib_ismemberof', maybe_serialize( $shib_ismemberof_updated ) );

	$shib_email = $_SERVER['HTTP_MAIL'];
	if ( false === strpos( $shib_email, ';' ) ) {
		$shib_email_updated = $shib_email;
	} else {
		$shib_email_updated = explode( ';', $shib_email );
	}
	$result = update_user_meta( $user_id, 'shib_email', maybe_serialize( $shib_email_updated ) );

	$shib_identity_provider = $_SERVER['HTTP_SHIB_IDENTITY_PROVIDER'];
	if ( false === strpos( $shib_identity_provider, ';' ) ) {
		$shib_identity_provider_updated = $shib_identity_provider;
	} else {
		$shib_identity_provider_updated = explode( ';', $shib_identity_provider );
	}
	$result = update_user_meta( $user_id, 'shib_identity_provider', maybe_serialize( $shib_identity_provider_updated ) );
}
add_action( 'shibboleth_set_user_roles', array( $this, 'hcommons_set_shibboleth_based_user_meta' ) );

/**
 * ensure invite-anyone correctly sets up notifications after user registers
 */
function hcommons_invite_anyone_activate_user( $user ) {
	$meta_key = 'hcommons_invite_anyone_activate_user_done';

	if (
		! empty( $user->user_email ) &&
		! get_user_meta( $user->ID, $meta_key ) &&
		function_exists( 'invite_anyone_activate_user' )
	) {
		invite_anyone_activate_user( $user->ID, null, null );
		update_user_meta( $user->ID, $meta_key, true );
	}
}
add_action( 'shibboleth_set_user_roles', array( $this, 'hcommons_invite_anyone_activate_user' ) );

/**
 * Syncs the HCommons managed WordPress profile data to HCommons XProfile Group fields.
 *
 * @since HCommons
 *
 * @param object $user   User object whose profile is being synced. Passed by reference.
 */
function hcommons_sync_bp_profile( $user ) {

	$user_id = $user->ID;

	$shib_session_id = get_user_meta( $user_id, 'shib_session_id', true );
/*
		if ( $shib_session_id == Humanities_Commons::$shib_session_id ) {
			hcommons_write_error_log( 'info', '****SYNC_BP_PROFILE_OUT****-' . var_export( $shib_session_id, true ) );
			return;
		}
 */
	hcommons_write_error_log( 'info', '****SYNC_BP_PROFILE****-'.var_export( $user->ID, true ) );

	$current_name = xprofile_get_field_data( 'Name', $user->ID );
	if ( empty( $current_name ) ) {
		$name = $_SERVER['HTTP_DISPLAYNAME']; // user record maybe not fully populated for first time users.
		if ( ! empty( $name ) ) {
			xprofile_set_field_data( 'Name', $user->ID, $name );
		}
	}

	$current_title = xprofile_get_field_data( 'Title', $user->ID );
	if ( empty( $current_title ) ) {
		$titles = maybe_unserialize( get_user_meta( $user->ID, 'shib_title', true ) );
		if ( is_array( $titles ) ) {
			$title = $titles[0];
		} else {
			$title = $titles;
		}
		if ( ! empty( $title ) ) {
			xprofile_set_field_data( 'Title', $user->ID, $title );
		}
	}

	$current_org = xprofile_get_field_data( 'Institutional or Other Affiliation', $user->ID );
	if ( empty( $current_org ) ) {
		$orgs = maybe_unserialize( get_user_meta( $user->ID, 'shib_org', true ) );
		if ( is_array( $orgs ) ) {
			$org = $orgs[0];
		} else {
			$org = $orgs;
		}
		if ( ! empty( $org ) ) {
			xprofile_set_field_data( 'Institutional or Other Affiliation', $user->ID, str_replace( 'Mla', 'MLA', $org ) );
		}
	}

	$current_orcid = xprofile_get_field_data( 18, $user->ID );
	if ( empty( $current_orcid ) ) {
		$orcid = get_user_meta( $user->ID, 'shib_orcid', true );
		if ( ! empty( $orcid ) ) {
			xprofile_set_field_data( 18, $user->ID, $orcid );
		}
	}

}
add_action( 'shibboleth_set_user_roles', array( $this, 'hcommons_sync_bp_profile' ) );

/**
 * Return first email if multiple provided in shibboleth session.
 *
 * @since HCommons
 *
 * @param string $shib_email
 * @return string $shib_email_array[0]
 */
function hcommons_set_shibboleth_based_user_email( $shib_email ) {

	$shib_email_array = explode( ';', $shib_email );
	return $shib_email_array[0];

}
add_filter( 'shibboleth_user_email', array( $this, 'hcommons_set_shibboleth_based_user_email' ) );

/**
 * Check the user's membership to this network prior to login and if valid return the role.
 *
 * @since HCommons
 *
 * @param string $user_role
 * @return string $user_role Role or null.
 */
function hcommons_check_user_site_membership( $user_role ) {

	$username = $_SERVER['HTTP_EMPLOYEENUMBER'];

	$user = get_user_by( 'login', $username );
	$user_id = $user->ID;
	$global_super_admins = array();
	if ( defined( 'GLOBAL_SUPER_ADMINS' ) ) {
		$global_super_admin_list = constant( 'GLOBAL_SUPER_ADMINS' );
		$global_super_admins = explode( ',', $global_super_admin_list );
	}
	$memberships = $this->hcommons_get_user_memberships();
	$member_societies = (array)$memberships['societies'];
	if ( ! in_array( Humanities_Commons::$society_id, $member_societies ) && ! in_array( $user->user_login, $global_super_admins ) ) {
		hcommons_write_error_log( 'info', '****CHECK_USER_SITE_MEMBERSHIP_FAIL****-' . var_export( $memberships['societies'], true ) .
			var_export( Humanities_Commons::$society_id, true ) . var_export( $user, true ) );
		return '';
	}

	//Check for existing user role, we don't want to overwrite role assignments made in WP.
	global $wp_roles;
	$user_role_set = false;
	foreach ( $wp_roles->roles as $role_key=>$role_name ) {
		if ( false === strpos( $role_key, 'bbp_' ) ) {
			$user_role_set = user_can( $user, $role_key );
		}
		if ( $user_role_set ) {
			$user_role = $role_key;
			break;
		}
	}
	hcommons_write_error_log( 'info', '****CHECK_USER_SITE_MEMBERSHIP****-' . var_export( $user_role, true ) . var_export( $user_role_set, true ) . var_export( $user->user_login, true ) );

	return $user_role;

}
add_filter( 'shibboleth_user_role', array( $this, 'hcommons_check_user_site_membership' ) );

/**
 * Handle a failed login attempt. Determine if the user has visitor status.
 *
 * @since HCommons
 *
 * @param string $username   User who is attempting to log in.
 */
function hcommons_login_failed( $username ) {

	global $wpdb;
	$prefix = $wpdb->get_blog_prefix();
	$referrer = $_SERVER['HTTP_REFERER'];
	hcommons_write_error_log( 'info', '****LOGIN_FAILED****-' . $_SERVER['HTTP_REFERER'] . ' ' . $_SERVER['HTTP_X_FORWARDED_FOR'] . ' ' . $_SERVER['HTTP_EMPLOYEENUMBER'] );
	if ( ! empty( $referrer ) && strstr( $referrer, 'idp/profile/SAML2/Redirect/SSO?' ) ) {
		if ( ! strstr( $_SERVER['REQUEST_URI'], '/not-a-member' ) && ! strstr( $_SERVER['REQUEST_URI'], '/inactive-member' ) ) { // one redirect
			wp_redirect( 'https://' . $_SERVER['HTTP_X_FORWARDED_HOST'] . '/not-a-member' );
			exit();
		}
	}
		/* Maybe this can go away for good now
		//
		// Otherwise, we assume we have an active session coming in as a visitor.
		$username = $_SERVER['HTTP_EMPLOYEENUMBER']; //TODO Why is the username parameter empty?
		$user = get_user_by( 'login', $username );
		$user_id = $user->ID;
		$visitor_notice = get_user_meta( $user_id, $prefix . 'commons_visitor', true );
		if ( ( empty( $visitor_notice ) ) && ! strstr( $_SERVER['REQUEST_URI'], '/not-a-member' ) ) {
			hcommons_write_error_log( 'info', '****LOGIN_FAILED_FIRST_TIME_NOTICE****-' . $username . '-' . $_SERVER['HTTP_EPPN'] . '-' .
				$_SERVER['HTTP_X_FORWARDED_HOST'] . '-' . var_export( $prefix, true ) );

			update_user_meta( $user_id, $prefix . 'commons_visitor', 'Y' );
			wp_redirect( 'https://' . $_SERVER['HTTP_X_FORWARDED_HOST'] . '/not-a-member' );
			exit();
		}
		 */

}
add_action( 'wp_login_failed', array( $this, 'hcommons_login_failed' ) );

/**
 * Filter shibboleth_session_active to set class variable
 *
 * @since HCommons
 *
 * @param bool $active
 * @return bool $active
 */
function hcommons_shibboleth_session_active( $active ) {

	if ( $active ) {
		Humanities_Commons::$shib_session_id = $_SERVER['HTTP_SHIB_SESSION_ID'];
	}
	return $active;
}
//add_filter( 'shibboleth_session_active', array( $this, 'hcommons_shibboleth_session_active' ) );

/**
 * Require shibboleth login rather than allowing vanilla wp-login.
 *
 * @since HCommons
 */
function hcommons_login_init() {
	if (
		! isset( $_REQUEST['action'] ) ||
		! in_array( $_REQUEST['action'], [ 'shibboleth', 'logout' ] )
	) {
		$exploded_url = explode( '?', $_SERVER['REQUEST_URI'] );

		parse_str( parse_url( $_SERVER['REQUEST_URI'], PHP_URL_QUERY ), $parsed_query );

		$parsed_query['action'] = 'shibboleth';

		wp_safe_redirect( $exploded_url[0] . '?' . http_build_query( $parsed_query ) );
	}
}
//add_action( 'login_init', array( $this, 'hcommons_login_init' ) );

/**
 * Force logout of current network if shibboleth session has expired.
 * This is intended to make logging out of one network log the user out of all networks,
 * but also serves to deal with shibboleth expiration or other unexpected scenarios.
 */
function hcommons_shibboleth_autologout() {
	if ( is_user_logged_in() && ! shibboleth_session_active() ) {
		$logout_url = shibboleth_get_option('shibboleth_logout_url');
		wp_logout();
		wp_redirect($logout_url);
		exit;
	}
}
//add_action( 'init', array( $this, 'hcommons_shibboleth_autologout' ) );


/**
 * filter shibboleth_login_url & shibboleth_logout_url to always use https
 */
function hcommons_filter_site_option_shibboleth_urls( $value ) {
	$value = str_replace( 'http:', 'https:', $value );
	return $value;
}
