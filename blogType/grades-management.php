<?php
/*
Version: 0.0.1
Author: Antoni Bertran
Author URI: http://www.tresipunt.com
License: GPLv2 Copyright (c) 2018
*/

define('PORTAFOLIS_UOC_DEBAT_DIR', dirname(__FILE__)); //an absolute path to this directory
require_once(dirname(__FILE__) . '/class-lti-grade-table.php');

class LTIGradesManagement
{
    private static $instance = null;
    public static $DOMAIN = 'wordpress-mu-ltiadvantage';
    public static $USER_PREFIX_OPTION = 'lti_user_grade_';
    public static $USER_PREFIX_OPTION_COMMENT = 'lti_user_comment_';
    public static $CAPABILITY_EDITOR_ROLE = 'delete_others_pages';
    public static $ACTIVITY_PROGRESS = "Completed";
    public static $GRADING_PROGRESS = "FullyGraded";
    public static $LTI_METAKEY_USER_ID = "lti_user_id";
    public static $LTI_METAKEY_CLAIM_ENDPOINT = "lti_claim_endpoint_";
    public static $LTI_METAKEY_RESOURCE_LINK = "resource_link";
    public static $MAX_GRADE = 100;
    public static $MIN_GRADE = 0;

    private $client = null;
    private $client_id;
    private $lti_issuer;
    private $lti_deployment_id;
    private $lti_custom_params;
    private $lti_namesroleservice;
    private $error;

