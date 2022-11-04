<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wordpresslti_database.php';
require_once file_exists(__DIR__ . '/../../../wp-config.php') ? __DIR__ . '/../../../wp-config.php' : __DIR__ . '/../../../../wp-config.php';
require_once __DIR__ . '/../blogType/blogTypeLoader.php';
require_once __DIR__ . '/../filters.php';
require_once __DIR__ . '/lib.php';
require_once ABSPATH . '/wp-admin/includes/plugin.php';
require_once ABSPATH . '/wp-admin/includes/bookmark.php';
require_once ABSPATH . '/wp-settings.php';

use \IMSGlobal\LTI;

$launch = LTI\LTI_Message_Launch::new(new WordPressLTI_Database())
    ->validate();

if ($launch->is_deep_link_launch()) {
    // TODO prepare Deeplink flow
}
$client_id = $launch->get_launch_data()['aud'];

LTIUtils::lti13_check_nonce($client_id, $launch->get_launch_data()['nonce']);
parse_launch_lti_13($client_id, $launch);


function parse_launch_lti_13($client_id, LTI\LTI_Message_Launch $launch)
{
    try {

        //deactivate_plugins( $plugin, true );

        $lti_data = $launch->get_launch_data();
        // $issuer_id = $lti_data['iss'];
        // $deployment_id = $lti_data['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] ?? '';
        $lti_user_id = $lti_data['sub'];
        $custom_params = $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'] ?? [];
        $blogType = new blogTypeLoader(isset($custom_params['blogtype']) ? $custom_params['blogtype'] : 'defaultType');

        if ($blogType->error < 0) {

            wp_die("LTI loading Types Aula Failed " . $blogType->error_miss);
            return;
        }
        if ($blogType->requires_user_authorized() && !$blogType->isAuthorizedUserInCourse($lti_data['https://purl.imsglobal.org/spec/lti/claim/roles'])) {
            wp_die("You are not authorized to access");
            return;
        }

        $overwrite_roles = isset($lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_ROLES]) ? $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_ROLES] : false;

        // Set up the user...
        $userkey = LTIUtils::getUserkeyLTI($client_id, $lti_user_id, $custom_params);

        if (empty($userkey)) {
            wp_die(__('<p>Empty username</p><p>Cannot create a user without username</p>',
                'wordpress-mu-ltiadvantage'));
        }

        $uinfo = get_user_by('login', $userkey);

        $created_user = false;
        $given_name = apply_filters('lti_get_given_name', $lti_data['given_name'] ?? '', $userkey);
        $family_name = apply_filters('lti_get_family_name', $lti_data['family_name'] ?? '', $userkey);
        $email = apply_filters('lti_get_email', $lti_data['email'] ?? '', $userkey);
        $name = apply_filters('lti_get_name', $lti_data['name'] ?? '', $userkey);

        if (empty($email)) {
            wp_die(__('<p>Empty email</p><p>Cannot create a user without email</p>', 'wordpress-mu-ltiadvantage'));
        }

        $user_data = [
            'user_login' => $userkey,
            'user_nicename' => $name,
            'first_name' => $given_name,
            'last_name' => $family_name,
            'user_email' => $email,
            'display_name' => $name
        ];
        if (isset($uinfo) && $uinfo != false) {
            $user_data['ID'] = $uinfo->ID;
            if (is_multisite()) {
                $user_data['role'] = get_option('default_role');
            }
            $ret_id = wp_insert_user($user_data);
            if (is_wp_error($ret_id)) {
                wp_die('<p>' . $ret_id->get_error_message() . '</p>',
                    __('User updating Failure', 'wordpress-mu-ltiadvantage'));
            }
        } else { // new user!!!!
            $user_data['user_pass'] = wp_generate_password(10, true, true);
            $ret_id = wp_insert_user($user_data);
            if (is_wp_error($ret_id)) {
                wp_die('<p>' . $ret_id->get_error_message() . '</p>',
                    __('User updating Failure', 'wordpress-mu-ltiadvantage'));
            }
            $uinfo = get_user_by('login', $userkey);
            $created_user = true;
        }

        update_user_meta($uinfo->ID, LTIAdvantageManagement::$LTI_METAKEY_USER_ID, $lti_user_id);

        $user = new WP_User($uinfo->ID);
        $_SERVER['REMOTE_USER'] = $userkey;
        $password = md5($uinfo->user_pass);


        $blog_created = false;
        $overwrite_plugins_theme = isset($lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_PLUGINS_THEME]) ? $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_PLUGINS_THEME] : false;

        $blog_is_new = false;
        $blog_id = 0;
        $domain = '';
        if (is_multisite()) {

            // User is now authorized; force WordPress to use the generated password
            //login, set cookies, and set current user
            $current_site = get_current_site();
            $domain = $current_site->domain;
            $subject_code = sanitize_user($blogType->getCoursePath($lti_data, $domain), true);
            $subject_code = str_replace('_', '-', $subject_code);

            if (is_subdomain_install()) {
                $domain = $subject_code . '.' . $domain;
                $path = '/';
            } else {
                $path = $current_site->path . $subject_code . '/';
            }
            $blog_id = domain_exists($domain, $path);
            if (!isset($blog_id)) {
                $title = $blogType->getCourseName($lti_data);
                $blog_is_new = true;

                $meta = $blogType->getMetaBlog($lti_data);
                $old_site_language = get_site_option('WPLANG');
                $blogType->setLanguage();
                update_site_option('WPLANG', $blogType->getLanguage());
                $blog_id = wpmu_create_blog($domain, $path, $title, $uinfo->ID, $meta);

                $blogType->checkErrorCreatingBlog($blog_id, $path);
                switch_to_blog($blog_id);

                update_site_option('WPLANG', $old_site_language);

                $blog_created = true;
            }
        } else {
            $blog_id = get_current_blog_id();
        }
        update_option('lti_clientid', $client_id);
        update_option('lti_issuer', $lti_data['iss']);
        $deployment_id = $lti_data['https://purl.imsglobal.org/spec/lti/claim/deployment_id'];
        $custom_params = $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'];
        update_option('lti_deployment_id', $deployment_id);
        update_option('lti_custom_params', $custom_params);
        // Connect the user to the blog
        if (isset($blog_id)) {

            if (is_multisite()) {
                switch_to_blog($blog_id);
            }

            if ($overwrite_plugins_theme || $blog_created) {
                $blogType->loadPlugins();
                $blogType->changeTheme();
            }
            //Agafem el rol anterior
            $old_role = null;
            if (!$created_user && !$blog_created && !$overwrite_roles) {
                $old_role = LTIUtils::get_current_user_role($uinfo->ID);
            }
            $obj = new stdClass();
            $obj->blog_id = $blog_id;
            $obj->userkey = $userkey;
            $obj->domain = $domain;
            $obj->context = $lti_data;
            $obj->uinfoID = $uinfo->ID;
            $obj->blog_is_new = $blog_is_new;
            if ($overwrite_roles || ($old_role == null) || $old_role == false) {
                $obj->role = get_lti_13_role($client_id, $lti_data, $blogType);
                if (is_multisite()) {
                    add_user_to_blog($blog_id, $uinfo->ID, $obj->role);
                } else {
                    wp_update_user(array('ID' => $uinfo->ID, 'role' => $obj->role));
                }

            } else {
                $obj->role = $old_role;
            }
            $blogType->postActions($obj);
        }
    } catch (Exception $e) {
        error_log("Error exception " . $e->getMessage());
    } finally {
        //error_reporting(E_ALL);
        //error_log("activate_plugin $plugin");
        //activate_plugin( array_pop($plugin), '', false, true );
    }

    $credentials = array(
        'user_login' => $userkey,
        'user_password' => $password,
        'remember' => true
    );
    wp_signon($credentials);
    wp_set_auth_cookie($user->ID, true);
    wp_set_current_user($user->ID, $userkey);
    do_action('uoc_create_site_user_login', $user);

    add_user_meta($user->ID, 'lti_launch_' . $blog_id, $launch);


    if ($redirecturl = $blogType->force_redirect_to_url( $custom_params )) {
        wp_redirect($redirecturl);
        exit();
    }
    wp_redirect(get_home_url($blog_id));
    exit();

    /**    $redirecturl = get_option("siteurl");
     * if (!endsWith('/', $redirecturl)) {
     * $redirecturl.='/';
     * }
     * wp_redirect($redirecturl);
     * exit();*/
}

function get_lti_13_role($client_id, $lti_data, $blogType) {

    $lti_conf = LTIUtils::lti_get_by_client_id($client_id);
    $student_role = false;
    if (isset($lti_conf)) {
        $student_role = $lti_conf->student_role;
    }
    $role = $blogType->roleMapping($lti_data['https://purl.imsglobal.org/spec/lti/claim/roles'], $student_role);
    return $role;
}