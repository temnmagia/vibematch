<?php
// session_start(); // ❌ ВИДАЛЕНО
require_once 'db_connect.php';
require_once 'auth_check.php'; // ✅ ДОДАНО

// if (!isset($_SESSION['user_id'], $_POST['movie_id'])) { // ❌ ВИДАЛЕНО
if (!$IS_LOGGED_IN || !isset($_POST['movie_id'])) { // ✅ ЗМІНЕНО
    header("Location: ../index.php");
    exit;
}

$userId = $CURRENT_USER_ID; // ✅ ЗМІНЕНО
$localMovieId = (int) $_POST['movie_id']; // Це локальний ID з movie_actions.php

// Потрібно отримати TMDB_ID, оскільки таблиця `favorites` використовує його
$stmt = $pdo->prepare("SELECT tmdb_id FROM movies WHERE id = ?");
$stmt->execute([$localMovieId]);
$TMDB_ID = $stmt->fetchColumn();

if (!$TMDB_ID) {
    die("Помилка: фільм не знайдено.");
}

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

$referer = $_SERVER['HTTP_REFERER'] ?? '../index.php';
header("Location: $referer");
exit;
?>