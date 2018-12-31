<?php
/**
 * @name Interface blogType
 * @abstract Interface of blogType
 * @author Antoni Bertran (abertranb@uoc.edu)
 * @copyright 2010 Universitat Oberta de Catalunya
 * @license GPL
 * @version 1.0.0
 * Date December 2010
*/

interface blogType
{
	/**
	 *
	 * Get the name of course, if you want you can overwrite this method in your new plugin to customize the course name.
	 * @param LTI Object $blti
	 */
	public function getCourseName($blti);
	/**
	 *
	 * Gets the path of blog (URL to site)
	 * @param JWT Body $jwt_body
	 * @param unknown_type $domain
	 */
	public function getCoursePath($jwt_body, $domain);
	/**
	 *
	 * Set the Language to the blog, as default is using the launch_presentation_locale
	 */
	public function setLanguage();


    /**
     * @return mixed
     */
	public function getLanguage();


	/**
	 *
	 * Change the theme to the blog
	 */
    public function changeTheme();
    /**
     *
     * Indicate the list of plugins to load
     */
    public function loadPlugins();
    /**
     *
     * Returns the role from LTI to Wordpress
     * @param String $roles
     * @param String $student_role this role can be overwrited by default configuration
	 */
    public function roleMapping($roles, $student_role);
    /**
     * This function contains the last actions before show blog
     */
    public function postActions($obj);

    /**
     *
     * To check if user is in the course
     */
    public function requires_user_authorized();

    /**
     *
     * To check if user is in the course
     */
    public function isAuthorizedUserInCourse($roles);


}
?>
