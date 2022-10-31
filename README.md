# Wordpress-lti-1-3
This is an implementation of LTI 1.3 Advantage on Wordpress

This code was developed during the IMS Europe Summit 2018 thanks to [James Rissler](https://github.com/jrissler) and [Martin Lenord](https://github.com/MartinLenord). We are using the PHP Library [https://github.com/IMSGlobal/lti-1-3-php-library](https://github.com/IMSGlobal/lti-1-3-php-library)

Tested with Wordpress 4.9.X and 5.0.2

## Features
* SSO
* Enabled membership role
* Grades Management


## Single or Multisite Wordpress
You can use this plugin in a single Wordpress but the interesting use case is a Wordpress Multisite because each course will have a site.

## Enabling Network
to use a multisite you can follow the instructions [https://codex.wordpress.org/Create_A_Network](https://codex.wordpress.org/Create_A_Network) 

Then deploy the code in folder wp-content/mu-plugins (create folder if not exist) then this plugin is enabled in all Wordpress sites.

The structure will be:
``
wp-content/mu-plugins/ims-lti-advantage.php
``

## Configure a keys
As superadmin you can go to Options -> LTI Clients then you can manage the client details

The parameters are:

* Client id: the id of the client who requested the token (Audience)
* Key set url: The url where the public key is set (well-known/jwks URL)
* Auth token url: The url to consume the Membershipt and Outcomes service
* Custom username parameter: a custom parameter to create a custom username instead use the issuer + "_"+ client_id + "_" + "deployment_id" + "user_id"
* Has custom username: boolean to enable custom username
* Enable: Check if tool is enabled or not
* Enable grade: As default all clients allows grades
* Student role: the student can be map as subscriber or author on Wordpress (Read about it on https://codex.wordpress.org/Roles_and_Capabilities#Subscriber)
* You can generate the public and private key, the public key can be set on platform to get membership users

_To create a Platform private and public key you can use ssh-keygen, search on internet how to do that, you will need the private and public key._  



## Actions

This plugin allows to add some filters and actions

### apply_filters

LTI 1.3 allows to share name and email information:

* _given_name_: Per OIDC specifcations, given name(s) or first name(s) of the End-User. Note that in some cultures, people can have multiple given names; all can be present, with the names being separated by space characters.
* _family_name_: Per OIDC specifcations, surname(s) or last name(s) of the End-User. Note that in some cultures, people can have multiple family names or no family name; all can be present, with the names being separated by space characters.
* _name_: Per OIDC specifcations, end-User's full name in displayable form including all name parts, possibly including titles and suffixes, ordered according to the End-User's locale and preferences.
* _email_: Per OIDC specifcations, end-User's preferred e-mail address.

The Wordpress-lti-1-3 allows to change this value and it is extremely useful when this data is not shared by the Platform (usally an LMS) for anonymization requirements. 

The plugin provides 4 filters to change the value and you can customize like this

The filters are:
```php
        $given_name = apply_filters('lti_get_given_name', $lti_data['given_name'] ?? '', $userkey);
        $family_name =  apply_filters('lti_get_family_name', $lti_data['family_name'] ?? '', $userkey);
        $email = apply_filters('lti_get_email', $lti_data['email'] ?? '', $userkey);
        $name = apply_filters('lti_get_name', $lti_data['name'] ?? '', $userkey);        
```

Then on filters.php has the default implementation of each one and only do anything if the content is empty, for that reason you can add your custom filter with less priority (currently is 99)

Default implementation are:
````php
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
````




## Next steps

* Certificate it! 