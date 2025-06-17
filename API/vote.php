<?php
session_start();
require_once 'db_connect.php';


// Перевірка сесії і даних форми
if (!isset($_SESSION['user_id'], $_POST['movie_id'], $_POST['vote'])) {
    die("❌ Помилка: відсутні дані (user_id або movie_id або vote)");
}

$userId = $_SESSION['user_id'];
$tmdbId = (int) $_POST['movie_id']; // з форми приходить TMDb ID
$vote = (int) $_POST['vote'];


// Перевірка значення голосу
if (!in_array($vote, [-1, 1])) {
    die("❌ Недопустиме значення vote (має бути -1 або 1)");
}

// Знайти локальний ID фільму в базі за TMDb ID
$stmt = $pdo->prepare("SELECT tmdb_id FROM movies WHERE id = ?");
$stmt->execute([$tmdbId]);
$localMovieId = $stmt->fetchColumn();


if (!$localMovieId) {
    die("❌ Фільм з TMDb ID $tmdbId не знайдено в таблиці movies");
}

// Перевірка, чи голос вже існує
$stmt = $pdo->prepare("SELECT vote FROM votes WHERE user_id = ? AND movie_id = ?");
$stmt->execute([$userId, $localMovieId]);
$currentVote = $stmt->fetchColumn();

if ($currentVote === false || $currentVote === null) {
    // Ще не голосував — вставити
    $stmt = $pdo->prepare("INSERT INTO votes (user_id, movie_id, vote) VALUES (?, ?, ?)");
    $stmt->execute([$userId, $localMovieId, $vote]);
} elseif ((int)$currentVote === $vote) {
    // Натиснуто ту саму кнопку — скасувати голос
    $stmt = $pdo->prepare("DELETE FROM votes WHERE user_id = ? AND movie_id = ?");
    $stmt->execute([$userId, $localMovieId]);
} else {
    // Зміна голосу
    $stmt = $pdo->prepare("UPDATE votes SET vote = ? WHERE user_id = ? AND movie_id = ?");
    $stmt->execute([$vote, $userId, $localMovieId]);
}

$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
header("Location: $referer");
exit;
