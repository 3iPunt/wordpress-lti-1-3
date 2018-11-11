# Wordpress-lti-1-3
This is a implementation of LTI 1.3 Advantage on Wordpress

This code was developed during the IMS Europe Summit 2018 thanks to [James Rissler](https://github.com/jrissler) and [Martin Lenord](https://github.com/MartinLenord). We are using the PHP Library [https://github.com/IMSGlobal/lti-1-3-php-library](https://github.com/IMSGlobal/lti-1-3-php-library)

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
* You can generate the public and private key, the public key can be set on platform to get membership users 

## Next steps

* Create a cron job to consume membership
* Allow to assess students and return this grade to platform 
* Store user role on membership
* Certificate it! 





