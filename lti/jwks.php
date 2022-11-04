<?php
require_once file_exists(__DIR__ . '/../../../wp-config.php') ? __DIR__ . '/../../../wp-config.php' : __DIR__ . '/../../../../wp-config.php';
require_once __DIR__ . '/../ims-lti-advantage.php';

use \IMSGlobal\LTI;

$kid = $_GET['kid'] ?? false;
$rows = lti_13_get_tools_with_priv_key($kid);
$keys = [];
foreach ($rows as $row) {
    $keys[$row->client_id] = $row->private_key;
}
LTI\JWKS_Endpoint::new($keys)->output_jwks();