<?php
session_start(); // Все ще потрібно для $_SESSION['code_verifier']
require_once 'db_connect.php';
require_once 'jwt_helper.php'; 

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
    die("Помилка: не вдалося отримати токен. Відповідь Spotify: " . $response);
}

// ✅ Зберігаємо всю інформацію про токен
$access_token = $result['access_token'];
$refresh_token = $result['refresh_token'] ?? null; // Отримуємо refresh token
$expires_in = $result['expires_in'] ?? 3600;     // Отримуємо час життя
$token_expires_at = time() + $expires_in;        // Обчислюємо час закінчення

// 🟣 Отримати інфу про користувача з Spotify
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

// 🟣 ОНОВЛЕНО: Записати або оновити користувача та токени в БД
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
$userId = $stmt->fetchColumn();

if ($userId) {
    $stmt = $pdo->prepare(
        "UPDATE users SET spotify_token = ?, spotify_refresh_token = ?, spotify_token_expires = ? WHERE id = ?"
    );
    $stmt->execute([$access_token, $refresh_token, $token_expires_at, $userId]);
} else {
    $stmt = $pdo->prepare(
        "INSERT INTO users (name, email, spotify_token, spotify_refresh_token, spotify_token_expires, created_at) 
         VALUES (?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([$name, $email, $access_token, $refresh_token, $token_expires_at]);
    $userId = $pdo->lastInsertId();
}

// 🟣 ЗАМІНА СЕСІЇ НА JWT COOKIE 🟣

// 1. Генеруємо наш власний JWT
$jwt = generate_jwt($userId, $email);

// 2. Встановлюємо його як безпечне, HttpOnly cookie
setcookie(
    'auth_token',          // Назва cookie
    $jwt,                  // Токен
    time() + (86400 * 30), // Час закінчення (відповідає токену)
    '/',                   // Шлях (весь сайт)
    '127.0.0.1',           // Домен (використовуйте `false` для production)
    false,                 // Secure (використовуйте `true` для HTTPS)
    true                   // HttpOnly (запобігає доступу з JS)
);

// 3. Очищуємо сесію
unset($_SESSION['code_verifier']);
unset($_SESSION['spotify_token']); // Більше не потрібно
unset($_SESSION['user_id']);       // Тепер це в JWT

// Перенаправляємо на головну сторінку
header("Location: index.php");
exit();
?>