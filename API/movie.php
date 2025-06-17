<?php
require_once 'TMDb_api.php';
session_start();
if (!isset($_GET['id'])) {
    echo "Фільм не знайдено.";
    exit;
}
$isLoggedIn = isset($_SESSION['spotify_token']);
$movieId = intval($_GET['id']);
$movie = tmdb_get_movie_details($movieId);
$video = tmdb_get_movie_trailers($movieId);
$trailerUrl = null;

if (!empty($video['results'])) {
    foreach ($video['results'] as $v) {
        if ($v['site'] === 'YouTube' && $v['type'] === 'Trailer') {
            $trailerUrl = "https://www.youtube.com/embed/" . $v['key'];
            break;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($movie['title']) ?></title>
    <link rel="stylesheet" href="styles.css">
</head>




<body>
    <header class="main-header">
<h1 class="app-title"><a href="index.php">VibeMatch</a></h1>

        <nav class="header-nav">
            <?php if (!$isLoggedIn):?>
                <a href="spotify_auth.php" class="btn auth-btn">Авторизуватись</a>
            <?php else: ?>
                <div class="dropdown">
                    <button class="btn cabinet-btn">Кабінет</button>
                    <div class="dropdown-content">
                        <a href="cabinet.php">Перейти в Кабінет</a>
                        <div class="dropdown-divider"></div> <form method="post" style="display:inline;">
                            <button type="submit" name="logout">Вийти</button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </nav>
    </header>
<div class="main-content">
<div class="intro-section" style="text-align: left;">
<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
    <h2 style="margin: 1%;">
        <?= htmlspecialchars($movie['title']) ?> (<?= substr($movie['release_date'], 0, 4) ?>)
    </h2>
    <?php include 'movie_actions.php'; ?>
</div>
<div style="display: flex; gap: 30px; flex-wrap: wrap;">
    <!-- Картинка з вертикальним центруванням -->
    <div style="display: flex; align-items: center; justify-content: center; min-width: 500px;">
        <img src="https://image.tmdb.org/t/p/w500<?= $movie['poster_path'] ?>" 
             alt="Постер" 
             style="width: 500px; height: 750px; border-radius: 12px; box-shadow: 0 4px 10px rgba(0,0,0,0.6);">
    </div>

    <!-- Контент -->
    <div style="flex: 1; min-width: 250px;">
        <p><strong>Жанр:</strong>
            <?= implode(', ', array_map(fn($g) => $g['name'], $movie['genres'])) ?>
        </p>
        <p><strong>Дата виходу:</strong> <?= $movie['release_date'] ?></p>
        <p><strong>Рейтинг TMDb:</strong> <?= $movie['vote_average'] ?>/10</p>
        <p><strong>Опис:</strong><br><?= $movie['overview'] ?></p>

        <?php if ($trailerUrl): ?>
            <div style="margin-top: 30px;">
                <h3>Трейлер</h3>
                <iframe width="100%" height="400" src="<?= $trailerUrl ?>" frameborder="0" allowfullscreen></iframe>
            </div>
        <?php endif; ?>
    </div>
</div>

    </div>

    <div style="text-align: center;">
        <a href="index.php" class="btn back-btn">⬅ Назад до рекомендацій</a>
    </div>
        </div>
</body>
</html>
