<?php
require_once 'db_connect.php';

if (!isset($movieId)) {
    echo "<p style='color:red;'>Помилка: movieId не заданий</p>";
    return;
}
$stmt = $pdo->prepare("SELECT id FROM movies WHERE tmdb_id = ?");
$stmt->execute([$movieId]);
$TMDB_ID = $movieId;
$movieId = $stmt->fetchColumn();

if (!$movieId) {
    echo "<p style='color:red;'>Фільм не знайдено в базі (по tmdb_id)</p>";
    return;
}
$isLoggedIn = isset($_SESSION['spotify_token']);
$userId = $_SESSION['user_id'] ?? null;

$voteScore = 0;
$userVote = 0;
$isFavorite = false;

if ($isLoggedIn && $userId) {
    // Отримати загальний рейтинг
    $stmt = $pdo->prepare("SELECT SUM(vote) FROM votes WHERE movie_id = ?");
    $stmt->execute([$TMDB_ID]);
    $voteScore = $stmt->fetchColumn() ?? 0;

    // Отримати голос користувача
    $stmt = $pdo->prepare("SELECT vote FROM votes WHERE movie_id = ? AND user_id = ?");
    $stmt->execute([$TMDB_ID, $userId]);
    $userVote = $stmt->fetchColumn() ?? 0;

    // Чи є в закладках
    $stmt = $pdo->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND movie_id = ?");
    $stmt->execute([$userId, $TMDB_ID]);
    $isFavorite = $stmt->fetchColumn();
}
?>

<?php if ($isLoggedIn): ?>
    <div style="
        display: flex;
        align-items: center;
        justify-content:space-between;
        gap: 10px;
    ">
        <!-- Голосування -->
        <div style="display: flex; align-items: center; gap: 0px;">
            <form action="vote.php" method="post">
                <input type="hidden" name="movie_id" value="<?= $movieId ?>">
                <input type="hidden" name="vote" value="1">
                <button type="submit"
                        style="font-size: 24px; background: none; border: none; color: <?= $userVote == 1 ? '#8A2BE2' : '#ccc' ?>; cursor: pointer;">⬆</button>
            </form>

            <div style="font-size: 20px; color: #fff; font-weight: bold;">
                <?= ($voteScore >= 0 ? '+' : '') . $voteScore ?>
            </div>

            <form action="vote.php" method="post">
                <input type="hidden" name="movie_id" value="<?= $movieId ?>">
                <input type="hidden" name="vote" value="-1">
                <button type="submit"
                        style="font-size: 24px; background: none; border: none; color: <?= $userVote == -1 ? '#8A2BE2' : '#ccc' ?>; cursor: pointer;">⬇</button>
            </form>
        </div>

        <!-- Закладки -->
        <form action="toggle_favorite.php" method="post">
            <input type="hidden" name="movie_id" value="<?= $movieId ?>">
            <button type="submit"
                    style="background-color: <?= $isFavorite ? '#4A0050' : '#2A0A33' ?>;
                           color: #fff;
                           padding: 8px 16px;
                           border-radius: 8px;
                           border: 1px solid #8A2BE2;
                           cursor: pointer;">
                <?= $isFavorite ? '💜 У закладках' : '🤍 Додати в закладки' ?>
            </button>
        </form>
    </div>
<?php endif; ?>
