<?php

class LTIUtils {

    public static function lti13_check_nonce($client_id, $nonce)
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
     *
     * Gets the registered the parameter custom_userkey or standar userkey
     * @param $client_id
     * @param $lti_user_id
     * @param $custom_params
     * @return mixed|string
     */
    public static function getUserkeyLTI($client_id, $lti_user_id, $custom_params)
    {
        //Userkey mus have issuer id $jwt_body['iss'] + auid $jwt_body['aud'] + deploymentbody ['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] $jwt_ + subject $jwt_body['sub']
        $userkey = $client_id . '-' . $lti_user_id;
        $username_param = self::lti_get_username_parameter_from_client_id($client_id);
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
    public static function lti_get_username_parameter_from_client_id($client_id)
    {
        lti_maybe_create_db();
        $custom_username_parameter = null;
        $row = self::lti_get_by_client_id($client_id);
        if (isset($row) && $row->has_custom_username_parameter == 1) {
            $custom_username_parameter = $row->custom_username_parameter;
        }
        return $custom_username_parameter;
    }


    /**
     * Returns the translated role of the current user. If that user has
     * no role for the current blog, it returns false.
     *
     * @return string The name of the current role
     * */
    public static function get_current_user_role($userId)
    {
        $user = new WP_User($userId);
        $roles = $user->roles; //xget_usermeta($userId, 'wp_capabilities');
        $role = array_shift($roles);
        return $role;
    }


    public static function lti_get_by_client_id($client_id)
    {
        global $wpdb;
        lti_maybe_create_db();

        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . lti_13_get_table() . " WHERE client_id = %s",
            $client_id));
        return $row;
    }
}