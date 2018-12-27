<?php
/*
Version: 0.0.1
Author: Antoni Bertran
Author URI: http://www.tresipunt.com
License: GPLv2 Copyright (c) 2018
*/

define('PORTAFOLIS_UOC_DEBAT_DIR', dirname(__FILE__)); //an absolute path to this directory
require_once(dirname(__FILE__).'/class-lti-grade-table.php');
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

        $gradesListTable = new LTI_Grade_Table();
        $gradesListTable->prepare_items();
        ?>
        <div class="wrap">
            <div id="icon-users" class="icon32"></div>
            <h2><?php _e('Student List', self::$DOMAIN)?></h2>
            <?php $gradesListTable->display(); ?>
        </div>
        <?php
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



