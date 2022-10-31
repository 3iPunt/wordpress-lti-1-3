<?php
/*
 * Plugin Name: IMS LTI 1.3
 * @name Load Blog Type
 * @abstract Processes incoming requests for IMS  1.3LTI and apply wordpress with blogType parametrer. This code is developed based on Chuck Severance code
 * @author Antoni Bertran (antoni@tresipunt.com)
 * @copyright 2022 3ipunt and Universitat Oberta de Catalunya
 * @license Apache License
 * Date August 2022
 */

require_once(ABSPATH . '/wp-admin/includes/plugin.php');
require_once(ABSPATH . '/wp-includes/ms-functions.php');
require_once(ABSPATH . '/wp-admin/includes/bookmark.php');

require_once dirname(__FILE__) . '/blogType/blogTypeLoader.php';
require_once dirname(__FILE__) . '/lti/lib.php';

require_once dirname(__FILE__) . '/vendor/autoload.php';

use \Firebase\JWT\JWT;
use \Firebase\JWT\JWK;

require_once dirname(__FILE__) . '/blogType/Constants.php';
require_once dirname(__FILE__) . '/blogType/utils/UtilsPropertiesWP.php';

use \IMSGlobal\LTI;

function lti_client_id_admin()
{
    global $wpdb;
    if (false == lti_site_admin()) {
        return false;
    }

    lti_maybe_create_db();

    $is_editing = false;
    echo '<h2>' . __('LTI: Clients', 'wordpress-mu-ltiadvantage') . '</h2>';
    if (!empty($_POST['action'])) {
        check_admin_referer('lti');
        $client_id = $_POST['client_id'];
        switch ($_POST['action']) {
            case "edit":
                $row = LTIUtils::lti_get_by_client_id($client_id);
                if ($row) {
                    lti_edit($row);
                    $is_editing = true;
                } else {
                    ?>
                    <div id="message" class="error fade"><p><strong><?php _e('LTI Tool not found.',
                                    'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                }
                break;
            case "view_config_info":
                $row = LTIUtils::lti_get_by_client_id($client_id);
                if ($row) {
                    lti_show_launch($row);
                    lti_show_keys($row);
                    $is_editing = true;
                } else {
                    ?>
                    <div id="message" class="error fade"><p><strong><?php _e('LTI Tool not found.',
                                    'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                }
                break;
            case "generate_priv_pub_key":
                $row = LTIUtils::lti_get_by_client_id($client_id);
                if ($row) {
                    lti_generate_public_and_private_key($client_id);
                    $row = LTIUtils::lti_get_by_client_id($client_id);

                    lti_show_keys($row);
                    $is_editing = true;
                } else {
                    ?>
                    <div id="message" class="error fade"><p><strong><?php _e('LTI Tool not found.',
                                    'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                }
                break;
            case "save":
                $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lti_13_get_table() . " WHERE client_id = %s",
                    $client_id));
                $auth_login_url = isset($_POST['auth_login_url']) ? $_POST['auth_login_url'] : '';
                $issuer = isset($_POST['issuer']) ? $_POST['issuer'] : '';
                $key_set_url = isset($_POST['key_set_url']) ? $_POST['key_set_url'] : '';
                $auth_token_url = isset($_POST['auth_token_url']) ? $_POST['auth_token_url'] : '';
                $enabled = isset($_POST['enabled']) ? $_POST['enabled'] : 0;
                $custom_username_parameter = isset($_POST['custom_username_parameter']) ? $_POST['custom_username_parameter'] : '';
                $has_custom_username_parameter = isset($_POST['has_custom_username_parameter']) ? $_POST['has_custom_username_parameter'] : 0;
                $grade_column_tag = isset($_POST['grade_column_tag']) ? $_POST['grade_column_tag'] : '';
                $grade_column_name = isset($_POST['grade_column_name']) ? $_POST['grade_column_name'] : '';
                $student_role = isset($_POST['student_role']) ? $_POST['student_role'] : '';
                $deployments_ids = isset($_POST['deployments_ids']) ? $_POST['deployments_ids'] : '';
                if ($row) {
                    $wpdb->query($wpdb->prepare("UPDATE " . lti_13_get_table() . " SET  auth_login_url = %s, issuer = %s, key_set_url = %s, auth_token_url = %s, enabled = %d, custom_username_parameter = %s, has_custom_username_parameter = %d, grade_column_tag= %s, grade_column_name= %s, student_role = %s, deployments_ids = %s, updated = now()  WHERE client_id = %s",
                        $auth_login_url, $issuer, $key_set_url, $auth_token_url, $enabled,
                        $custom_username_parameter, $has_custom_username_parameter, $grade_column_tag,
                        $grade_column_name, $student_role, $deployments_ids, $client_id));
                    ?>
                    <div id="message" class="updated fade"><p><strong><?php _e('LTI Tool updated.',
                                    'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                } else {
                    $wpdb->query($wpdb->prepare("INSERT INTO " . lti_13_get_table() . " ( `client_id`, `auth_login_url`, `issuer`, `key_set_url`, `auth_token_url`, `enabled`, `custom_username_parameter`, `has_custom_username_parameter`, `grade_column_tag`, `grade_column_name`, `student_role`, `deployments_ids`, `created`, `updated`) VALUES ( %s, %s, %s, %s, %s, %d, %s, %d, %s, %s, %s, %s, now(), now())",
                        $client_id, $auth_login_url, $issuer, $key_set_url, $auth_token_url, $enabled,
                        $custom_username_parameter,
                        $has_custom_username_parameter,
                        $grade_column_tag,
                        $grade_column_name,
                        $student_role,
                        $deployments_ids));
                    lti_generate_public_and_private_key($client_id);


                    ?>
                    <div id="message" class="updated fade"><p><strong><?php _e('LTI Tool added.',
                                    'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                }
                break;
            case "del":
                $wpdb->query($wpdb->prepare("DELETE FROM " . lti_13_get_table() . " WHERE client_id = %s", $client_id));
                ?>
                <div id="message" class="updated fade"><p><strong><?php _e('LTI Tool deleted.',
                                'wordpress-mu-ltiadvantage') ?></strong></p></div> <?php
                break;
        }
    }

    if (!$is_editing) {
        $search_str = isset($_POST['search_txt']) ? $_POST['search_txt'] : '';
        echo "<h3>" . __('Search', 'wordpress-mu-ltiadvantage') . "</h3>";
        $escaped_search = addslashes($search_str);
        $rows = $wpdb->get_results("SELECT * FROM " . lti_13_get_table() . " WHERE client_id LIKE '%{$escaped_search}%' ");
        lti_listing($rows,
            empty($escaped_search) ? '' : sprintf(__("Searching for \"%s\"", 'wordpress-mu-ltiadvantage'),
                esc_html($search_str)));
        echo '<form method="POST">';
        wp_nonce_field('lti');
        echo '<input type="hidden" name="action" value="search" />';
        echo '<p>';
        echo _e("Search:", 'wordpress-mu-ltiadvantage');
        echo " <input type='text' name='search_txt' value='" . esc_html($search_str) . "' /></p>";
        echo "<p><input type='submit' class='button-secondary' value='" . __('Search',
                'wordpress-mu-ltiadvantage') . "' /></p>";
        echo "</form><br />";
        lti_edit();
    }
}

function lti_generate_public_and_private_key($client_id) {
    global $wpdb;
    $keys = lti_generate_private_public_key();

    return $wpdb->query($wpdb->prepare("UPDATE " . lti_13_get_table() . " SET  private_key = %s, public_key = %s  WHERE client_id = %s",
        $keys['privKey'], $keys['pubKey'], $client_id));
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
        $row->issuer = '';
        $row->auth_login_url = '';
        $row->key_set_url = '';
        $row->auth_token_url = '';
        $row->enabled = 1;
        $row->grade_column_tag = '';
        $row->grade_column_name = '';
        $row->student_role = 'subscriber';
        $row->has_custom_username_parameter = 0;
        $row->custom_username_parameter = '';
        $row->public_key = '';
        $row->deployments_ids = '';
        $is_new = true;
    }

    echo "<form method='POST'><input type='hidden' name='action' value='save' />";
    wp_nonce_field('lti');
    echo "<table class='form-table'>\n";
    echo "<tr><th>" . __('Client id',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' name='client_id' value='{$row->client_id}' " . (!$is_new ? 'readonly="readonly"' : '') . "/></td></tr>\n";
    echo "<tr><th>" . __('Issuer',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' size='50' name='issuer' value='{$row->issuer}' /></td></tr>\n";
    echo "<tr><th>" . __('Auth login url',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' size='50' name='auth_login_url' value='{$row->auth_login_url}' /></td></tr>\n";
    echo "<tr><th>" . __('Key set url',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' size='50' name='key_set_url' value='{$row->key_set_url}' /></td></tr>\n";
    echo "<tr><th>" . __('Auth token url',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' size='50' name='auth_token_url' value='{$row->auth_token_url}' /></td></tr>\n";

    echo "<tr><th>" . __('Custom username parameter',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' size='50' name='custom_username_parameter' value='{$row->custom_username_parameter}' /></td></tr>\n";
    echo "<tr><th>" . __('Deployments ids (comma separeated)',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' name='deployments_ids' value='{$row->deployments_ids}' /></td></tr>\n";
    echo "<tr><th>" . __('Has custom username',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='checkbox' name='has_custom_username_parameter' value='1' ";

    echo $row->has_custom_username_parameter == 1 ? 'checked=1 ' : ' ';
    echo "/></td></tr>\n";
    echo "<tr><th>" . __('Enabled',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='checkbox' name='enabled' value='1' ";
    echo $row->enabled == 1 ? 'checked=1 ' : ' ';
    echo "/></td></tr>\n";

    echo "<tr><th>" . __('Grade Column tag',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' name='grade_column_tag' value='{$row->grade_column_tag}' /></td></tr>\n";
    echo "<tr><th>" . __('Grade Column name',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' name='grade_column_name' value='{$row->grade_column_name}' /></td></tr>\n";

    $options = '<option value="subscriber" ' . ($row->student_role == 'subscriber' ? 'selected' : '') . '>' . __('Subscriber',
            'wordpress-mu-ltiadvantage') . '</option>';
    $options .= '<option value="author" ' . ($row->student_role == 'author' ? 'selected' : '') . '>' . __('Author',
            'wordpress-mu-ltiadvantage') . '</option>';
    echo "<tr><th>" . __('Student Role',
            'wordpress-mu-ltiadvantage') . "</th><td><select name='student_role'>'.$options.'</select>";

    echo "</td></tr>\n";

    echo "<tr><th>" . __('Public key',
            'wordpress-mu-ltiadvantage') . "</th><td><textarea readonly cols='60' rows  ='5'>" . $row->public_key . "</textarea>";
    echo "</td></tr>\n";
    echo "</table>";
    echo "<p><input type='submit' class='button-primary' value='" . __('Save',
            'wordpress-mu-ltiadvantage') . "' /></p></form>";
    if (!$is_new) {
        echo "<form method='POST'><input type='hidden' name='action' value='generate_priv_pub_key' /><p><input type='submit' class='button-primary' value='" . __('Generate new private and public key',
                'wordpress-mu-ltiadvantage') . "' /></p>" . wp_nonce_field('lti') . "<input type='hidden' name='client_id' value='{$row->client_id}' /></form><br /><br />";
    }
}

function lti_show_keys($row = false)
{
    echo "<table class='form-table'>\n";
    echo "<tr><th>" . __('Client id',
            'wordpress-mu-ltiadvantage') . "</th><td><input type='text' name='client_id' id='client_id' value='{$row->client_id}' readonly=\"readonly\"/>" .
        '<div class="wordpress-lti-tooltip">
            <button class="button-secondary" onclick="wordpress_lti_copy(\'client_id\', \'client_idTooltip\')" onmouseout="wordpress_lti_copy_outFunc(\'client_idTooltip\')">
              <span class="wordpress-lti-tooltiptext" id="client_idTooltip">' . __('Copy to clipboard', 'wordpress-mu-ltiadvantage') . '</span>
              ' . __('Copy text', 'wordpress-mu-ltiadvantage') . '
              </button>
        </div>' .
    "</td></tr>\n";
    echo "<tr><th>" . __('Public key',
            'wordpress-mu-ltiadvantage') . "</th><td><textarea readonly cols='60' rows='6' id='publicKey'>" . $row->public_key . "</textarea>" .
        '<div class="wordpress-lti-tooltip">
            <button class="button-secondary" onclick="wordpress_lti_copy(\'publicKey\', \'publicKeyTooltip\')" onmouseout="wordpress_lti_copy_outFunc(\'publicKeyTooltip\')">
              <span class="wordpress-lti-tooltiptext" id="publicKeyTooltip">' . __('Copy to clipboard', 'wordpress-mu-ltiadvantage') . '</span>
              ' . __('Copy text', 'wordpress-mu-ltiadvantage') . '
              </button>
        </div>';
    echo "</td></tr>\n";
    echo "</table>\n";
    echo "<form method='post'><input type='submit' class='button button-cancel' value='" . __('Back',
            'wordpress-mu-ltiadvantage') . "' /></form>";

}

function lti_admin_add_css_js()
{
    wp_register_script('lti-wp-backend', plugins_url('lti/js/backend.js', __FILE__),
        array(), '202210301');

    $script_params = array(
        'copied' => __('Copied', 'wordpress-mu-ltiadvantage'),
        'copyToClipboard' => __('Copy to clipboard', 'wordpress-mu-ltiadvantage')
    );

    wp_localize_script('lti-wp-backend', 'scriptParams', $script_params);
    wp_enqueue_script('lti-wp-backend');

    wp_register_style('grades-management-css', plugins_url('lti/css/backend.css', __FILE__),
        array(), '20221030');
    wp_enqueue_style('grades-management-css');

}

function lti_show_launch($row = false)
{
    echo '<table class="form-table">';
    echo '<tr><th>' . __('Launch URL',
            'wordpress-mu-ltiadvantage') . '</th><td><input type="text" id="launchUrl" size="60" value="' . plugin_dir_url(__FILE__) . 'lti/launch.php" readonly="readonly"/>' .
        '<div class="wordpress-lti-tooltip">
            <button class="button-secondary" onclick="wordpress_lti_copy(\'launchUrl\', \'launchUrlTooltip\')" onmouseout="wordpress_lti_copy_outFunc(\'launchUrlTooltip\')">
              <span class="wordpress-lti-tooltiptext" id="launchUrlTooltip">' . __('Copy to clipboard', 'wordpress-mu-ltiadvantage') . '</span>
              ' . __('Copy text', 'wordpress-mu-ltiadvantage') . '
              </button>
        </div>' .
        '</td></tr>';
    echo '<tr><th>' . __('Login URL',
            'wordpress-mu-ltiadvantage') . '</th><td><input type="text" id="loginUrl" size="60" value="' . plugin_dir_url(__FILE__) . 'lti/login.php" readonly="readonly"/>' .
        '<div class="wordpress-lti-tooltip">
            <button class="button-secondary" onclick="wordpress_lti_copy(\'loginUrl\', \'loginUrlTooltip\')" onmouseout="wordpress_lti_copy_outFunc(\'loginUrlTooltip\')">
              <span class="wordpress-lti-tooltiptext" id="loginUrlTooltip">' . __('Copy to clipboard', 'wordpress-mu-ltiadvantage') . '</span>
              ' . __('Copy text', 'wordpress-mu-ltiadvantage') . '
              </button>
        </div>' .
        '</td></tr>';
    echo '<tr><th>' . __('URL JWKS',
            'wordpress-mu-ltiadvantage') . '</th><td><input type="text" id="jwksUrl" size="60" value="' . plugin_dir_url(__FILE__) . 'lti/jwks.php?kid=' . $row->client_id . '" readonly="readonly"/>' .
        '<div class="wordpress-lti-tooltip">
            <button class="button-secondary" onclick="wordpress_lti_copy(\'jwksUrl\', \'jwksUrlTooltip\')" onmouseout="wordpress_lti_copy_outFunc(\'jwksUrlTooltip\')">
              <span class="wordpress-lti-tooltiptext" id="jwksUrlTooltip">' . __('Copy to clipboard', 'wordpress-mu-ltiadvantage') . '</span>
              ' . __('Copy text', 'wordpress-mu-ltiadvantage') . '
              </button>
        </div>' .
        '</td></tr>';
    $keys = [$row->client_id => $row->private_key];

    $jwksInfo = json_encode(LTI\JWKS_Endpoint::new($keys)->get_public_jwks());
    echo '<tr><th>' . __('JWKS Info',
            'wordpress-mu-ltiadvantage') . '</th><td><textarea readonly cols="60" rows="4" id="jwksInfo">' . $jwksInfo . '</textarea>'.
        '<div class="wordpress-lti-tooltip">
            <button class="button-secondary" onclick="wordpress_lti_copy(\'jwksInfo\', \'jwksInfoTooltip\')" onmouseout="wordpress_lti_copy_outFunc(\'jwksInfoTooltip\')">
              <span class="wordpress-lti-tooltiptext" id="jwksUrlTooltip">' . __('Copy to clipboard', 'wordpress-mu-ltiadvantage') . '</span>
              ' . __('Copy text', 'wordpress-mu-ltiadvantage') . '
              </button>
        </div>' .
        '</td></tr>';
    echo '</table>';

}

function lti_network_warning()
{
    echo "<div id='lti-warning' class='updated fade'><p><strong>" . __('LTI Disabled.',
            'lti_network_warning') . "</strong> " . sprintf(__('You must <a href="%1$s">create a network</a> for it to work.',
            'wordpress-mu-ltiadvantage'), "http://codex.wordpress.org/Create_A_Network") . "</p></div>";
}


function lti_network_pages()
{
    add_submenu_page('settings.php', 'LTI Clients', 'LTI Clients', 'manage_options',
        'lti_client_id_admin', 'lti_client_id_admin');
}

function lti_admin_page()
{
    add_menu_page('LTI Clients', 'LTI Clients', 'manage_options', 'lti_client_id_admin',
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

    if (lti_site_admin()) {
        $created = 0;
        if ($wpdb->get_var("SHOW TABLES LIKE '" . lti_13_get_table() . "'") != lti_13_get_table()) {
            $charset_collate = $wpdb->get_charset_collate();
            $wpdb->query("CREATE TABLE IF NOT EXISTS `" . lti_13_get_table() . "` (
				  client_id varchar(150) NOT NULL,
                  issuer varchar(255) NOT NULL,
                  auth_login_url varchar(255) NOT NULL,
                  key_set_url varchar(255) NOT NULL,
                  auth_token_url varchar(255) NOT NULL,
                  deployments_ids tinytext null,
                  enabled tinyint(1) NOT NULL,
                  private_key mediumtext NULL,
                  public_key mediumtext NULL,
                  custom_username_parameter varchar(255) DEFAULT NULL,
                  has_custom_username_parameter decimal(1,0) default 0,
                  grade_column_tag varchar(255) default '',
                  grade_column_name varchar(255) default '',
                  student_role varchar(30) default 'subscriber',
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
                'wordpress-mu-ltiadvantage') . '</th><th>' . __('Show config information',
                'wordpress-mu-ltiadvantage') . '</th><th>' . __('Edit',
                'wordpress-mu-ltiadvantage') . '</th><th>' . __('Delete',
                'wordpress-mu-ltiadvantage') . '</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo "<tr><td>{$row->client_id}</td>";
            echo "<td>{$row->key_set_url}</td>";
            echo "<td>{$row->auth_token_url}</td>";
            echo "<td>" . ($row->enabled == 1 ? __('Yes', 'wordpress-mu-ltiadvantage') : __('No',
                    'wordpress-mu-ltiadvantage'));
            echo "</td><td><form method='POST'><input type='hidden' name='action' value='view_config_info' /><input type='hidden' name='client_id' value='{$row->client_id}' />";
            wp_nonce_field('lti');
            echo "<input type='submit' class='button-secondary' value='" . __('Show',
                    'wordpress-mu-ltiadvantage') . "' /></form>";
            echo "</td><td><form method='POST'><input type='hidden' name='action' value='edit' /><input type='hidden' name='client_id' value='{$row->client_id}' />";
            wp_nonce_field('lti');
            echo "<input type='submit' class='button-secondary' value='" . __('Edit',
                    'wordpress-mu-ltiadvantage') . "' /></form></td>";
            echo "<td><form method='POST'><input type='hidden' name='action' value='del' /><input type='hidden' name='client_id' value='{$row->client_id}' />";
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
add_action('admin_init', 'lti_admin_add_css_js');

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

/**
 * Generates a private and public key
 * @return array
 */
function lti_generate_private_public_key()
{
    $config = array(
        "digest_alg" => "sha512",
        "private_key_bits" => 4096,
        "private_key_type" => OPENSSL_KEYTYPE_RSA,
    );

// Create the private and public key
    $res = openssl_pkey_new($config);

// Extract the private key from $res to $privKey
    openssl_pkey_export($res, $privKey);

// Extract the public key from $res to $pubKey
    $pubKey = openssl_pkey_get_details($res);
    $pubKey = $pubKey["key"];

    return ['privKey' => $privKey, 'pubKey' => $pubKey];
}

function lti_13_get_table()
{
    global $wpdb;
    $wpdb->ltitable = $wpdb->base_prefix . 'lti_clients';
    return $wpdb->ltitable;
}

function lti_13_get_tools_with_priv_key($kid = false)
{
    global $wpdb;

    $sql = "SELECT * FROM " . lti_13_get_table() . " WHERE coalesce(private_key, '') != '' ";
    if ($kid) {
        $sql = $wpdb->prepare($sql . ' AND client_id = %s',
            $kid);
    }
    $rows = $wpdb->get_results($sql);
    return $rows;
}

require_once dirname(__FILE__) . '/lti/lti-advantage-management.php';