<?php
/*
Version: 1.0.0
Author: Antoni Bertran (antoni@tresipunt.com)
Author URI: http://www.tresipunt.com
License: GPLv2 Copyright (c) 2022
*/

require_once(dirname(__FILE__) . '/class-lti-grade-table.php');
require_once(dirname(__FILE__) . '/lib.php');
require_once __DIR__ . '/../vendor/autoload.php';

use \IMSGlobal\LTI;

class LTIAdvantageManagement
{
    private static $instance = null;
    public static $DOMAIN = 'wordpress-mu-ltiadvantage';
    public static $USER_PREFIX_OPTION = 'lti_user_grade_';
    public static $USER_PREFIX_OPTION_COMMENT = 'lti_user_comment_';
    public static $CAPABILITY_EDITOR_ROLE = 'delete_others_pages';
    public static $ACTIVITY_PROGRESS = "Completed";
    public static $GRADING_PROGRESS = "FullyGraded";
    public static $LTI_METAKEY_USER_ID = "lti_user_id";
    public static $MAX_GRADE = 100;
    public static $MIN_GRADE = 0;

    private $client = null;
    private $client_id;
    private $lti_issuer;
    private $lti_deployment_id;
    private $lti_custom_params;
    private $lti_namesroleservice;
    private $error;
    private $members_result;