    /**
     * Init function to register the subject taxonomy
     */
    public function LTIGradesManagement()
    {
        $this->client_id = get_option('lti_clientid');
        $this->lti_issuer = get_option('lti_issuer');
        $this->lti_deployment_id = get_option('lti_deployment_id');
        $this->lti_custom_params = get_option('lti_custom_params');
        $this->lti_namesroleservice = get_option('lti_namesroleservice');
        if ($this->client_id && $this->lti_issuer) {
            $this->client = lti_get_by_client_id($this->client_id);
            if ($this->client && $this->client->grades_enabled == 1) {

                load_plugin_textdomain(self::$DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/../lang');
                add_action('admin_menu', array($this, 'add_lti_grades_management_menu'));
                add_action('admin_init', array($this, 'admin_add_css_js'));
                add_action('wp_ajax_save_grade_lti', array($this, 'save_grade_lti'));


            }
        }
    }

    function admin_add_css_js()
    {
        wp_register_script('grades-management-js-admin', plugins_url('grades-management.js', __FILE__),
            array('jquery'), '20180917');

        $script_params = array(
            'error' => __('Error storing data. Try it later', self::$DOMAIN),
            'success' => __('Success', self::$DOMAIN)
        );

        wp_localize_script('grades-management-js-admin', 'gradesManagementJS', $script_params);
        wp_enqueue_script('grades-management-js-admin');
        add_action('admin_enqueue_scripts', array($this, 'add_admin_css'));

    }

    public function add_admin_css($hook)
    {
        if ('toplevel_page_lti_grades_management' !== $hook) {
            return;
        }
        if (!empty($_GET['type']) && $_GET['type'] == 'comments') {
            wp_register_style('grades-management-css-comments', plugins_url('css/backend-comments.css', __FILE__),
                array(), '20181226');
            wp_enqueue_style('grades-management-css-comments');
        }
        wp_register_style('grades-management-css', plugins_url('css/backend.css', __FILE__),
            array(), '20181226');
        wp_enqueue_style('grades-management-css');

    }


    public function add_lti_grades_management_menu()
    {
        add_menu_page(__('LTI Grades Management', self::$DOMAIN),
            __('LTI Grades Management', self::$DOMAIN), self::$CAPABILITY_EDITOR_ROLE,
            'lti_grades_management', array($this, 'lti_grades_management'));
        if (!empty($this->client->auth_token_url)) {
            add_submenu_page(
                'lti_grades_management',
                __('Sync Members', self::$DOMAIN),
                __('Sync Members', self::$DOMAIN),
                self::$CAPABILITY_EDITOR_ROLE,
                'lti_grades_management_syncmembers',
                array($this, 'lti_grades_management_syncmembers')
            );
        }
    }

    public function lti_grades_management()
    {

        if (!current_user_can(self::$CAPABILITY_EDITOR_ROLE)) {
            return false;
        }

        $type = isset($_GET['type']) ? $_GET['type'] : '';
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id'], 10) : 0;

        if (empty($type) || $user_id == 0) {
            $gradesListTable = new LTI_Grade_Table();
            $gradesListTable->prepare_items();
            ?>
            <div class="wrap">
                <div id="icon-users" class="icon32"></div>
                <h2><?php _e('Student List', self::$DOMAIN) ?></h2>
                <?php $gradesListTable->display(); ?>
            </div>
            <?php
            if ($gradesListTable->has_items()) { ?>
                <button id="save_all" class="button button-primary"><?php _e('Save all', self::$DOMAIN) ?></button>
            <?php }
        } else {
            //print current user and type
            $this->print_user_contributions($user_id, $type);
        }
    }

    private function print_user_contributions($user_id, $type)
    {
        ?>
        <div class="wrap">
            <div id="icon-users" class="icon32"></div>
            <h2><?php echo __($type, self::$DOMAIN) . ' ' . get_userdata($user_id)->display_name; ?> </h2>
            <?php
            switch ($type) {
                case 'comments':
                    $this->print_user_comments($user_id);
                    break;
                default:
                    $this->print_user_posts($type, $user_id);
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Prints a user comments table
     * @param $user_id
     */
    private function print_user_comments($user_id)
    {
        $wp_list_table = _get_list_table('WP_Comments_List_Table', array('screen' => 'edit-comments'));
        $wp_list_table->checkbox = false;
        $wp_list_table->prepare_items();
        if (isset($_REQUEST['paged'])) { ?>
            <input type="hidden" name="paged" value="<?php echo esc_attr(absint($_REQUEST['paged'])); ?>"/>
        <?php }
        //$wp_list_table->views();
        ?>
        <form id="comments-form" method="get">
            <input type="hidden" name="user_id" value="<?php echo esc_attr(absint($user_id)); ?>"/>

            <?php //$wp_list_table->search_box( __( 'Search Comments' ), 'comment' );

            $wp_list_table->display();

            ?>
        </form>
        <?php
    }

    /**
     * Prints a user posts table
     * @param $post_type
     * @param $user_id
     */
    private function print_user_posts($post_type, $user_id)
    {
        $post_type_object = get_post_type_object($post_type);

        if (!$post_type_object) {
            wp_die(__('Invalid post type.'));
        }

        if (!current_user_can($post_type_object->cap->edit_posts)) {
            wp_die(
                '<h1>' . __('You need a higher level of permission.') . '</h1>' .
                '<p>' . __('Sorry, you are not allowed to edit posts in this post type.') . '</p>',
                403
            );
        }

        $_REQUEST['post_type'] = $post_type;
        $_GET['author'] = $user_id;

        $wp_list_table = _get_list_table('WP_Posts_List_Table', array('screen' => 'edit'));
        //$wp_list_table->checkbox = false;
        $wp_list_table->prepare_items();
        $wp_list_table->views(); ?>
        <form id="comments-form" method="get">
            <input type="hidden" name="user_id" value="<?php echo esc_attr(absint($user_id)); ?>"/>

            <?php $wp_list_table->search_box($post_type_object->labels->search_items, $post_type);

            $wp_list_table->display();

            ?>
        </form>
        <?php
    }

    public function lti_grades_management_syncmembers()
    {

        if (!current_user_can(self::$CAPABILITY_EDITOR_ROLE)) {
            return false;
        }

        ob_start();
        ?>
        <div id="grades-management-membership">
            <img class="waiting"
                 src="<?php echo esc_url(admin_url('images/wpspin_light-2x.gif')) ?>"/><?php _e('Loading Memberships',
                self::$DOMAIN) ?></div>
        <?php
        ob_flush();
        flush();

        $auth_url = $this->client->auth_token_url;
        //TODO we need to stopre
        $success = $this->ltidoMembership($this->lti_issuer, $this->client_id, $auth_url, $this->client->private_key,
            $this->lti_namesroleservice,
            $this->lti_deployment_id,
            $this->lti_custom_params);
        if ($success) {
            echo "<h2>" . __('Membership synchronized sucessfully', self::$DOMAIN) . '</h2>';
        } else {
            echo "<h2>" . __('Error executing membership service', self::$DOMAIN) . '</h2>';
            foreach ($this->error as $error) {
                echo "<p>" . $error . "</p>";
            }
        }
    }

    private function ltidoMembership(
        $iss,
        $client_id,
        $auth_url,
        $tool_private_key,
        $namesroleservice,
        $deployment_id,
        $custom_params
    ) {
        $this->error = array();
        $success = false;
        if (isset($namesroleservice)) {

            $username_param = lti_get_username_parameter_from_client_id($client_id);
            if (empty($username_param)) {
                //If custom username the custom paramters are not returned by membership service

                $memberships_url = $namesroleservice['context_memberships_url'];
                $service_version = $namesroleservice['service_version'];

                // Getting access token with the scopes for the service calls we want to make
                // so they are all authenticated (see serviceauth.php)
                $scopes = [
                    "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem",
                    "https://purl.imsglobal.org/spec/lti-ags/scope/score",
                    "https://purl.imsglobal.org/spec/lti-ags/scope/result.readonly",
                    "https://purl.imsglobal.org/spec/lti-nrps/scope/contextmembership.readonly"
                ];

                $access_token = get_access_token($iss, $client_id, $auth_url, $tool_private_key, $scopes);

                if (!empty($access_token)) {


                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $memberships_url);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        'Authorization: Bearer ' . $access_token
                    ]);
                    $members = json_decode(curl_exec($ch), true);

                    curl_close($ch);
                    if (isset($members['members'])) {
                        $blogType = new blogTypeLoader(isset($custom_params['blogtype']) ? $custom_params['blogtype'] : 'defaultType');
                        $overwrite_roles = isset($custom_params[OVERWRITE_ROLES]) ? $custom_params[OVERWRITE_ROLES] : false;
                        $success = true;
                        foreach ($members['members'] as $member) {
                            $lti_user_id = $member['user_id'];
                            $firstname = isset($member['given_name']) ? $member['given_name'] : '';
                            $lastname = isset($member['family_name']) ? $member['family_name'] : '';
                            $email = isset($member['email']) ? $member['email'] : '';
                            $name = isset($member['display_name']) ? $member['display_name'] : '';
                            $user_creted = false;
                            if (!empty($email)) {
                                $userkey = getUserkeyLTI($client_id, $iss, $deployment_id, $lti_user_id,
                                    $custom_params);
                                $uinfo = get_user_by('login', $userkey);
                                if (!isset($uinfo) || $uinfo == false) {
                                    $ret_id = wp_insert_user(array(
                                        'user_login' => $userkey,
                                        'user_nicename' => $userkey,
                                        'first_name' => $firstname,
                                        'last_name' => $lastname,
                                        'user_email' => $email,
                                        'user_pass' => wp_generate_password(10, true, true),
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
                                    $uinfo = get_user_by('login', $userkey);
                                    $user_creted = true;
                                    update_user_meta($uinfo->ID, self::$LTI_METAKEY_USER_ID, $lti_user_id);

                                } else {
                                    update_user_meta($uinfo->ID, self::$LTI_METAKEY_USER_ID, $lti_user_id);
                                }

                                if ($uinfo && (!is_user_member_of_blog($uinfo->ID,
                                            get_current_blog_id()) || $overwrite_roles || $user_creted)) {
                                    if (is_user_member_of_blog($uinfo->ID)) {
                                        remove_user_from_blog($uinfo->ID, get_current_blog_id());
                                    }
                                    $role = $blogType->roleMapping($member['roles']);
                                    if (is_multisite()) {
                                        add_user_to_blog(get_current_blog_id(), $uinfo->ID, $role);
                                    } else {
                                        wp_update_user(array('ID' => $uinfo->ID, 'role' => $role));
                                    }
                                }
                            }
                        }
                    } else {
                        $this->error[] = __('Service doesn\'t return a valid member document', self::$DOMAIN);
                    }
                } else {
                    $this->error[] = __('Can\'t generate access token to get membership data!', self::$DOMAIN);
                }
            } else {
                $this->error[] = __('Custom username is not available to do a membership', self::$DOMAIN);
            }

        } else {
            $this->error[] = __('Platform doesn\'t have membership enabled', self::$DOMAIN);
        }
        return $success;
    }

    private function ltiStoreGrade(

        $iss,
        $client_id,
        $auth_url,
        $tool_private_key,
        $lti_user_id,
        $grade,
        $comment,
        $claim_url,
        $max_score
    ) {
        $success = false;
        if (isset($claim_url)) {

// Getting access token with the scopes for the service calls we want to make
// so they are all authenticated (see serviceauth.php)
            $access_token = get_access_token($iss, $client_id, $auth_url, $tool_private_key,
                [
                "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem",
                "https://purl.imsglobal.org/spec/lti-ags/scope/score"
            ]);

// Build grade book request
            $grade_call = [
                "scoreGiven" => $grade,
                "scoreMaximum" => $max_score,
                "comment" => $comment,
                "activityProgress" => self::$ACTIVITY_PROGRESS,
                "gradingProgress" => self::$GRADING_PROGRESS,
                "timestamp" => date('Y-m-d\TH:i:s') . "+00:00",
                "userId" => $lti_user_id
            ];

// Call grade book line item endpoint to send back a grade
            $line_item_url = $claim_url['lineitem'];
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $line_item_url . '/scores');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($grade_call));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $access_token,
                'Content-Type: application/vnd.ims.lis.v1.score+json'
            ]);
            $success = curl_exec($ch);
            curl_close($ch);

        } else {
            $this->error[] = __('Platform doesn\'t have membership enabled', self::$DOMAIN);
        }
        return $success;
    }


    /**
     * Returns the user grade
     * @param $user_id
     * @return int|mixed|void
     */
    public static function lti_grades_get_user_grade($user_id)
    {
        $grade = get_option(self::$USER_PREFIX_OPTION . $user_id, false);
        if ($grade) {
            $grade = intval($grade);
        }
        return $grade;
    }

    /**
     * Returns the user grade comment
     * @param $user_id
     * @return int|mixed|void
     */
    public static function lti_grades_get_user_comment($user_id)
    {
        $comment = get_option(self::$USER_PREFIX_OPTION_COMMENT . $user_id, '');
        return $comment;
    }


    /**
     * Stores the current url to send back grades
     * @param $user_id
     * @param $url claim
     * @return int|mixed|void
     */
    public static function lti_save_url_claim($user_id, $url)
    {
        $key = LTIGradesManagement::$LTI_METAKEY_CLAIM_ENDPOINT . (is_multisite() ? get_current_blog_id() : 1);
        update_user_meta($user_id, $key, $url);
    }
    /**
     * Return the url claim
     * @param $user_id
     * @return mixed
     */
    public static function lti_get_url_claim($user_id)
    {
        $key = LTIGradesManagement::$LTI_METAKEY_CLAIM_ENDPOINT . (is_multisite() ? get_current_blog_id() : 1);
        $url = get_user_meta($user_id, $key, true);

        return $url;
    }
    /**
     * Stores the current resource_link
     * @param $user_id
     * @param $resource_link
     * @return int|mixed|void
     */
    public static function lti_save_resource_link($user_id, $resource_link)
    {
        $key = LTIGradesManagement::$LTI_METAKEY_RESOURCE_LINK . (is_multisite() ? get_current_blog_id() : 1);
        update_user_meta($user_id, $key, $resource_link);
    }

    /**
     * Return the resource_link
     * @param $user_id
     * @return mixed
     */
    public static function lti_get_resource_link($user_id)
    {
        $key = LTIGradesManagement::$LTI_METAKEY_RESOURCE_LINK . (is_multisite() ? get_current_blog_id() : 1);
        $url = get_user_meta($user_id, $key, true);

        return $url;
    }


    /**
     * Stores an user grade
     */
    function save_grade_lti()
    {

        $userid = !empty($_POST['userid']) ? intval($_POST['userid']) : false;
        $grade = !empty($_POST['grade']) ? intval($_POST['grade']) : false;
        $comment = !empty($_POST['comment']) ? $_POST['comment'] : '';

        $ret = array('result' => false, 'error' => false);
        if (!current_user_can(self::$CAPABILITY_EDITOR_ROLE)) {
            $ret['result'] = false;
            $ret['error'] = __('You are not allowed to perform this operation', self::$DOMAIN);
        } else {
            if ($userid !== false && $grade !== false) {

                update_option(self::$USER_PREFIX_OPTION . $userid, $grade);
                update_option(self::$USER_PREFIX_OPTION_COMMENT . $userid, $comment);
                $claim_url = $this->lti_get_url_claim(get_current_user_id());

                //We don't create new line items
                //                 $line_item = $this->get_line_item('score', $max_score);
                //                $line_item_url = $line_item['id'];
                if ($claim_url && isset($claim_url['lineitems'])) {
                    $auth_url = $this->client->auth_token_url;
                    if (!isset($claim_url['lineitem'])) {

                        $blogname = get_bloginfo('name');
                        $line_item = $this->get_line_item($this->lti_issuer, $this->client_id, $auth_url, $this->client->private_key, $claim_url['lineitems'], get_current_user_id(), $blogname.' grades', self::$MAX_GRADE);
                        if (!empty($line_item['id'])) {
                            $claim_url['lineitem'] = $line_item['id'];

                            LTIGradesManagement::lti_save_url_claim(get_current_user_id(), $claim_url);
                        } else {
                            $ret['result'] = false;
                            $ret['error'] = __('Can not generate the line item to store data', self::$DOMAIN);
                            return $ret;
                        }
                    }
                    $lti_user_id = get_user_meta($userid, self::$LTI_METAKEY_USER_ID, true);
                    if ($lti_user_id) {
                        $success = $this->ltiStoreGrade(
                            $this->lti_issuer, $this->client_id, $auth_url, $this->client->private_key,
                            $lti_user_id,
                            $grade,
                            $comment,
                            $claim_url,
                            self::$MAX_GRADE,
                            self::$ACTIVITY_PROGRESS,
                            self::$GRADING_PROGRESS
                        );
                        $ret['result'] = $success;
                        if (!$success) {
                            $ret['error'] = __('Error storing grade on platform', self::$DOMAIN);
                        }
                    } else {
                        $ret['result'] = false;
                        $ret['error'] = __('Current user has not lti id, can not store it on platform', self::$DOMAIN);
                    }
                } else {
                    $ret['result'] = false;
                    $ret['error'] = __('We don\'t have the LTI claim url!', self::$DOMAIN);
                }
            } else {
                $ret['result'] = false;
                $ret['error'] = __('Missing user id or grade', self::$DOMAIN);
            }
        }
        echo json_encode($ret);

        wp_die(); // this is required to terminate immediately and return a proper response
    }


    private function get_line_item($iss, $client_id, $auth_url, $tool_private_key, $line_items_url, $user_id, $tag, $max_score = 9999) {

        // Getting access token with the scopes for the service calls we want to make
        // so they are all authenticated (see serviceauth.php)
        $access_token = get_access_token($iss, $client_id, $auth_url, $tool_private_key, [
            "https://purl.imsglobal.org/spec/lti-ags/scope/lineitem"
        ]);

        // Line items GET
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $line_items_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer '. $access_token,
            'Accept: application/vnd.ims.lis.v2.lineitemcontainer+json'
        ]);
        $resp = curl_exec($ch);
        $line_items = json_decode($resp, true);
        curl_close ($ch);

        $found_line_item = [];
        foreach ($line_items as $line_item) {
            if ($line_item['tag'] == $tag) {
                $found_line_item = $line_item;
                break;
            }
        }

        // if we can't find it, create it
        if (empty($found_line_item)) {
            // Build line item book request
            $new_line_item = [
                "label" => $tag,
                "tag" => $tag,
                "resourceId" => "" . $this->lti_get_resource_link($user_id),
                "scoreMaximum" => $max_score,
            ];

            // Call grade book line item endpoint to send back a grade
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $line_items_url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($new_line_item));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer '. $access_token,
                'Content-Type: application/vnd.ims.lis.v2.lineitem+json',
                'Accept: application/vnd.ims.lis.v2.lineitem+json'
            ]);
            $line_item = curl_exec($ch);
            curl_close ($ch);

            $found_line_item = json_decode($line_item, true);
        }

        return $found_line_item;
    }

    /**
     * Return an instance of the class
     *
     * Return an instance of the LTI Grades Management Class.
     *
     * @since 1.0.0
     * @access public static
     *
     * @return LTIGradesManagement class instance.
     */
    public static function get_instance()
    {
        if (null == self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    } //end get_instance

}

add_action('plugins_loaded', 'lti_gradesmanagement_instantiate');
$lti_gradesmanagement = null;
function lti_gradesmanagement_instantiate()
{
    global $lti_gradesmanagement;
    $lti_gradesmanagement = LTIGradesManagement::get_instance();
}



