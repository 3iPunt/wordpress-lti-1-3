<?php
require_once('keys.php');
require_once 'jwt/src/BeforeValidException.php';
require_once 'jwt/src/ExpiredException.php';
require_once 'jwt/src/SignatureInvalidException.php';
require_once 'jwt/src/JWT.php';
require_once 'jwt/src/JWK.php';

use \Firebase\JWT\JWT;

function get_access_token($iss, $client_id, $auth_url, $tool_private_key, $scopes) {
    // Start auth fetching

    // Build up JWT to exchange for an auth token
    //$auth_url = $_SESSION['auth_token_urls'][$_SESSION['current_request']['iss'].':'.$_SESSION['current_request']['aud']];
    $jwt_claim = [
            "iss" => $iss,
            "sub" => $client_id,
            "aud" => $auth_url,
            "iat" => time(),
            "exp" => time()+600,
            "jti" => uniqid("testing")
    ];

    // Sign the JWT with our private key (given by the platform on registration)
    $jwt = JWT::encode($jwt_claim, $tool_private_key, 'RS256');

    // Build auth token request headers
    $auth_request = [
        'grant_type' => 'client_credentials',
        'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
        'client_assertion' => $jwt,
        'scope' => implode(' ', $scopes)
    ];

    // Make request to get auth token
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $auth_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($auth_request));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $ret = curl_exec($ch);
    $token_data = json_decode($ret, true);
    curl_close ($ch);
    if (!isset($token_data['access_token'])) {
        echo "<p>url $auth_url</p>";
        print_r($ret);
    }

    return $token_data['access_token'];
}