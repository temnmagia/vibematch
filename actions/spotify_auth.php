<?php
session_start();
require_once __DIR__ . '/../config/config.php';

$code_verifier  = bin2hex(random_bytes(64));
$_SESSION['code_verifier'] = $code_verifier;

$code_challenge = rtrim(
    strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'),
    '='
);

$params = http_build_query([
    'client_id'             => SPOTIFY_CLIENT_ID,
    'response_type'         => 'code',
    'redirect_uri'          => SPOTIFY_REDIRECT_URI,
    'scope'                 => SPOTIFY_SCOPE,
    'show_dialog'           => 'true',
    'code_challenge_method' => 'S256',
    'code_challenge'        => $code_challenge,
]);

header("Location: https://accounts.spotify.com/authorize?$params");
exit();