    /**
     * Init function to register the subject taxonomy
     */
    public function __construct()
    {
        $this->client_id = get_option('lti_clientid');
        $this->lti_issuer = get_option('lti_issuer');
        $this->lti_deployment_id = get_option('lti_deployment_id');
        $this->lti_custom_params = get_option('lti_custom_params');
        $this->lti_namesroleservice = get_option('lti_namesroleservice');
        if ($this->client_id && $this->lti_issuer) {
            $this->client = LTIUtils::lti_get_by_client_id($this->client_id);
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
        wp_register_script('grades-management-js-admin', plugins_url('js/grades-management.js', __FILE__),
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
            wp_register_style('grades-management-css-comments', plugins_url('lti/css/backend-comments.css', __FILE__),
                array(), '20181226');
            wp_enqueue_style('grades-management-css-comments');
        }

    }


    public function add_lti_grades_management_menu()
    {
        add_menu_page(__('LTI Advantage', self::$DOMAIN),
            __('LTI Advantage', self::$DOMAIN), self::$CAPABILITY_EDITOR_ROLE,
            'lti_advantage', array($this, 'lti_advantage'));
        $launch = get_user_meta(get_current_user_id(), 'lti_launch_' . get_current_blog_id(), true);
        if ($launch) {
            if ($launch->has_ags()) {
                add_submenu_page(
                    'lti_advantage',
                    __('LTI Grades Management', self::$DOMAIN),
                    __('LTI Grades Management', self::$DOMAIN),
                    self::$CAPABILITY_EDITOR_ROLE,
                    'lti_grades_management',
                    array($this, 'lti_grades_management')
                );
            }
            if ($launch->has_nrps()) {
                add_submenu_page(
                    'lti_advantage',
                    __('Sync Members', self::$DOMAIN),
                    __('Sync Members', self::$DOMAIN),
                    self::$CAPABILITY_EDITOR_ROLE,
                    'lti_grades_management_syncmembers',
                    array($this, 'lti_grades_management_syncmembers')
                );
            }
        }
    }

    public function lti_advantage()
    {

        if (!current_user_can(self::$CAPABILITY_EDITOR_ROLE)) {
            return false;
        }
        ?>
        <div class="wrap">
            <h2><?php _e('List LTI Advantage extensions', self::$DOMAIN) ?></h2>
        </div>
        <?php
        $launch = get_user_meta(get_current_user_id(), 'lti_launch_' . get_current_blog_id(), true);
        $enabled_ags = $launch && $launch->has_ags() ? 'success' : 'error';
        $enabled_nrps = $launch && $launch->has_nrps() ? 'success' : 'error';
        ?>
        <div class="notice notice-<?php echo $enabled_ags ?>">
            <p><?php _e('Assignment and Grade Services (ags)', self::$DOMAIN) ?></p>
        </div>
        <div class="notice notice-<?php echo $enabled_nrps ?>">
            <p><?php _e('Names and Role Provisioning Services (nrps)', self::$DOMAIN) ?></p>
        </div>
        <?php

    }

    public function lti_grades_management()
    {

        if (!current_user_can(self::$CAPABILITY_EDITOR_ROLE)) {
            return false;
        }

        $type = isset($_GET['type']) ? $_GET['type'] : '';
        $user_id = isset($_GET['user_id']) ? intval($_GET['user_id'], 10) : 0;

        if (empty($type) || $user_id == 0) {
            $lti_conf = LTIUtils::lti_get_by_client_id($this->client_id);
            $student_role = false;
            if (isset($lti_conf)) {
                $student_role = $lti_conf->student_role;
            }
            $gradesListTable = new LTI_Grade_Table(array(), $student_role);
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

        $success = $this->ltidoMembership($this->client_id, $this->lti_custom_params);
        if ($success) {
            echo "<h2>" . __('Membership synchronized sucessfully', self::$DOMAIN) . '</h2>';
            echo "<h3>" . sprintf(__('%d Total users', self::$DOMAIN), count($this->members_result)) . '</h3>';
            foreach ($this->members_result as $members_result) {
                echo $members_result;
            }
        } else {
            echo "<h2>" . __('Error executing membership service', self::$DOMAIN) . '</h2>';
            foreach ($this->error as $error) {
                echo "<p>" . $error . "</p>";
            }
        }
    }

    public function ltidoMembership(
        $client_id,
        $custom_params,
        $lti_user_id_to_check = false
    )
    {
        $this->error = array();
        $success = false;
        $launch = get_user_meta(get_current_user_id(), 'lti_launch_' . get_current_blog_id(), true);

        if ($launch && $launch->has_nrps()) {

            $lti_conf = LTIUtils::lti_get_by_client_id($client_id);
            $student_role = false;
            if (isset($lti_conf)) {
                $student_role = $lti_conf->student_role;
            }
            $this->members_result = [];
            $members = $launch->get_nrps()->get_members();
            if ($members) {
                $blogType = new blogTypeLoader(isset($custom_params['blogtype']) ? $custom_params['blogtype'] : 'defaultType');
                $overwrite_roles = isset($custom_params[OVERWRITE_ROLES]) ? $custom_params[OVERWRITE_ROLES] : true;
                $success = true;
                foreach ($members as $member) {
                    $lti_user_id = $member['user_id'];
                    $userkey = LTIUtils::getUserkeyLTI($client_id, $lti_user_id, $custom_params);
                    $firstname = apply_filters('lti_get_given_name', $member['given_name'] ?? '', $userkey);
                    $lastname = apply_filters('lti_get_family_name', $member['family_name'] ?? '', $userkey);
                    $email = apply_filters('lti_get_email', $member['email'] ?? '', $userkey);
                    $name = apply_filters('lti_get_name', $member['name'] ?? '', $userkey);

                    $user_creted = false;
                    if (!empty($email)) {
                        $uinfo = get_user_by('login', $userkey);
                        $user_data = [
                            'user_login' => $userkey,
                            'user_nicename' => $userkey,
                            'first_name' => $firstname,
                            'last_name' => $lastname,
                            'user_email' => $email,
                            'display_name' => $name
                        ];
                        if (!isset($uinfo) || $uinfo == false) {
                            $user_data['user_pass'] = wp_generate_password(10, true, true);
                            $user_data['user_url'] = 'http://';

                            $ret_id = wp_insert_user($user_data);

                            if (is_wp_error($ret_id)) {
                                $this->members_result[] = '<div class="notice notice-error">' . $name . ' (' . $email . '): ' . __('Error creating user', self::$DOMAIN) . ' - ' . $ret_id->get_error_message() . '</div>';
                            } else {
                                $this->members_result[] = '<div class="notice notice-success">' . $name . ' (' . $email . '): ' . __('Created', self::$DOMAIN) . '</div>';
                            }
                            $uinfo = get_user_by('login', $userkey);
                            $user_creted = true;

                        } else {
                            $user_data['ID'] = $uinfo->ID;
                            $ret_id = wp_insert_user($user_data);
                            if (is_wp_error($ret_id)) {
                                $this->members_result[] = '<div class="notice notice-error">' . $name . ' (' . $email . '): ' . __('Error updating user', self::$DOMAIN) . ' - ' . $ret_id->get_error_message() . '</div>';
                            } else {
                                $this->members_result[] = '<div class="notice notice-success">' . $name . ' (' . $email . '): ' . __('Updated', self::$DOMAIN) . '</div>';
                            }
                        }
                        update_user_meta($uinfo->ID, self::$LTI_METAKEY_USER_ID, $lti_user_id);

                        if ($uinfo &&
                            ((is_multisite() && !is_user_member_of_blog($uinfo->ID,
                                    get_current_blog_id())) ||
                                $overwrite_roles ||
                                $user_creted ||
                                $lti_user_id_to_check !== false)) {
                            $role = $blogType->roleMapping($member['roles'], $student_role);
                            if (is_multisite()) {
                                if (is_user_member_of_blog($uinfo->ID)) {
                                    remove_user_from_blog($uinfo->ID, get_current_blog_id());
                                }
                                add_user_to_blog(get_current_blog_id(), $uinfo->ID, $role);
                            } else {
                                wp_update_user(array('ID' => $uinfo->ID, 'role' => $role));
                            }
                            if ($lti_user_id_to_check === $lti_user_id) {
                                $success = $role;
                            }
                        }
                    }
                }
            } else {
                $this->error[] = __('Service doesn\'t return a valid member document', self::$DOMAIN);
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
    )
    {
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
                $lti_user_id = get_user_meta($userid, self::$LTI_METAKEY_USER_ID, true);
                $launch = get_user_meta(get_current_user_id(), 'lti_launch_' . get_current_blog_id(), true);
                if ($lti_user_id && $launch && $launch->has_ags()) {

                    $grades = $launch->get_ags();
                    $score = LTI\LTI_Grade::new()
                        ->set_score_given($grade)
                        ->set_score_maximum(self::$MAX_GRADE)
                        ->set_timestamp(date(DateTime::ISO8601))
                        ->set_activity_progress(self::$ACTIVITY_PROGRESS)
                        ->set_grading_progress(self::$GRADING_PROGRESS)
                        ->set_comment($comment)
                        ->set_user_id($lti_user_id);

                    // TODO define tag and label
                    $tag = 'score';
                    $label = 'Score';
                    $score_lineitem = LTI\LTI_Lineitem::new()
                        ->set_tag($tag)
                        ->set_score_maximum(self::$MAX_GRADE)
                        ->set_label($label)
                        ->set_start_date_time('')
                        ->set_end_date_time('')
                        ->set_resource_id($launch->get_launch_data()['https://purl.imsglobal.org/spec/lti/claim/resource_link']['id']);

                    $success = $grades->put_grade($score, $score_lineitem);

                    $ret['result'] = $success;
                    if (!$success) {
                        $ret['error'] = __('Error storing grade on platform', self::$DOMAIN);
                    }
                } else {
                    $ret['result'] = false;
                    $ret['error'] = __('Don\'t allow to send back grades. Review LMS configuraton', self::$DOMAIN);
                }

            } else {
                $ret['result'] = false;
                $ret['error'] = __('Missing user id or grade', self::$DOMAIN);
            }
        }
        echo json_encode($ret);

        wp_die(); // this is required to terminate immediately and return a proper response
    }

    /**
     * Return an instance of the class
     *
     * Return an instance of the LTI Grades Management Class.
     *
     * @return LTIAdvantageManagement class instance.
     * @since 1.0.0
     * @access public static
     *
     */
    public static function get_instance()
    {
        if (null == self::$instance) {
            self::$instance = new self;
        }
        return self::$instance;
    } //end get_instance

}

add_action('plugins_loaded', 'lti_advantagemanagement_instantiate');
$lti_advantagemanagement = null;
function lti_advantagemanagement_instantiate()
{
    global $lti_advantagemanagement;
    $lti_advantagemanagement = LTIAdvantageManagement::get_instance();
    return $lti_advantagemanagement;
}



