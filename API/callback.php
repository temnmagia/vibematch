<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/jwt_helper.php';

if (!isset($_GET['code'])) {
    die("Помилка: code відсутній");
}

$code          = $_GET['code'];
$code_verifier = $_SESSION['code_verifier'] ?? null;

if (!$code_verifier) {
    die("Помилка: відсутній code_verifier");
}

// --- Обмін code на токени ---
$ch = curl_init('https://accounts.spotify.com/api/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'authorization_code',
        'code'          => $code,
        'redirect_uri'  => SPOTIFY_REDIRECT_URI,
        'client_id'     => SPOTIFY_CLIENT_ID,
        'code_verifier' => $code_verifier,
    ]),
]);
$result = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!isset($result['access_token'])) {
    die("Помилка: не вдалося отримати токен. Відповідь: " . json_encode($result));
}

$access_token      = $result['access_token'];
$refresh_token     = $result['refresh_token'] ?? null;
$token_expires_at  = time() + ($result['expires_in'] ?? 3600);

// --- Дані користувача зі Spotify ---
$ch = curl_init('https://api.spotify.com/v1/me');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer $access_token"],
]);
$userData = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!isset($userData['email'])) {
    die("Помилка: не вдалося отримати email");
}

$email = $userData['email'];
$name  = $userData['display_name'] ?? 'NoName';

// --- Upsert користувача ---
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $pdo->prepare(
        "UPDATE users SET spotify_token = ?, spotify_refresh_token = ?, spotify_token_expires = ? WHERE id = ?"
    )->execute([$access_token, $refresh_token, $token_expires_at, $userId]);
} else {
    $pdo->prepare(
        "INSERT INTO users (name, email, spotify_token, spotify_refresh_token, spotify_token_expires, created_at)
         VALUES (?, ?, ?, ?, ?, NOW())"
    )->execute([$name, $email, $access_token, $refresh_token, $token_expires_at]);
    $userId = (int) $pdo->lastInsertId();
}

// --- Видаємо JWT cookie ---
$jwt = generate_jwt($userId, $email);
setcookie('auth_token', $jwt, time() + JWT_TTL, COOKIE_PATH, COOKIE_DOMAIN, COOKIE_SECURE, true);

// --- Очищуємо сесію ---
unset($_SESSION['code_verifier']);

header("Location: index.php");
exit();
