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

* Client id: 
* Key set url:
* Auth token url:
* Custom username parameter
* Has custom username
* Enable
* Tool public key





