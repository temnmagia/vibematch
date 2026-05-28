<?php
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';

require_auth();

$localMovieId = (int)($_POST['movie_id'] ?? 0);
$vote         = (int)($_POST['vote']     ?? 0);

if (!$localMovieId || !in_array($vote, [-1, 1])) {
    die("❌ Невірні дані");
}

// Поточний голос
$stmt = $pdo->prepare("SELECT vote FROM votes WHERE user_id = ? AND movie_id = ?");
$stmt->execute([$CURRENT_USER_ID, $localMovieId]);
$currentVote = $stmt->fetchColumn();

// Отримуємо tmdb_id для activity_feed
$stmt = $pdo->prepare("SELECT tmdb_id FROM movies WHERE id = ?");
$stmt->execute([$localMovieId]);
$tmdbId = $stmt->fetchColumn();

if ($currentVote === false) {
    // Новий голос
    $pdo->prepare("INSERT INTO votes (user_id, movie_id, vote) VALUES (?, ?, ?)")
        ->execute([$CURRENT_USER_ID, $localMovieId, $vote]);

    if ($tmdbId) {
        $type = $vote === 1 ? 'vote_up' : 'vote_down';
        $pdo->prepare("INSERT INTO activity_feed (user_id, type, movie_id) VALUES (?, ?, ?)")
            ->execute([$CURRENT_USER_ID, $type, $tmdbId]);
    }
} elseif ((int)$currentVote === $vote) {
    // Скасувати голос
    $pdo->prepare("DELETE FROM votes WHERE user_id = ? AND movie_id = ?")
        ->execute([$CURRENT_USER_ID, $localMovieId]);

    if ($tmdbId) {
        $type = $vote === 1 ? 'vote_up' : 'vote_down';
        $pdo->prepare("DELETE FROM activity_feed WHERE user_id = ? AND movie_id = ? AND type = ?")
            ->execute([$CURRENT_USER_ID, $tmdbId, $type]);
    }
} else {
    // Зміна голосу
    $pdo->prepare("UPDATE votes SET vote = ? WHERE user_id = ? AND movie_id = ?")
        ->execute([$vote, $CURRENT_USER_ID, $localMovieId]);

    if ($tmdbId) {
        $oldType = $vote === 1 ? 'vote_down' : 'vote_up';
        $newType = $vote === 1 ? 'vote_up'   : 'vote_down';
        $pdo->prepare("DELETE FROM activity_feed WHERE user_id = ? AND movie_id = ? AND type = ?")
            ->execute([$CURRENT_USER_ID, $tmdbId, $oldType]);
        $pdo->prepare("INSERT INTO activity_feed (user_id, type, movie_id) VALUES (?, ?, ?)")
            ->execute([$CURRENT_USER_ID, $newType, $tmdbId]);
    }
}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'index.php'));
exit();
