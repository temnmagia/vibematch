<?php
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';

require_auth();

// Перевіряємо, чи це тихий запит (AJAX) з JavaScript
$isAjax = !empty($_POST['is_ajax']);

// Приймаємо або локальний ID (з форми movie.php) або tmdb_id (через AJAX)
$localMovieId = (int)($_POST['movie_id'] ?? 0);
$tmdbId       = (int)($_POST['tmdb_id'] ?? 0);

// Якщо прислали локальний ID (старий метод), дістаємо його tmdb_id
if ($localMovieId > 0 && !$tmdbId) {
    $stmt = $pdo->prepare("SELECT tmdb_id FROM movies WHERE id = ?");
    $stmt->execute([$localMovieId]);
    $tmdbId = $stmt->fetchColumn();
}

if (!$tmdbId) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'error' => 'Фільм не знайдено']);
        exit;
    }
    header('Location: ../public/index.php');
    exit();
}

// Перевірка чи вже в закладках
$stmt = $pdo->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND movie_id = ?");
$stmt->execute([$CURRENT_USER_ID, $tmdbId]);
$exists = $stmt->fetchColumn();

$isFavoriteNow = false;

if ($exists) {
    // Видалити з закладок
    $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND movie_id = ?")
        ->execute([$CURRENT_USER_ID, $tmdbId]);
    // Видалити з activity_feed
    $pdo->prepare("DELETE FROM activity_feed WHERE user_id = ? AND movie_id = ? AND type = 'favorite'")
        ->execute([$CURRENT_USER_ID, $tmdbId]);
    
    $isFavoriteNow = false;
} else {
    // Додати в закладки
    $pdo->prepare("INSERT INTO favorites (user_id, movie_id) VALUES (?, ?)")
        ->execute([$CURRENT_USER_ID, $tmdbId]);
    // Записати в activity_feed
    $pdo->prepare("INSERT INTO activity_feed (user_id, type, movie_id, created_at) VALUES (?, 'favorite', ?, NOW())")
        ->execute([$CURRENT_USER_ID, $tmdbId]);
        
    $isFavoriteNow = true;
}

// Якщо це AJAX — повертаємо JSON-результат для JavaScript
if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'is_favorite' => $isFavoriteNow]);
    exit();
}

// Якщо це звичайна форма (з movie.php) — перекидаємо назад
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../public/index.php'));
exit();