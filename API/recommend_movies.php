<?php
session_start();
require_once 'db_connect.php';

// Токен Spotify (припускаємо, що він у сесії)
$access_token = $_SESSION['spotify_token']['access_token'] ?? null;
if (!$access_token) {
    die('Токен Spotify не знайдено.');
}

// Функція отримання топ артистів (для прикладу)
function getUserTopArtists($token, $limit = 20) {
    $url = "https://api.spotify.com/v1/me/top/artists?limit=$limit";
    $opts = [
        "http" => [
            "header" => "Authorization: Bearer $token"
        ]
    ];
    $context = stream_context_create($opts);
    $response = file_get_contents($url, false, $context);
    return json_decode($response, true);
}

// Отримуємо топ артистів
$topArtistsData = getUserTopArtists($access_token);
if (empty($topArtistsData['items'])) {
    die("Не вдалося отримати топ артистів.");
}

// Витягуємо жанри артистів
$allGenres = [];
foreach ($topArtistsData['items'] as $artist) {
    foreach ($artist['genres'] as $genre) {
        $allGenres[] = $genre;
    }
}

// Підрахунок найчастіших жанрів
$genreCounts = array_count_values($allGenres);
arsort($genreCounts);

// Вибираємо топ 5 жанрів
$topGenres = array_slice(array_keys($genreCounts), 0, 5);

$_SESSION['user_genres'] = $topGenres;

// Переадресація на import_movies.php з передачею жанрів GET
header('Location: import_movies.php?genres=' . urlencode(implode(',', $topGenres)));
exit;
