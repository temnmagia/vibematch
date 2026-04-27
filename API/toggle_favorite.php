<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

require_auth();

$localMovieId = (int)($_POST['movie_id'] ?? 0);
if (!$localMovieId) {
    header('Location: index.php');
    exit();
}

// Знаходимо tmdb_id
$stmt = $pdo->prepare("SELECT tmdb_id FROM movies WHERE id = ?");
$stmt->execute([$localMovieId]);
$tmdbId = $stmt->fetchColumn();

if (!$tmdbId) {
    header('Location: index.php');
    exit();
}

// Перевірка чи вже в закладках
$stmt = $pdo->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND movie_id = ?");
$stmt->execute([$CURRENT_USER_ID, $tmdbId]);
$exists = $stmt->fetchColumn();

if ($exists) {
    // Видалити з закладок
    $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND movie_id = ?")
        ->execute([$CURRENT_USER_ID, $tmdbId]);
    // Видалити з activity_feed
    $pdo->prepare("DELETE FROM activity_feed WHERE user_id = ? AND movie_id = ? AND type = 'favorite'")
        ->execute([$CURRENT_USER_ID, $tmdbId]);
} else {
    // Додати в закладки
    $pdo->prepare("INSERT INTO favorites (user_id, movie_id) VALUES (?, ?)")
        ->execute([$CURRENT_USER_ID, $tmdbId]);
    // Записати в activity_feed
    $pdo->prepare("INSERT INTO activity_feed (user_id, type, movie_id) VALUES (?, 'favorite', ?)")
        ->execute([$CURRENT_USER_ID, $tmdbId]);
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit();
