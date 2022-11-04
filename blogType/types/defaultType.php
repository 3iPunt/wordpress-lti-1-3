<?php
/**
 * @name Plugin  defaultType
 * @abstract Plugin to implement blogType and modify wordpress for any blogType, except defined in files
 * @author Antoni Bertran (abertranb@uoc.edu)
 * @copyright 2010 Universitat Oberta de Catalunya
 * @license GPL
 * @version 1.0.0
 * Date December 2010
*/

require_once(dirname(__FILE__).DIRECTORY_SEPARATOR.'blogType.php');

class defaultType implements blogType {

	private $configuration = null;
	private $language_wp = null;
	private $requires_user_authorized = true;

	/**
	 * Gets the course name
	 * @see blogType::getCourseName()
	 */
	public function getCourseName($jwt_body) {
	    $title = $context_id = $jwt_body["https://purl.imsglobal.org/spec/lti/claim/context"]["title"];
		return $title;
	}

	/**
	 * get the course path
	 * @see blogType::getCoursePath()
	 */
	public function getCoursePath($jwt_body) {
        $context_id = $jwt_body["https://purl.imsglobal.org/spec/lti/claim/context"]["id"];
        $client_id = is_array($jwt_body['aud']) ? $jwt_body['aud'][0] : $jwt_body['aud'];

	    $course = str_replace(':','-', $client_id.'-'.$context_id);  // TO make it past sanitize_user
		return $course;


	}

	/**
	 *
	 * @see blogType::getMetaBlog()
	 */
	public function getMetaBlog($jwt_body){

		$langid = $jwt_body['locale'];
		switch ($langid)
		{
			case "ca-ES":
				$lang="ca";
				$this->language_wp="ca";
				break;
			case "es-ES":
				$lang="es_ES";
				$this->language_wp="es_ES";
				break;
			case "fr-FR":
				$lang="fr_FR";
				$this->language_wp="fr_FR";
				break;
			case "ir-IR":
				$lang="ir_IR";
				$this->language_wp="ir_IR";
				break;
			case "nl-FR":
				$lang="nl_NL";
				$this->language_wp="nl_NL";
				break;
			case "pl-PL":
				$lang="pl_PL";
				$this->language_wp="pl_PL";
				break;
			case "sv-SE":
				$lang="sv_SE";
				$this->language_wp="sv_SE";
				break;
			default:
				$lang="en_EN";
				$this->language_wp="en_EN";
			}

			$meta = apply_filters('signup_create_blog_meta', array ('lang_id' => $lang, 'public' => 0)); //deprecated

			return $meta;

		}

		/**
		 *
		 * @see blogType::setLanguage()
		 */
		public function setLanguage(){
		    return update_site_option( 'WPLANG', $this->language_wp );
		}
		/**
		 *
		 * @see blogType::getLanguage()
		 */
		public function getLanguage(){
		    return $this->language_wp ;
		}


	/**
	 *
	 * @see blogType::changeTheme()
	 */
	public function changeTheme() {

	}

	/**
	 *
	 * @see blogType::loadPlugins()
	 */
	public function loadPlugins() {

	}

	/**
	 *
	 * @see blogType::roleMapping()
	 */
	public function roleMapping($roles, $student_role=false) {

		//if ($this->check_role_contains("Administrator", $roles)) return "administrator";
	    if ($this->check_role_contains("Instructor", $roles)) return "editor";
	    elseif ($this->check_role_contains("Learner", $roles)) return $student_role?$student_role:"subscriber";
	    else return "subscriber";

	}

    private function check_role_contains($stringtocheck, $roles)
    {
        foreach ($roles as $name) {
            if (stripos($name, $stringtocheck) !== FALSE) {
                return true;
            }
        }
    }

	/**
	 *
	 * @see blogType::postActions()
	 */
    public function postActions($obj) {

    }

    /**
     *
     * Shows error if exists
     * @param unknown_type $blog_id
     * @param unknown_type $path
     */
    public function checkErrorCreatingBlog($blog_id, $path) {
        if (is_wp_error($blog_id)) {
            $data = intval( $blog_id->get_error_data() );
            if ( ! empty( $data ) ) {
                wp_die( '<p>' . $blog_id->get_error_message() . '</p>', __( 'Blog creation Failure', 'wordpress-mu-ltiadvantage' ), array( 'response' => $data, 'back_link' => true ) );
            } else {
                exit;
            }
        }
    }

    /**
    * Remove the plugins loaded as default
     * @param array $arrayPlugins
    */
    public function removeActivedPlugins($arrayPlugins) {
	    $array = array();
	    foreach ($arrayPlugins as $plugin) {
	    if (!is_plugin_active($plugin))
	    	$array[] = $plugin;
	    }
    	return $array;
    }

    /**
     *
     * To check if user is in the course
     */
    public function requires_user_authorized() {
        return $this->requires_user_authorized;
    }

    /**
     *
     * To check if user is in the course
     */
    public function isAuthorizedUserInCourse($roles) {
	$auth = false;

 	if (in_array("http://purl.imsglobal.org/vocab/lis/v2/institution/person#Administrator", $roles)
            || in_array("http://purl.imsglobal.org/vocab/lis/v2/institution/person#Instructor", $roles)
	    || in_array("http://purl.imsglobal.org/vocab/lis/v2/membership#Learner", $roles))    	    {
	 $auth = true;
	}
	return $auth;
    }

    /**
     * @$custom_params array
     * Force to redirect if returns false don't redirect
     */
    public function force_redirect_to_url($custom_params) {
        return false;
    }
}
