<?php
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../core/TMDb_api.php';

session_start();
if (isset($_POST['logout'])) logout();

$isLoggedIn = $IS_LOGGED_IN;

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$movieId = (int)$_GET['id'];
$movie   = tmdb_get_movie_details($movieId);

if (empty($movie['title'])) {
    include __DIR__ . '/404.php';
    exit();
}

$videos  = tmdb_get_movie_trailers($movieId);

$trailerUrl = null;
foreach ($videos['results'] ?? [] as $v) {
    if ($v['site'] === 'YouTube' && $v['type'] === 'Trailer') {
        $trailerUrl = "https://www.youtube.com/embed/{$v['key']}";
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <?php 
    $pageTitle = htmlspecialchars($movie['title'] ?? 'Фільм') . " — VibeMatch";
    include __DIR__ . '/../views/partials/head.php'; 
    ?>
</head>
<body>
    <?php include __DIR__ . '/../views/partials/header.php'; ?>

<div class="main-content">
    <div class="intro-section movie-intro-section">
        <div class="movie-page-header">
            <h2 style="margin:0; flex:1;">
                <?= htmlspecialchars($movie['title']) ?>
                (<?= substr($movie['release_date'] ?? '', 0, 4) ?>)
            </h2>
            <div class="movie-actions-wrapper">
                <?php include __DIR__ . '/../views/partials/movie_actions.php'; ?>
            </div>
        </div>

        <div class="movie-details-wrapper">
            <div class="movie-poster-container">
                <img src="https://image.tmdb.org/t/p/w500<?= $movie['poster_path'] ?? '' ?>"
                     alt="Постер"
                     class="movie-poster-large">
            </div>

            <div class="movie-info-block">
                <p><strong>Жанр:</strong>
                    <?= htmlspecialchars(implode(', ', array_map(fn($g) => $g['name'], $movie['genres'] ?? []))) ?>
                </p>
                <p><strong>Дата виходу:</strong> <?= htmlspecialchars($movie['release_date'] ?? '—') ?></p>
                <p><strong>Рейтинг TMDb:</strong> <?= $movie['vote_average'] ?? '—' ?>/10</p>
                <p><strong>Опис:</strong><br><?= nl2br(htmlspecialchars($movie['overview'] ?? '')) ?></p>

                <?php if ($trailerUrl): ?>
                <div style="margin-top:1.5rem;">
                    <h3>Трейлер</h3>
                    <iframe width="100%" height="360" src="<?= $trailerUrl ?>"
                            frameborder="0" allowfullscreen style="border-radius:10px;"></iframe>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div style="text-align:center; margin-top:2rem;">
        <a href="index.php" class="btn back-btn">⬅ Назад до рекомендацій</a>
    </div>
</div>
</body>
<script src="assets/app.js"></script>
</html>

