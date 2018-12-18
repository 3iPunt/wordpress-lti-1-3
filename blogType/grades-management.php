<?php
/*
Version: 0.0.1
Author: Antoni Bertran
Author URI: http://www.tresipunt.com
License: GPLv2 Copyright (c) 2018
*/

define('PORTAFOLIS_UOC_DEBAT_DIR', dirname(__FILE__)); //an absolute path to this directory
class LTIGradesManagement
{
    private static $instance = null;
    public static $DOMAIN = 'wordpress-mu-ltiadvantage';
    public static $CAPABILITY_EDITOR_ROLE = 'delete_others_pages';
    private $client = null;
    private $client_id;
    private $lti_issuer;
    private $lti_deployment_id;
    private $lti_custom_params;
    private $lti_namesroleservice;

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

            }
        }
    }

    function admin_add_css_js()
    {
        wp_register_script('grades-management-js-admin', plugins_url('grades-management.js', __FILE__),
            array('jquery'), '20180917');
        wp_enqueue_script('grades-management-js-admin');
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

        $current_classroom_id = uoc_create_site_is_classroom_blog();
        if ($current_classroom_id != null) {
            global $openIDUOC;
            if ($openIDUOC) {
                $uocApi = $openIDUOC->getUocAPI();

                if (!empty($_POST['action'])) {
                    check_admin_referer('lti_grades_management_edit');
                    switch ($_POST['action']) {
                        case "save":
                            $board = $_POST['board'];
                            $folder = $_POST['folder'];
                            $c = uoc_create_site_get_subject_from_db($current_classroom_id);
                            if ($board != $c->forumId) {
                                $folder = '';
                            }
                            uoc_create_site_update_subject_forum($current_classroom_id, $board, $folder);
                            break;
                    }
                } elseif (!empty($_POST['action_create'])) {
                    check_admin_referer('lti_grades_management_edit_create');
                    $c = uoc_create_site_get_subject_from_db($current_classroom_id);
                    $parentFolderId = $_POST['parentFolderId'];
                    $folder_name = empty($_POST['folder_name']) ? false : $_POST['folder_name'];
                    if (!$folder_name) {
                        wp_die(__("You have to indicate the folder name", self::$DOMAIN));
                    }
                    $uocApi->create_folder(get_current_user_id(), $current_classroom_id, $c->forumId, $parentFolderId,
                        $folder_name);
                }


                $uocApi = $openIDUOC->getUocAPI();
                $boards = $uocApi->getClassroomForums($current_classroom_id, get_current_user_id());

                $user = $uocApi->get_user(get_current_user_id());
                if (!$boards || $user == null || $user->getId() == null) {

                    global $openIDUOC;
                    if (class_exists('OpenID_Connect_Generic') && $openIDUOC) {
                        wp_die(sprintf(__("You have to <a href=\"%s\" target='_blank'>reauthenticate</a>, then reload the current page",
                            self::$DOMAIN), $openIDUOC->getClientWrapper()->get_authentication_url()));
                    }
                    wp_die("Can't get the api information, try again later", self::$DOMAIN);
                }

                $c = uoc_create_site_get_subject_from_db($current_classroom_id);
                $this->lti_grades_management_edit($boards->getBoards(), $uocApi, $current_classroom_id, $c->forumId,
                    $c->folderId);
            }
        } else {
            wp_die('This is not a classroom portfolio', self::$DOMAIN);
        }
    }

    public function  lti_grades_management_syncmembers() {

        if (!current_user_can(self::$CAPABILITY_EDITOR_ROLE)) {
            return false;
        }

        ob_start();
        ?>
        <div id="grades-management-membership">
            <img class="waiting" src="<?php echo  esc_url(admin_url('images/wpspin_light-2x.gif')) ?>"  /><?php  _e('Loading Memberships',
                self::$DOMAIN) ?></div>
        <?php
        ob_flush();
        flush();

        $auth_url = $this->client->auth_token_url;
        //TODO we need to stopre
        $success = ltidoMembership($this->lti_issuer, $this->client_id, $auth_url, $this->client->private_key,
            $this->lti_namesroleservice,
            $this->lti_deployment_id,
            $this->lti_custom_params);
        echo "Done! ".$success;
    }

    private function lti_grades_management_edit(
        $boardList,
        $uocApi,
        $classroom_id,
        $board_id = null,
        $folder_id = null
    ) {
        $folders = array();
        if ($board_id != null) {
            echo "<h2>" . __('Edit Debate configuration', self::$DOMAIN) . "</h2>";
        } else {
            echo "<h2>" . __('New Debate configuration', self::$DOMAIN) . "</h2>";
        }

        $options = '<option>' . __('Select one', self::$DOMAIN) . '</option>';
        $allowed_board_sub_types = array('WKGRP_FO', 'WKGRP_DE');
        foreach ($boardList as $board) {
            if (in_array($board->getSubtype(), $allowed_board_sub_types)) {
                $selected = $board->getId() == $board_id ? 'selected' : '';
                $options .= '<option value="' . $board->getId() . '" ' . $selected . '>' . $board->getTitle() . '</option>';
            }
        }

        echo "<form method='POST'><input type='hidden' name='action' value='save' />";
        wp_nonce_field('lti_grades_management_edit');
        echo "<table class='form-table'>\n";
        echo "<tr><th>" . __('Select Board', self::$DOMAIN) .
            " * " .
            "</th><td><select id=\"portafolis-uoc-debats-board\" name=\"board\">" . $options . "</select></td></tr>\n";
        if ($board_id != null) {
            $options_folder = '<option>' . __('Select one', self::$DOMAIN) . '</option>';
            $folders = $uocApi->get_folders(get_current_user_id(), $classroom_id, $board_id);
            foreach ($folders->getFolders() as $folder) {
                $selected = $folder->getId() == $folder_id ? 'selected' : '';
                $options_folder .= '<option value="' . $folder->getId() . '" ' . $selected . '>' . $folder->getName() . '</option>';
            }

            echo "<tr><th>" . __('Select Folder', self::$DOMAIN) .
                " * " .
                "</th><td><select id=\"portafolis-uoc-debats-folder\" name=\"folder\">" . $options_folder . "</select></td></tr>\n";

        }
        echo "</table>";
        echo "<small>*" . __('You have to select board and folder to finish configuration', self::$DOMAIN) . "</small>";
        echo "<p><input type='submit' class='button-primary' value='" . __('Save',
                self::$DOMAIN) . "' /></p></form><br />";


        if ($board_id != null) {
            echo "<form method='POST'><input type='hidden' name='action_create' value='save' />";
            wp_nonce_field('lti_grades_management_edit_create');
            echo "<table class='form-table'>\n";
            $options_folder = '';
            foreach ($folders->getFolders() as $folder) {
                $selected = '';
                $options_folder .= '<option value="' . $folder->getId() . '" ' . $selected . '>' . $folder->getName() . '</option>';
            }
            echo "<tr><th>" . __('Folder name', self::$DOMAIN) .
                "</th><td><input type=\"text\" name=\"folder_name\"  size=\"20\"/></td></tr>\n";
            echo "<tr><th>" . __('Select Parent Folder', self::$DOMAIN) .
                "</th><td><select name=\"parentFolderId\">" . $options_folder . "</select></td></tr>\n";
            echo "</table>";
            echo "<p><input type='submit' class='button-primary' value='" . __('Create Folder',
                    self::$DOMAIN) . "' /></p></form><br />";
        }
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
} //end portafolis_uocdebats_instantiate
