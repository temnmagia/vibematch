<?php
require_once __DIR__ . '/../config/config.php';

/**
 * Перевіряє та за потреби оновлює токен Spotify.
 * Повертає дійсний access_token або null.
 */
function get_valid_spotify_token(PDO $pdo, int $userId): ?string {
    $stmt = $pdo->prepare("SELECT spotify_token, spotify_token_expires, spotify_refresh_token FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $tokenData = $stmt->fetch();

    if (!$tokenData || empty($tokenData['spotify_refresh_token'])) {
        return null;
    }

    $accessToken = $tokenData['spotify_token'];

    // Якщо токен ще дійсний (з запасом в 60 секунд)
    if ($tokenData['spotify_token_expires'] >= (time() + 60)) {
        return $accessToken;
    }

    // Оновлюємо токен
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $tokenData['spotify_refresh_token'],
            'client_id'     => SPOTIFY_CLIENT_ID,
        ]),
    ]);
    $res      = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($res['access_token'])) {
        error_log("Spotify refresh failed for user $userId");
        return null;
    }

    $newToken   = $res['access_token'];
    $newRefresh = $res['refresh_token'] ?? $tokenData['spotify_refresh_token'];
    $newExpires = time() + ($res['expires_in'] ?? 3600);

    $pdo->prepare("UPDATE users SET spotify_token = ?, spotify_token_expires = ?, spotify_refresh_token = ? WHERE id = ?")
        ->execute([$newToken, $newExpires, $newRefresh, $userId]);

    return $newToken;
}

/**
 * Отримує топ елементи (artists або tracks) користувача
 */
function get_spotify_top_items(string $token, string $type, int $limit = 20, string $time_range = 'medium_term'): array {
    $url = "https://api.spotify.com/v1/me/top/{$type}?limit={$limit}&time_range={$time_range}";
    $res = @file_get_contents($url, false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $token", "ignore_errors" => true]
    ]));
    
    // Якщо помилка авторизації (наприклад, 401)
    if (!empty($http_response_header) && (str_contains($http_response_header[0], '401') || str_contains($http_response_header[0], '403'))) {
        return ['error' => 'auth_failed'];
    }
    
    $data = $res ? json_decode($res, true) : [];
    return $data['items'] ?? [];
}

/**
 * Отримує профіль користувача зі Spotify
 */
function get_spotify_profile(string $token): ?array {
    $res = @file_get_contents("https://api.spotify.com/v1/me", false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $token", "ignore_errors" => true]
    ]));
    return $res ? json_decode($res, true) : null;
}