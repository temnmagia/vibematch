<?php
session_start();
require_once 'db_connect.php';

if (!isset($_SESSION['user_id'], $_POST['movie_id'])) {
    header("Location: index.php");
    exit;
}

$userId = $_SESSION['user_id'];
$movieId = (int) $_POST['movie_id'];

$stmt = $pdo->prepare("SELECT tmdb_id FROM movies WHERE id = ?");
$stmt->execute([$movieId]);
$TMDB_ID = $stmt->fetchColumn();

// Перевіряємо, чи вже в закладках
$stmt = $pdo->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND movie_id = ?");
$stmt->execute([$userId, $TMDB_ID]);
$exists = $stmt->fetchColumn();

if ($exists) {
    // Видалити з обраного
    $pdo->prepare("DELETE FROM favorites WHERE user_id = ? AND movie_id = ?")->execute([$userId, $TMDB_ID]);
} else {
    // Додати в обране
    $pdo->prepare("INSERT INTO favorites (user_id, movie_id) VALUES (?, ?)")->execute([$userId, $TMDB_ID]);
}

$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header("Location: $referer");
exit;
