<?php
require_once __DIR__ . '/auth_check.php';
session_start();

if (isset($_POST['logout'])) logout();

$isLoggedIn = $IS_LOGGED_IN;
$showMovies = $_SESSION['show_movies'] ?? false;
$movies     = $_SESSION['last_movies'] ?? [];

if ($isLoggedIn && (isset($_POST['get_recommendations']) || isset($_POST['refresh_recommendations']))) {
    require_once __DIR__ . '/db_connect.php';

    $_SESSION['shown_movie_ids'] = $_SESSION['shown_movie_ids'] ?? [];
    $movies = include __DIR__ . '/give_movies.php';

    $_SESSION['last_movies']  = $movies;
    $_SESSION['show_movies']  = true;
    $showMovies = true;

    foreach ($movies as $m) {
        if (!empty($m['id']) && $m['id'] !== -1) {
            $_SESSION['shown_movie_ids'][] = $m['id'];
        }
    }
}

// Кількість запитів дружби (для бейджа)
$pendingFriends = 0;
if ($isLoggedIn) {
    require_once __DIR__ . '/db_connect.php';
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE receiver_id = ? AND status = 'pending'");
    $stmt->execute([$CURRENT_USER_ID]);
    $pendingFriends = (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>VibeMatch — Головна</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .badge { display:inline-block; background:#7c3aed; color:#fff; font-size:0.7rem; padding:1px 6px; border-radius:10px; margin-left:5px; vertical-align:middle; }
    </style>
</head>
<body>
    <header class="main-header">
        <h1 class="app-title"><a href="index.php">VibeMatch</a></h1>
        <nav class="header-nav">
            <?php if (!$isLoggedIn): ?>
                <a href="spotify_auth.php" class="btn auth-btn">Авторизуватись</a>
            <?php else: ?>
                <a href="friends.php" class="btn" style="background:#2a1a4a;">
                    👥 Друзі
                    <?php if ($pendingFriends > 0): ?>
                        <span class="badge"><?= $pendingFriends ?></span>
                    <?php endif; ?>
                </a>
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

    <main class="main-content">
        <div class="intro-section">
            <h2>Ласкаво просимо у <span class="highlight">VibeMatch</span></h2>
            <p>Розкрийте фільми, що резонують з вашими музичними вібраціями.</p>
        </div>

        <?php if (!$isLoggedIn): ?>
            <div class="message-card login-prompt">
                <p>Щоб отримати персоналізовані рекомендації, будь ласка, авторизуйтесь через Spotify.</p>
                <a href="spotify_auth.php" class="btn auth-btn">Увійти через Spotify</a>
            </div>

        <?php elseif (!$showMovies): ?>
            <div class="message-card recommendation-prompt">
                <p>Натисніть кнопку нижче, щоб отримати рекомендації фільмів на основі ваших музичних вподобань!</p>
                <form method="post">
                    <button type="submit" name="get_recommendations" class="btn">🎬 Отримати рекомендації</button>
                </form>
            </div>

        <?php elseif (empty($movies) || !is_array($movies)): ?>
            <div class="message-card no-recommendations">
                <p>На жаль, наразі немає фільмів, що відповідають вашим вібраціям. Спробуйте пізніше або перевірте ваш Spotify.</p>
                <form method="post">
                    <button type="submit" name="get_recommendations" class="btn">🔄 Спробувати знову</button>
                </form>
            </div>

        <?php else: ?>
            <section class="movies-section">
                <form method="post" style="margin-bottom:1rem;">
                    <button type="submit" name="refresh_recommendations" class="btn">🔄 Оновити рекомендації</button>
                </form>
                <h3>Ваші персональні рекомендації:</h3>
                <div class="movies-carousel">
                    <?php foreach ($movies as $movie): ?>
                    <a href="movie.php?id=<?= $movie['tmdb_id'] ?>" class="movie-slide">
                        <div class="movie-poster-wrapper">
                            <?php if (!empty($movie['image_url'])): ?>
                            <img src="<?= htmlspecialchars($movie['image_url']) ?>"
                                 alt="<?= htmlspecialchars($movie['title']) ?>"
                                 loading="lazy" class="movie-poster">
                            <?php endif; ?>
                        </div>
                        <div class="movie-details">
                            <h4 class="movie-title"><?= htmlspecialchars($movie['title']) ?></h4>
                            <p class="movie-description">
                                <?= nl2br(htmlspecialchars(mb_strimwidth($movie['description'] ?: $movie['description_en'], 0, 200, '…'))) ?>
                            </p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </main>

    <script>
        const carousel = document.querySelector('.movies-carousel');
        if (carousel) {
            let isDown = false, startX, scrollLeft;
            carousel.addEventListener('mousedown', e => {
                isDown = true; carousel.classList.add('active-drag');
                startX = e.pageX - carousel.offsetLeft;
                scrollLeft = carousel.scrollLeft;
            });
            carousel.addEventListener('mouseleave', () => { isDown = false; carousel.classList.remove('active-drag'); });
            carousel.addEventListener('mouseup',    () => { isDown = false; carousel.classList.remove('active-drag'); });
            carousel.addEventListener('mousemove',  e => {
                if (!isDown) return;
                e.preventDefault();
                carousel.scrollLeft = scrollLeft - (e.pageX - carousel.offsetLeft - startX) * 2;
            });
        }
    </script>
</body>
</html>
