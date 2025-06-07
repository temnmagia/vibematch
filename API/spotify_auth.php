<?php
session_start();

$client_id = 'ff991d6f6c224110be45fa35a875b85b';
$redirect_uri = 'http://127.0.0.1:80/vibematch/API/callback.php';
$scope = 'user-read-email user-top-read user-read-private';

$code_verifier = bin2hex(random_bytes(64));
$_SESSION['code_verifier'] = $code_verifier;

$code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');

$params = http_build_query([
    'client_id' => $client_id,
    'response_type' => 'code',
    'redirect_uri' => $redirect_uri,
    'scope' => $scope,
    'code_challenge_method' => 'S256',
    'code_challenge' => $code_challenge,
]);

header("Location: https://accounts.spotify.com/authorize?$params");
exit();
