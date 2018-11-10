<?php
/*
 * Plugin Name: IMS Basic Learning Tools Interoperability
 * @name Load Blog Type
 * @abstract Processes incoming requests for IMS Basic LTI and apply wordpress with blogType parametrer. This code is developed based on Chuck Severance code
 * @author Chuck Severance
 * @author Antoni Bertran (abertranb@uoc.edu)
 * @copyright 2010-2012 Universitat Oberta de Catalunya
 * @license GPL
 * Date December 2010
 */

require_once(ABSPATH . '/wp-admin/includes/plugin.php');
require_once(ABSPATH . '/wp-admin/includes/bookmark.php');

//require_once dirname(__FILE__).'/IMSBasicLTI/uoc-blti/bltiUocWrapper.php';
require_once dirname(__FILE__) . '/blogType/blogTypeLoader.php';

require_once dirname(__FILE__) . '/lib/util.php';
require_once dirname(__FILE__) . '/lib/jwt/src/BeforeValidException.php';
require_once dirname(__FILE__) . '/lib/jwt/src/ExpiredException.php';
require_once dirname(__FILE__) . '/lib/jwt/src/SignatureInvalidException.php';
require_once dirname(__FILE__) . '/lib/jwt/src/JWT.php';
require_once dirname(__FILE__) . '/lib/jwt/src/JWK.php';

require_once dirname(__FILE__) . '/lib/serviceauth.php';
require_once dirname(__FILE__) . '/lib/lineitem.php';

use \Firebase\JWT\JWT;
use \Firebase\JWT\JWK;

require_once dirname(__FILE__) . '/blogType/Constants.php';
require_once dirname(__FILE__) . '/blogType/utils/UtilsPropertiesWP.php';
/**
 * This function get the secret from db
 * @global type $wpdb
 * @param type $wp
 */
function lti_parse_request($wp)
{
    // Make sure JWT has been passed in the request
    $raw_jwt = $_REQUEST['jwt'] ?: $_REQUEST['id_token'];
    if (!empty($raw_jwt)) {

        // Decode JWT Head and Body
        $jwt_parts = explode('.', $raw_jwt);
        if ($jwt_parts && is_array($jwt_parts)) {
            $jwt_head = json_decode(JWT::urlsafeB64Decode($jwt_parts[0]), true);
            $jwt_body = json_decode(JWT::urlsafeB64Decode($jwt_parts[1]), true);
            // TODO dirty hack
            if (is_array($jwt_head) && $jwt_body['aud'] === "tresipunt") {
                $client_id = is_array($jwt_body['aud']) ? $jwt_body['aud'][0] : $jwt_body['aud'];
                if (!empty($client_id)) {

                    $row = lti_get_by_client_id($client_id);

// Find key set URL to fetch the JWKS
                    $key_set_url = $row->key_set_url;
                    if (empty($key_set_url)) {
                        wp_die(__('The tool is not well configurated, review key set url parameter is empty',
                            'wordpress-mu-ltiadvantage'));
                    }

                    $version = $jwt_body['https://purl.imsglobal.org/spec/lti/claim/version'];
                    if ($version !== "1.3.0") {
                        wp_die(sprintf(__("Version %s is not supported", "wordpress-mu-ltiadvantage"), $version));
                    }

                    $response = get_lti_curl($key_set_url);

// Download key set
                    $public_key_set = json_decode($response, true);

// Find key used to sign the JWT (matches the KID in the header)
                    $public_key = '';
                    $alg = '';
                    foreach ($public_key_set['keys'] as $key) {
                        if ($key['kid'] == $jwt_head['kid'] && $key['alg'] == $jwt_head['alg']) {
                            $public_key = openssl_pkey_get_details(JWK::parseKey($key));
                            $alg = $jwt_head['alg'];
                            break;
                        }
                    }

// Make sure we found the correct key
                    if (empty($public_key_set)) {
                        wp_die("Failed to find KID: " . $jwt_head['kid'] . " in keyset from " . $key_set_url);
                    }

// Validate JWT signature
                    try {
                        JWT::decode($raw_jwt, $public_key['key'], array($alg));
                    } catch (Exception $e) {
                        wp_die($e->getMessage());
                    }


                    lti_check_nonce($client_id, $jwt_body['nonce']);

// Are we a deep linking request?
                    /* Disable at the moment
                    if ($jwt_body['https://purl.imsglobal.org/spec/lti/claim/message_type'] == 'LtiDeepLinkingRequest') {
                        // Go to deep linking setup form
                        include('setupform.php');
                        die;
                    }*/

                    $auth_url = $row->auth_token_url;


                    if (!empty($auth_url)) {
                        $file_key = dirname(__FILE__) . '/keys/tool/toolRS256.key';
                        if (file_exists($file_key)) {
                            $tool_private_key = file_get_contents($file_key);
                            ltidoMembership($jwt_body['iss'], $client_id, $auth_url, $tool_private_key, $jwt_body);
                        }
                    }

                    lti_do_actions($jwt_body, $client_id);
                } else {
                    wp_die("Missing client id, review launch configuration", "wordpress-mu-ltiadvantage");
                }
            }
        }
    }
    return false;
}

