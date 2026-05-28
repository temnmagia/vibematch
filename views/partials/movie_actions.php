<?php
// movie_actions.php — підключається в movie.php
// Змінні $movieId, $IS_LOGGED_IN, $CURRENT_USER_ID вже доступні з movie.php

require_once __DIR__ . '/../../config/db_connect.php';

if (!isset($movieId)) return;

$TMDB_ID = (int)$movieId;

// Локальний ID з нашої БД
$stmt = $pdo->prepare("SELECT id FROM movies WHERE tmdb_id = ?");
$stmt->execute([$TMDB_ID]);
$localMovieId = $stmt->fetchColumn();

$isLoggedIn = $IS_LOGGED_IN;
$userId     = $CURRENT_USER_ID;

$voteScore  = 0;
$userVote   = 0;
$isFavorite = false;

if ($isLoggedIn && $userId && $localMovieId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(vote),0) FROM votes WHERE movie_id = ?");
    $stmt->execute([$localMovieId]);
    $voteScore = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT vote FROM votes WHERE movie_id = ? AND user_id = ?");
    $stmt->execute([$localMovieId, $userId]);
    $userVote = (int)($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND movie_id = ?");
    $stmt->execute([$userId, $TMDB_ID]);
    $isFavorite = (bool)$stmt->fetchColumn();
}

if (!$isLoggedIn || !$localMovieId) {
    if ($isLoggedIn) echo '<p style="color:#777; font-size:0.9em;">Рейтинги доступні незабаром.</p>';
    return;
}
?>
<div class="movie-actions-container">
    <div class="vote-controls">
        <form action="../actions/vote.php" method="post">
            <input type="hidden" name="movie_id" value="<?= $localMovieId ?>">
            <input type="hidden" name="vote" value="1">
            <button type="submit" class="btn-vote <?= $userVote===1 ? 'active' : 'inactive' ?>">⬆</button>
        </form>

        <span class="vote-score">
            <?= ($voteScore > 0 ? '+' : '') . $voteScore ?>
        </span>

        <form action="../actions/vote.php" method="post">
            <input type="hidden" name="movie_id" value="<?= $localMovieId ?>">
            <input type="hidden" name="vote" value="-1">
            <button type="submit" class="btn-vote <?= $userVote===-1 ? 'active' : 'inactive' ?>">⬇</button>
        </form>
    </div>

    <form action="../actions/toggle_favorite.php" method="post">
        <input type="hidden" name="movie_id" value="<?= $localMovieId ?>">
        <button type="submit" class="btn-favorite-toggle <?= $isFavorite ? 'saved' : 'unsaved' ?>">
            <?= $isFavorite ? '💜 У закладках' : '🤍 Додати в закладки' ?>
        </button>
    </form>
</div>
