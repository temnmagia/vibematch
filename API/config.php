<?php
// Встановлюємо правильний часовий пояс для сервера (UTC)
date_default_timezone_set('UTC');

// ============================================================
// config.php — Централізована конфігурація VibeMatch
// ============================================================

// --- Динамічне визначення URL ---
$protocol = isset($_SERVER['HTTP_X_FORWARDED_PROTO']) ? $_SERVER['HTTP_X_FORWARDED_PROTO'] . '://' : ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://");
$host = $_SERVER['HTTP_HOST']; 

// Якщо ми локально (через localhost або просто 127.0.0.1 без порту), 
// жорстко підміняємо адресу на ту, яку вимагає Spotify (:80)

$base_url_dynamic = $protocol . $host . '/vibematch/API';

// --- Spotify ---
define('SPOTIFY_CLIENT_ID',   'ff991d6f6c224110be45fa35a875b85b');
// Тепер тут завжди буде 127.0.0.1:80 для локальної роботи, або ngrok для публічної
define('SPOTIFY_REDIRECT_URI', $base_url_dynamic . '/callback.php');
define('SPOTIFY_SCOPE',       'user-read-email user-top-read user-read-private');

// --- TMDb ---
define('TMDB_API_KEY',  '52e3411fbaed0449e366f0843aad1d2b');
define('TMDB_BASE_URL', 'https://api.themoviedb.org/3');

// --- JWT ---
define('JWT_SECRET_KEY', 'trafiuksuperduperkruta'); // ⚠️ змінити перед production
define('JWT_ISSUER',     $protocol . $host);
define('JWT_AUDIENCE',   $protocol . $host);
define('JWT_TTL',        86400 * 30); // 30 днів

// --- Cookie ---
// Відсікаємо порт (наприклад :80), бо куки не люблять порти в домені
$cookie_domain = explode(':', $host)[0];
define('COOKIE_DOMAIN',  $cookie_domain);
// Якщо це ngrok (https), то куки будуть Secure. Якщо локально (http) - ні.
define('COOKIE_SECURE',  strpos($protocol, 'https') !== false); 
define('COOKIE_PATH',    '/');

// --- DB ---
define('DB_HOST',     'localhost');
define('DB_NAME',     'vibematch_2');
define('DB_USER',     'root');
define('DB_PASSWORD', '1234');
define('DB_CHARSET',  'utf8mb4');

// --- App ---
define('BASE_URL', $base_url_dynamic);