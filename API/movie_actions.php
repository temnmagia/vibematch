<?php
// Цей файл підключається в movie.php, де вже є auth_check
// Але db_connect потрібен тут
require_once 'db_connect.php';

if (!isset($movieId)) {
    echo "<p style='color:red;'>Помилка: movieId не заданий (має прийти з movie.php)</p>";
    return;
}

// $movieId, що прийшов з movie.php, - це TMDb ID
$TMDB_ID = $movieId; 

// Знаходимо наш ЛОКАЛЬНИЙ ID
$stmt = $pdo->prepare("SELECT id FROM movies WHERE tmdb_id = ?");
$stmt->execute([$TMDB_ID]);
$localMovieId = $stmt->fetchColumn(); // Це локальний 'movies.id'

// $isLoggedIn = isset($_SESSION['spotify_token']); // ❌ ВИДАЛЕНО
$isLoggedIn = $IS_LOGGED_IN; // ✅ ЗМІНЕНО (встановлено в movie.php)
// $userId = $_SESSION['user_id'] ?? null; // ❌ ВИДАЛЕНО
$userId = $CURRENT_USER_ID; // ✅ ЗМІНЕНО (встановлено в movie.php)

$voteScore = 0;
$userVote = 0;
$isFavorite = false;

if ($isLoggedIn && $userId && $localMovieId) {
    // Отримати загальний рейтинг
    $stmt = $pdo->prepare("SELECT SUM(vote) FROM votes WHERE movie_id = ?");
    $stmt->execute([$localMovieId]); // ✅ Використовуємо локальний ID
    $voteScore = $stmt->fetchColumn() ?? 0;

    // Отримати голос користувача
    $stmt = $pdo->prepare("SELECT vote FROM votes WHERE movie_id = ? AND user_id = ?");
    $stmt->execute([$localMovieId, $userId]); // ✅ Використовуємо локальний ID
    $userVote = $stmt->fetchColumn() ?? 0;

    // Чи є в закладках
    $stmt = $pdo->prepare("SELECT 1 FROM favorites WHERE user_id = ? AND movie_id = ?");
    $stmt->execute([$userId, $TMDB_ID]); // ✅ Використовуємо TMDb ID (згідно з toggle_favorite)
    $isFavorite = $stmt->fetchColumn();
}
?>

<?php if ($isLoggedIn && $localMovieId): // Показуємо кнопки, тільки якщо фільм є в нашій базі ?>
    <div style="
        display: flex;
        align-items: center;
        justify-content:space-between;
        gap: 10px;
    ">
        <div style="display: flex; align-items: center; gap: 0px;">
            <form action="vote.php" method="post">
                <input type="hidden" name="movie_id" value="<?= $localMovieId ?>"> <input type="hidden" name="vote" value="1">
                <button type="submit"
                        style="font-size: 24px; background: none; border: none; color: <?= $userVote == 1 ? '#8A2BE2' : '#ccc' ?>; cursor: pointer;">⬆</button>
            </form>

            <div style="font-size: 20px; color: #fff; font-weight: bold;">
                <?= ($voteScore >= 0 ? '+' : '') . $voteScore ?>
            </div>

            <form action="vote.php" method="post">
                <input type="hidden" name="movie_id" value="<?= $localMovieId ?>"> <input type="hidden" name="vote" value="-1">
                <button type="submit"
                        style="font-size: 24px; background: none; border: none; color: <?= $userVote == -1 ? '#8A2BE2' : '#ccc' ?>; cursor: pointer;">⬇</button>
            </form>
        </div>

        <form action="toggle_favorite.php" method="post">
            <input type="hidden" name="movie_id" value="<?= $localMovieId ?>"> <button type="submit"
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
<?php elseif ($isLoggedIn): ?>
    <p style="color: #777; font-size: 0.9em;">Рейтинги та закладки будуть доступні незабаром.</p>
<?php endif; ?>
