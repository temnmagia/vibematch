<?php
require_once 'jwt_helper.php';

$CURRENT_USER_ID = null;
$CURRENT_USER_EMAIL = null;
$IS_LOGGED_IN = false;

// Отримуємо токен з HttpOnly cookie
$token = $_COOKIE['auth_token'] ?? null;
$payload = validate_jwt($token);

if ($payload && isset($payload->data)) {
    // Токен дійсний! Встановлюємо глобальні змінні
    $CURRENT_USER_ID = $payload->data->userId;
    $CURRENT_USER_EMAIL = $payload->data->email;
    $IS_LOGGED_IN = true;
}
?>