function lti_do_actions($jwt_body, $client_id)
{
    // Insert code here to handle incoming connections - use the user
    // and resource_link properties of the $tool_provider parameter
    // to access the current user and resource link.
    // Get consumer key
    //$plugin = array('saml-20-single-sign-on/samlauth.php');
    try {
        //deactivate_plugins( $plugin, true );

        $issuer_id = $jwt_body['iss'];
        $deployment_id = $jwt_body['https://purl.imsglobal.org/spec/lti/claim/deployment_id'];
        $lti_user_id = $jwt_body['sub'];
        $custom_params = $jwt_body['https://purl.imsglobal.org/spec/lti/claim/custom'];
        $blogType = new blogTypeLoader($custom_params['blogtype'] ?: 'defaultType');

        if ($blogType->error < 0) {

            wp_die("LTI loading Types Aula Failed " . $blogType->error_miss);
            return;
        }
        if ($blogType->requires_user_authorized() && !$blogType->isAuthorizedUserInCourse($jwt_body['https://purl.imsglobal.org/spec/lti/claim/roles'])) {
            wp_die("You are not authorized to access");
            return;
        }


        //TODO Get the list of users
        $resource_link = $jwt_body["https://purl.imsglobal.org/spec/lti/claim/resource_link"]['id'];

        // Set up the user...
        $userkey = getUserkeyLTI($client_id, $issuer_id, $deployment_id, $lti_user_id, $custom_params);

        if (empty($userkey)) {
            wp_die(__('<p>Empty username</p><p>Cannot create a user without username</p>',
                'wordpress-mu-ltiadvantage'));
        }

        if (empty($jwt_body['email'])) {
            wp_die(__('<p>Empty email</p><p>Cannot create a user without email</p>', 'wordpress-mu-ltiadvantage'));
        }

        $uinfo = get_user_by('login', $userkey);
        $created_user = false;
        if (isset($uinfo) && $uinfo != false) {
            $update_data = array(
                'ID' => $uinfo->ID,
                'user_login' => $userkey,
                'user_nicename' => $userkey,
                'first_name' => $jwt_body['given_name'],
                'last_name' => $jwt_body['family_name'],
                'user_email' => $jwt_body['email'],
                'display_name' => $jwt_body['name']
            );
            if (is_multisite()) {
                $update_data['role'] = get_option('default_role');
            }
            $ret_id = wp_insert_user($update_data);
            if (is_wp_error($ret_id)) {
                $data = intval($ret_id->get_error_data());
                if (!empty($data)) {
                    wp_die('<p>' . $ret_id->get_error_message() . '</p>',
                        __('User updating Failure', 'wordpress-mu-ltiadvantage'),
                        array('response' => $data, 'back_link' => true));
                } else {
                    exit;
                }
            }
        } else { // new user!!!!
            $ret_id = wp_insert_user(array(
                'user_login' => $userkey,
                'user_nicename' => $userkey,
                'first_name' => $jwt_body['given_name'],
                'last_name' => $jwt_body['family_name'],
                'user_email' => $jwt_body['email'],
                'display_name' => $jwt_body['name'],
                'user_url' => 'http://'
            ));
            if (is_wp_error($ret_id)) {
                $data = intval($ret_id->get_error_data());
                if (!empty($data)) {
                    wp_die('<p>' . $ret_id->get_error_message() . '</p>',
                        __('User creation Failure', 'wordpress-mu-ltiadvantage'),
                        array('response' => $data, 'back_link' => true));
                } else {
                    exit;
                }
            }
            $uinfo = get_user_by('login', $userkey);
            $created_user = true;
        }


        $user = new WP_User($uinfo->ID);
        $_SERVER['REMOTE_USER'] = $userkey;
        $password = md5($uinfo->user_pass);


        // User is now authorized; force WordPress to use the generated password
        //login, set cookies, and set current user
        $current_site = get_current_site();
        $domain = $current_site->domain;
        $subject_code = sanitize_user($blogType->getCoursePath($jwt_body, $domain), true);
        $subject_code = str_replace('_', '-', $subject_code);

        if (is_subdomain_install()) {
            $domain = $subject_code . '.' . $domain;
            $path = '/';
        } else {
            $path = $current_site->path . $subject_code . '/';
        }


        $blog_created = false;
        $overwrite_plugins_theme = $jwt_body['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_PLUGINS_THEME] ?: false;
        $overwrite_roles = $jwt_body['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_ROLES] ?: false;

        $blog_is_new = false;
        if (is_multisite()) {
            $blog_id = domain_exists($domain, $path);
            if (!isset($blog_id)) {
                $title = $blogType->getCourseName($jwt_body);
                $blog_is_new = true;

                $meta = $blogType->getMetaBlog($jwt_body);
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
                if (is_multisite()) {
                    if (!$blog_created && !$overwrite_roles) {
                        $old_role_array = get_user_meta($user->ID, 'wp_' . $blog_id . '_capabilities');
                        if ($old_role_array && count($old_role_array) > 0) {
                            foreach ($old_role_array as $key => $value) {
                                if ($value == true) {
                                    $old_role = $key;
                                }
                            }
                        }
                    }
                    remove_user_from_blog($uinfo->ID, $blog_id);
                } else {
                    $old_role = get_current_user_role($uinfo->ID);
                }
            }
            $obj = new stdClass();
            $obj->blog_id = $blog_id;
            $obj->userkey = $userkey;
            $obj->domain = $domain;
            $obj->context = $jwt_body;
            $obj->uinfoID = $uinfo->ID;
            $obj->blog_is_new = $blog_is_new;
            if ($overwrite_roles || ($old_role == null) || $old_role == false) {
                $obj->role = $blogType->roleMapping($jwt_body['https://purl.imsglobal.org/spec/lti/claim/roles']);
            } else {
                $obj->role = $old_role;
            }
            if (is_multisite()) {
                add_user_to_blog($blog_id, $uinfo->ID, $obj->role);
            } else {
                wp_update_user(array('ID' => $uinfo->ID, 'role' => $obj->role));
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
    if ($redirecturl = $blogType->force_redirect_to_url()) {
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

function endsWith($haystack, $needle)
{
    // search forward starting from end minus needle length characters
    return $needle === "" || (($temp = strlen($haystack) - strlen($needle)) >= 0 && strpos($haystack, $needle,
                $temp) !== false);
}

/**
 * Returns the translated role of the current user. If that user has
 * no role for the current blog, it returns false.
 *
 * @return string The name of the current role
 * */
function get_current_user_role($userId)
{
    $user = new WP_User($userId);
    $roles = $user->roles; //xget_usermeta($userId, 'wp_capabilities');
    $role = array_shift($roles);
    return $role;
}

add_filter('parse_request', 'lti_parse_request', 1);

/**
 *
 * Gets the registered the parameter custom_userkey or standar userkey
 * @param $client_id
 * @param $issuer_id
 * @param $deployment_id
 * @param $lti_user_id
 * @param $custom_params
 * @return mixed|string
 */
function getUserkeyLTI($client_id, $issuer_id, $deployment_id, $lti_user_id, $custom_params)
{
    //Userkey mus have issuer id $jwt_body['iss'] + auid $jwt_body['aud'] + deploymentbody ['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] $jwt_ + subject $jwt_body['sub']
    $userkey = $issuer_id . '-' . $client_id . '-' . $deployment_id . '-' . $lti_user_id;
    $username_param = lti_get_username_parameter_from_client_id($client_id);
    if (isset($username_param) && $username_param && strlen($username_param) > 0) {

        $userkey = $custom_params[$username_param] ?: $userkey;

    }
    $userkey = str_replace(':', '', $userkey);  // TO make it past sanitize_user
    $userkey = str_replace('-', '', $userkey);  // TO make it past sanitize_user
    $userkey = str_replace('_', '', $userkey);  // TO make it past sanitize_user
    $userkey = sanitize_user($userkey, true);
    $userkey = apply_filters('pre_user_login', $userkey);
    $userkey = trim($userkey);

    return $userkey;
}

/**
 *
 * get the parameter to get the username if is needed
 * @return String
 */
function lti_get_username_parameter_from_client_id($client_id)
{
    global $wpdb;
    lti_maybe_create_db();
    $custom_username_parameter = null;
    $row = lti_get_by_client_id($client_id);
    if (isset($row) && $row->has_custom_username_parameter == 1) {
        $custom_username_parameter = $row->custom_username_parameter;
    }
    return $custom_username_parameter;
}

function lti_get_by_client_id($client_id)
{
    global $wpdb;
    lti_maybe_create_db();
    $custom_username_parameter = null;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->ltitable} WHERE client_id = %s",
        $client_id));

    return $row;
}

function lti_client_id_admin()
{
    global $wpdb;
    if (false == lti_site_admin()) {
        return false;
    }

    switch ($_POST['action']) {
        default:
    }
    lti_maybe_create_db();
    $is_editing = false;
    echo '<h2>' . __('LTI: Clients', 'wordpress-mu-ltiadvantage') . '</h2>';
    if (!empty($_POST['action'])) {
        check_admin_referer('lti');
        $client_id = $_POST['client_id'];
        switch ($_POST['action']) {
            case "edit":
                $row = lti_get_by_client_id($client_id);
                if ($row) {
                    lti_edit($row);
                    $is_editing = true;
                } else {
                    ?>
                    <div id="message" class="error fade"><p><strong><?php _e('LTI Tool not found.',
                                    'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                }
                break;
            case "save":
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->ltitable} WHERE client_id = %s",
                    $client_id));
                if ($row) {
                    $wpdb->query($wpdb->prepare("UPDATE {$wpdb->ltitable} SET  key_set_url = %s, auth_token_url = %s, enabled = %d, custom_username_parameter = %s, has_custom_username_parameter = %d  WHERE client_id = %s",
                        $_POST['key_set_url'], $_POST['auth_token_url'], $_POST['enabled'],
                        $_POST['custom_username_parameter'],
                        $_POST['has_custom_username_parameter'], $client_id));
                    ?>
                    <div id="message" class="updated fade"><p><strong><?php _e('LTI Tool updated.',
                                    'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                } else {
                    $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->ltitable} ( `client_id`, `key_set_url`, `auth_token_url`, `enabled`, `custom_username_parameter`, `has_custom_username_parameter`) VALUES ( %s, %s, %s, %d, %s, %d)",
                        $client_id, $_POST['key_set_url'], $_POST['auth_token_url'], $_POST['enabled'],
                        $_POST['custom_username_parameter'],
                        $_POST['has_custom_username_parameter']));
                    ?>
                    <div id="message" class="updated fade"><p><strong><?php _e('LTI Tool added.',
                                    'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                }
                break;
            case "del":
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->ltitable} WHERE client_id = %s", $client_id));
                ?>
                <div id="message" class="updated fade"><p><strong><?php _e('LTI Tool deleted.',
                                'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                break;
        }
    }

    if (!$is_editing) {
        echo "<h3>" . __('Search', 'wordpress-mu-ltiadvantage') . "</h3>";
        $escaped_search = addslashes($_POST['search_txt']);
        $rows = $wpdb->get_results("SELECT * FROM {$wpdb->ltitable} WHERE client_id LIKE '%{$escaped_search}%' ");
        lti_listing($rows,
            empty($escaped_search) ? '' : sprintf(__("Searching for \"%s\"", 'wordpress-mu-ltiadvantage'),
                esc_html($_POST['search_txt'])));
        echo '<form method="POST">';
        wp_nonce_field('lti');
        echo '<input type="hidden" name="action" value="search" />';
        echo '<p>';
        echo _e("Search:", 'wordpress-mu-ltiadvantage');
        echo " <input type='text' name='search_txt' value='" . esc_html($_POST['search_txt']) . "' /></p>";
        echo "<p><input type='submit' class='button-secondary' value='" . __('Search',
                'wordpress-mu-ltiadvantage') . "' /></p>";
        echo "</form><br />";
        lti_edit();
    }
}

function lti_edit($row = false)
{
    $is_new = false;
    if (is_object($row)) {
        echo "<h3>" . __('Edit LTI', 'wordpress-mu-ltiadvantage') . "</h3>";
    } else {
        echo "<h3>" . __('New LTI', 'wordpress-mu-ltiadvantage') . "</h3>";
        $row = new stdClass();
        $row->client_id = '';
        $row->key_set_url = '';
        $row->auth_token_url = '';
        $row->enabled = 1;
        $row->has_custom_username_parameter = 0;
        $row->custom_username_parameter = '';
        $is_new = true;
    }

    echo "<form method='POST'><input type='hidden' name='action' value='save' />";
    wp_nonce_field('lti');
    echo "<table class='form-table'>\n";
    echo "<tr><th>" . __('Client id',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' name='client_id' value='{$row->client_id}' " . (!$is_new ? 'readonly="readonly"' : '') . "/></td></tr>\n";
    echo "<tr><th>" . __('Key set url',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' size='50' name='key_set_url' value='{$row->key_set_url}' /></td></tr>\n";
    echo "<tr><th>" . __('Auth token url',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' size='50' name='auth_token_url' value='{$row->auth_token_url}' /></td></tr>\n";

    echo "<tr><th>" . __('Custom username parameter',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' name='custom_username_parameter' value='{$row->custom_username_parameter}' /></td></tr>\n";
    echo "<tr><th>" . __('Has custom username',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='checkbox' name='has_custom_username_parameter' value='1' ";

    echo $row->has_custom_username_parameter == 1 ? 'checked=1 ' : ' ';
    echo "/></td></tr>\n";
    echo "<tr><th>" . __('Enabled',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='checkbox' name='enabled' value='1' ";
    echo $row->enabled == 1 ? 'checked=1 ' : ' ';
    echo "/></td></tr>\n";
    echo "</table>";
    echo "<p><input type='submit' class='button-primary' value='" . __('Save',
            'wordpress-mu-ltiadvantage') . "' /></p></form><br /><br />";
}

function lti_network_warning()
{
    echo "<div id='lti-warning' class='updated fade'><p><strong>" . __('LTI Disabled.',
            'lti_network_warning') . "</strong> " . sprintf(__('You must <a href="%1$s">create a network</a> for it to work.',
            'wordpress-mu-ltiadvantage'), "http://codex.wordpress.org/Create_A_Network") . "</p></div>";
}

/* function lti_add_pages() {
  global $current_site, $wpdb, $wp_db_version, $wp_version;

  if ( !isset( $current_site ) && $wp_db_version >= 15260 ) {
  // WP 3.0 network hasn't been configured
  add_action('admin_notices', 'lti_network_warning');
  return false;
  }

  if ( lti_site_admin() && version_compare( $wp_version, '3.0.9', '<=' ) ) {
  if ( version_compare( $wp_version, '3.0.1', '<=' ) ) {
  add_submenu_page('wpmu-admin.php', __( 'LTI Consumer Keys', 'wordpress-mu-ltiadvantage' ), __( 'LTI Consumer Keys', 'wordpress-mu-ltiadvantage'), 'manage_options', 'lti_admin_page', 'lti_admin_page');
  } else {
  add_submenu_page('ms-admin.php', __( 'LTI Consumer Keys', 'wordpress-mu-ltiadvantage' ), 'LTI Consumer Keys', 'manage_options', 'lti_admin_page', 'lti_admin_page');
  }
  }
  }
  add_action( 'admin_menu', 'lti_add_pages' ); */

function lti_network_pages()
{
    add_submenu_page('settings.php', 'LTI Consumers Keys', 'LTI Consumers Keys', 'manage_options',
        'lti_client_id_admin', 'lti_client_id_admin');
}

function lti_admin_page()
{
    add_menu_page('LTI Consumers Keys', 'LTI Consumers Keys', 'manage_options', 'lti_client_id_admin',
        'lti_client_id_admin');
}

if (is_multisite()) {
    add_action('network_admin_menu', 'lti_network_pages');
} else {
    add_action('admin_menu', 'lti_admin_page');
}

function get_lti_hash()
{
    $remote_login_hash = get_site_option('lti_hash');
    if (null == $remote_login_hash) {
        $remote_login_hash = md5(time());
        update_site_option('lti_hash', $remote_login_hash);
    }
    return $remote_login_hash;
}

/**
 *
 * Create table to store the consumers ands passwords if not exists
 */
function lti_maybe_create_db()
{
    global $wpdb;

    get_lti_hash(); // initialise the remote login hash

    lti_check_environment();

    $wpdb->ltitable = $wpdb->base_prefix . 'lti_clients';
    if (lti_site_admin()) {
        $created = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->ltitable}'") != $wpdb->ltitable) {
            $charset_collate = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE IF NOT EXISTS `{$wpdb->ltitable}` (
				  client_id varchar(150) NOT NULL,
                  key_set_url varchar(255) NOT NULL,
                  auth_token_url varchar(255) NOT NULL,
                  enabled tinyint(1) NOT NULL,
                  last_access date DEFAULT NULL,
                  custom_username_parameter varchar(255) DEFAULT NULL,
                  has_custom_username_parameter decimal(1,0) default 0,
                  created datetime NOT NULL,
                  updated datetime NOT NULL,
                  PRIMARY KEY (client_id)
				) $charset_collate;");

            $wpdb->query("CREATE TABLE IF NOT EXISTS `" . $wpdb->base_prefix . "lti_nonce` (
						  client_id varchar(150) NOT NULL,
						  nonce varchar(150) NOT NULL,
						  created timestamp default current_timestamp,
						  PRIMARY KEY (client_id, nonce)
						) $charset_collate");

            $created = 1;
        }
        if ($created) {
            ?>
            <div id="message" class="updated fade"><p><strong><?php _e('LTI database tables created.',
                            'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
        }
    }
}

/**
 *
 * Check if current user is admin
 */
function lti_site_admin()
{
    if (function_exists('is_super_admin')) {
        return is_super_admin();
    } elseif (function_exists('is_site_admin')) {
        return is_site_admin();
    } else {
        return true;
    }
}

function lti_listing($rows, $heading = '')
{
    if ($heading != '') {
        echo "<h3>$heading</h3>";
    }
    if ($rows) {
        echo '<table class="widefat" cellspacing="0"><thead><tr><th>' . __('Client id',
                'wordpress-mu-ltiadvantage') . '</th><th>' . __('Key set url',
                'wordpress-mu-ltiadvantage') . '</th><th>' . __('Auth token url',
                'wordpress-mu-ltiadvantage') . '</th><th>' . __('Enabled',
                'wordpress-mu-ltiadvantage') . '</th><th>' . __('Edit',
                'wordpress-mu-ltiadvantage') . '</th><th>' . __('Delete',
                'wordpress-mu-ltiadvantage') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo "<tr><td>{$row->client_id}</td>";
            echo "<td>{$row->key_set_url}</td>";
            echo "<td>{$row->auth_token_url}</td>";
            echo "<td>" . ($row->enabled == 1 ? __('Yes', 'wordpress-mu-ltiadvantage') : __('No',
                    'wordpress-mu-ltiadvantage'));
            echo "</td><td><form method='POST'><input type='hidden' name='action' value='edit' /><input type='hidden' name='client_id' value='{$row->client_id}' />";
            wp_nonce_field('lti');
            echo "<input type='submit' class='button-secondary' value='" . __('Edit',
                    'wordpress-mu-ltiadvantage') . "' /></form></td><td><form method='POST'><input type='hidden' name='action' value='del' /><input type='hidden' name='client_id' value='{$row->client_id}' />";
            wp_nonce_field('lti');
            echo "<input type='submit' class='button-secondary' value='" . __('Del',
                    'wordpress-mu-ltiadvantage') . "' /></form>";
            echo "</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p>' . __('No records found', 'wordpress-mu-ltiadvantage') . '</p>';
    }
}

add_action('plugins_loaded', 'lti_plugins_loaded_plugin');

function lti_plugins_loaded_plugin()
{
    load_muplugin_textdomain('wordpress-mu-ltiadvantage', 'lang');
}

register_activation_hook(__FILE__, 'add_lti_plugin_activate');
function add_lti_plugin_activate()
{

    // Require parent plugin
    if (!is_plugin_active('wp-session-manager/wp-session-manager.php')) {
        // Stop activation redirect and show error
        wp_die('Sorry, but this plugin requires the wp-session-manager to be installed and active. <br><a href="' . admin_url('plugins.php') . '">&laquo; Return to Plugins</a>');
    }
}


function ltidoMembership($iss, $client_id, $auth_url, $tool_private_key, $jwt_body)
{
    if (isset($jwt_body['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice'])) {

        $username_param = lti_get_username_parameter_from_client_id($client_id);
        if (empty($username_param)) {
            //If custom username the custom paramters are not returned by membership service

            $memberships_url = $jwt_body['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url'];
            $service_version = $jwt_body['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['service_version'];

            // Getting access token with the scopes for the service calls we want to make
            // so they are all authenticated (see serviceauth.php)
            $scopes = [
                "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem",
                "https://purl.imsglobal.org/spec/lti-ags/scope/score",
                "https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly",
                "https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly"
            ];

            $access_token = get_access_token($iss, $client_id, $auth_url, $tool_private_key, $scopes);


            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $memberships_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $access_token
            ]);
            $members = json_decode(curl_exec($ch), true);

            curl_close($ch);
            if (isset($members['members'])) {
                $issuer_id = $jwt_body['iss'];
                $deployment_id = $jwt_body['https://purl.imsglobal.org/spec/lti/claim/deployment_id'];
                $custom_params = $jwt_body['https://purl.imsglobal.org/spec/lti/claim/custom'];
                foreach ($members['members'] as $member) {
                    $lti_user_id = $member['user_id'];
                    $firstname = isset($member['given_name']) ?: '';
                    $lastname = isset($member['family_name']) ?: '';
                    $email = isset($member['user_email']) ?: '';
                    $name = isset($member['display_name']) ?: '';
                    if (!empty($email)) {
                        // TODO check if it exist
                        $userkey = getUserkeyLTI($client_id, $issuer_id, $deployment_id, $lti_user_id, $custom_params);
                        $uinfo = get_user_by('login', $userkey);
                        if (!isset($uinfo) || $uinfo == false) {
                            $ret_id = wp_insert_user(array(
                                'user_login' => $userkey,
                                'user_nicename' => $userkey,
                                'first_name' => $firstname,
                                'last_name' => $lastname,
                                'user_email' => $email,
                                'display_name' => $name,
                                'user_url' => 'http://'
                            ));

                            if (is_wp_error($ret_id)) {
                                $data = intval($ret_id->get_error_data());
                                if (!empty($data)) {
                                    wp_die('<p>' . $ret_id->get_error_message() . '</p>',
                                        __('User creation Failure', 'wordpress-mu-ltiadvantage'),
                                        array('response' => $data, 'back_link' => true));
                                } else {
                                    exit;
                                }
                            }
                        }

                    }
                }
            }
        }

    }
}


function lti_check_nonce($client_id, $nonce)
{
    global $wpdb;

    $table = $wpdb->base_prefix . "lti_nonce";
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE client_id = %s and nonce = %s",
        array($client_id, $nonce)));

    if ($row) {
        wp_die(sprintf(__("LTI validation for client id \"%s\" fails because the nonce \"%s\" is already used",
            "wordpress-mu-ltiadvantage"),
            $client_id, $nonce));
    }

    $wpdb->query($wpdb->prepare("INSERT INTO {$table} ( `client_id`, `nonce`) VALUES ( %s, %s)",
        $client_id, $nonce));
}

/**
 * Checks for the presence of various files and directories that the plugin needs to operate
 *
 * @return void
 */
function lti_check_environment()
{
    if(! file_exists( constant('LTIADVANTAGE_CONF') ) )
    {
        mkdir( constant('LTIADVANTAGE_CONF'), 0775, true );
    }

    if(! file_exists( constant('LTIADVANTAGE_CONF') . '/certs') )
    {
        mkdir( constant('LTIADVANTAGE_CONF') . '/certs', 0775, true );
    }

    if(! file_exists( constant('LTIADVANTAGE_CONF') . '/certs/.htaccess' ) || md5_file( constant('LTIADVANTAGE_CONF') . '/certs/.htaccess' ) != '9f6dc1ce87ca80bc859b47780447f1a6')
    {
        file_put_contents( constant('LTIADVANTAGE_CONF') . '/certs/.htaccess' , "<Files ~ \"\\.(key)$\">\nDeny from all\n</Files>" );
    }
}

function lti_define_constants() {

    if (!defined('LTIADVANTAGE_CONF')) {
        // Store all config stuff with the main blog, which is defined by BLOG_ID_CURRENT_SITE
        if (is_multisite()) {
            switch_to_blog(constant('BLOG_ID_CURRENT_SITE'));
        }

        $upload_dir = wp_upload_dir();
        define('LTIADVANTAGE_CONF', $upload_dir['basedir'] . '/ims-lti-advantage/');

        restore_current_blog();
    }
}

add_action('init', 'lti_define_constants');