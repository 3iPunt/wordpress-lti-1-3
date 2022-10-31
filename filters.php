<?php

add_filter('lti_get_given_name', 'lti_filter_get_given_name', 99, 2);
function lti_filter_get_given_name($given_name, $userkey)
{
    if (empty($given_name)) {
        $given_name = $userkey;
    }
    return $given_name;
}

add_filter('lti_get_family_name', 'lti_filter_get_family_name', 99, 2);
function lti_filter_get_family_name($family_name, $userkey)
{
    if (empty($family_name)) {
        $family_name = $userkey;
    }
    return $family_name;
}

add_filter('lti_get_name', 'lti_filter_get_name', 99, 2);
function lti_filter_get_name($name, $userkey)
{
    if (empty($name)) {
        $name = $userkey;
    }
    return $name;
}

add_filter('lti_get_email', 'lti_filter_get_email', 99, 2);
function lti_filter_get_email($email, $userkey)
{
    if (empty($email)) {
        $email = $userkey . '@nomail.com';
    }
    return $email;
}
