<?php
// session_start(); // ❌ ВИДАЛЕНО
require_once 'db_connect.php';
require_once 'auth_check.php'; // ✅ ДОДАНО

// Перевірка сесії і даних форми
// if (!isset($_SESSION['user_id'], $_POST['movie_id'], $_POST['vote'])) { // ❌ ВИДАЛЕНО
if (!$IS_LOGGED_IN || !isset($_POST['movie_id'], $_POST['vote'])) { // ✅ ЗМІНЕНО
    die("❌ Помилка: відсутні дані або ви не авторизовані");
}

$userId = $CURRENT_USER_ID; // ✅ ЗМІНЕНО
$localMovieId = (int) $_POST['movie_id']; // Це ЛОКАЛЬНИЙ ID з movie_actions.php
$vote = (int) $_POST['vote'];

// Перевірка значення голосу
if (!in_array($vote, [-1, 1])) {
    die("❌ Недопустиме значення vote (має бути -1 або 1)");
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

$referer = $_SERVER['HTTP_REFERER'] ?? '../index.php';
header("Location: $referer");
exit;
?>