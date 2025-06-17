<?php
session_start();
require_once 'db_connect.php'; // підключення до бази

if (!isset($_GET['code'])) {
    die("Помилка: code відсутній");
}

$client_id = 'ff991d6f6c224110be45fa35a875b85b';
$redirect_uri = 'http://127.0.0.1:80/vibematch/API/callback.php';

$code = $_GET['code'];
$code_verifier = $_SESSION['code_verifier'] ?? null;

if (!$code_verifier) {
    die("Помилка: відсутній code_verifier");
}

$data = [
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $redirect_uri,
    'client_id' => $client_id,
    'code_verifier' => $code_verifier
];

$ch = curl_init('https://accounts.spotify.com/api/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);

if (!isset($result['access_token'])) {
    die("Помилка: не вдалося отримати токен");
}

$_SESSION['spotify_token'] = $result;
unset($_SESSION['code_verifier']);

// 🟣 ДОДАНО: отримати інфу про користувача з Spotify
$access_token = $result['access_token'];

$ch = curl_init('https://api.spotify.com/v1/me');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $access_token"
]);
$userResponse = curl_exec($ch);
curl_close($ch);

$userData = json_decode($userResponse, true);

if (!isset($userData['email'])) {
    die("Помилка: не вдалося отримати email користувача");
}

$email = $userData['email'];
$name = $userData['display_name'] ?? 'NoName';

// 🟣 ДОДАНО: записати або оновити користувача в БД
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $stmt = $pdo->prepare("UPDATE users SET spotify_token = ? WHERE id = ?");
    $stmt->execute([$access_token, $userId]);
} else {
    $stmt = $pdo->prepare("INSERT INTO users (name, email, spotify_token, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$name, $email, $access_token]);
    $userId = $pdo->lastInsertId();
}

// 🟣 ДОДАНО: зберігаємо user_id в сесію
$_SESSION['user_id'] = $userId;

header("Location: index.php");
exit();
