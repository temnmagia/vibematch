<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/TMDb_api.php';

session_start();
if (isset($_POST['logout'])) logout();

$isLoggedIn = $IS_LOGGED_IN;

if (!isset($_GET['id'])) {
    header('Location: index.php');
    exit();
}

$movieId = (int)$_GET['id'];
$movie   = tmdb_get_movie_details($movieId);
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
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($movie['title'] ?? 'Фільм') ?> — VibeMatch</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<header class="main-header">
    <h1 class="app-title"><a href="index.php">VibeMatch</a></h1>
    <nav class="header-nav">
        <?php if (!$isLoggedIn): ?>
            <a href="spotify_auth.php" class="btn auth-btn">Авторизуватись</a>
        <?php else: ?>
            <a href="friends.php" class="btn" style="background:#2a1a4a;">👥 Друзі</a>
            <div class="dropdown">
                <a href="cabinet.php" class="btn cabinet-btn">Кабінет</a>
                <div class="dropdown-content">
                    <a href="cabinet.php">Перейти в Кабінет</a>
                    <a href="friends.php">Друзі</a>
                    <div class="dropdown-divider"></div>
                    <form method="post" class="dropdown-form-button">
                        <button type="submit" name="logout">Вийти</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </nav>
</header>

<div class="main-content">
    <div class="intro-section" style="text-align:left;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
            <h2 style="margin:0; flex:1;">
                <?= htmlspecialchars($movie['title']) ?>
                (<?= substr($movie['release_date'] ?? '', 0, 4) ?>)
            </h2>
            <div style="flex-shrink:0;">
                <?php include __DIR__ . '/movie_actions.php'; ?>
            </div>
        </div>

        <div style="display:flex; gap:30px; flex-wrap:wrap; margin-top:1.5rem;">
            <div style="display:flex; align-items:flex-start;">
                <img src="https://image.tmdb.org/t/p/w500<?= $movie['poster_path'] ?? '' ?>"
                     alt="Постер"
                     style="width:300px; border-radius:12px; box-shadow:0 4px 20px rgba(0,0,0,0.5);">
            </div>

            <div style="flex:1; min-width:250px;">
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
</html>
