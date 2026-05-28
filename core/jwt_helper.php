<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function generate_jwt(int $userId, string $email): string {
    $now = time();
    $payload = [
        'iss'  => JWT_ISSUER,
        'aud'  => JWT_AUDIENCE,
        'iat'  => $now,
        'nbf'  => $now,
        'exp'  => $now + JWT_TTL,
        'data' => [
            'userId' => $userId,
            'email'  => $email,
        ],
    ];
    return JWT::encode($payload, JWT_SECRET_KEY, 'HS256');
}

function validate_jwt(?string $token): ?object {
    if (!$token) return null;
    try {
        return JWT::decode($token, new Key(JWT_SECRET_KEY, 'HS256'));
    } catch (Exception) {
        return null;
    }
}
