<?php
require_once '../vendor/autoload.php'; // Path to your composer autoload
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

define('JWT_SECRET_KEY', 'trafiuksuperduperkruta');
define('JWT_ISSUER', 'http://127.0.0.1:80'); // Your domain
define('JWT_AUDIENCE', 'http://127.0.0.1:80'); // Your domain

function generate_jwt($userId, $email) {
    $issuedAt = time();
    $expirationTime = $issuedAt + (86400 * 30); // Token valid for 30 days
    
    $payload = [
        'iss' => JWT_ISSUER,
        'aud' => JWT_AUDIENCE,
        'iat' => $issuedAt,
        'nbf' => $issuedAt,
        'exp' => $expirationTime,
        'data' => [
            'userId' => $userId,
            'email' => $email
        ]
    ];

    return JWT::encode($payload, JWT_SECRET_KEY, 'HS256');
}

function validate_jwt($token) {
    if (!$token) {
        return null;
    }
    try {
        $payload = JWT::decode($token, new Key(JWT_SECRET_KEY, 'HS256'));
        return $payload;
    } catch (Exception $e) {
        // Token is invalid (expired, wrong signature, etc.)
        return null;
    }
}
?>