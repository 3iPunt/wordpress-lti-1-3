<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wordpresslti_database.php';
require_once __DIR__ . '/../../../wp-config.php';

use \IMSGlobal\LTI;

LTI\LTI_OIDC_Login::new(new WordPressLTI_Database())
    ->do_oidc_login_redirect(plugin_dir_url( __FILE__ ) . "launch.php")
    ->do_redirect();