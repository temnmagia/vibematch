<?php
require_once __DIR__ . '/jwt_helper.php';

$CURRENT_USER_ID    = null;
$CURRENT_USER_EMAIL = null;
$IS_LOGGED_IN       = false;

$token   = $_COOKIE['auth_token'] ?? null;
$payload = validate_jwt($token);

if ($payload && isset($payload->data)) {
    $CURRENT_USER_ID    = (int) $payload->data->userId;
    $CURRENT_USER_EMAIL = $payload->data->email;
    $IS_LOGGED_IN       = true;
}

/**
 * Примусово вимагає автентифікацію.
 * Якщо не залогінений — редіректить на index.php.
 */
function require_auth(): void {
    global $IS_LOGGED_IN;
    if (!$IS_LOGGED_IN) {
        header('Location: index.php');
        exit();
    }
}

/**
 * Знищує сесію та видаляє auth_cookie.
 */
function logout(): void {
    setcookie('auth_token', '', time() - 3600, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, true);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    header('Location: ../public/index.php');
    exit();
}
