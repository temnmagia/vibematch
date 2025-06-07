<?php
session_start();

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

header("Location: index.php");
exit();
