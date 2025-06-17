<?php
session_start();
$isLoggedIn = isset($_SESSION['spotify_token']);

$movies = [];

if ($isLoggedIn) {
    // Підключаємо import_movies і отримуємо масив фільмів
    // Переконайтеся, що 'give_movies.php' існує і повертає коректний масив
    $movies = include 'give_movies.php';
}

// Обробка виходу
if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8" />
    <title>VibeMatch — Головна</title>
    <link rel="stylesheet" href="styles.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
    <header class="main-header">
<h1 class="app-title"><a href="index.php">VibeMatch</a></h1>

        <nav class="header-nav">
            <?php if (!$isLoggedIn): ?>
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

    <main class="main-content">
        <div class="intro-section">
            <h2>Ласкаво просимо у <span class="highlight">VibeMatch</span></h2>
            <p>Розкрийте фільми, що резонують з вашими таємними музичними вібраціями.</p>
        </div>

        <?php if (!$isLoggedIn): ?>
            <div class="message-card login-prompt">
                <p>Щоб зануритися у світ персоналізованих рекомендацій, будь ласка, авторизуйтесь.</p>
                <a href="spotify_auth.php" class="btn auth-btn">Увійти через Spotify</a>
            </div>
        <?php else: ?>
            <?php if (empty($movies) || !is_array($movies)): ?>
                <div class="message-card no-recommendations">
                    <p>На жаль, наразі немає фільмів, що відповідають вашим вібраціям. Продовжуйте слухати музику або перевірте дані в Кабінеті.</p>
                </div>
            <?php else: ?>
                <section class="movies-section">
                    <h3>Ваші персональні рекомендації:</h3>
                    <div class="movies-carousel">
                        <?php foreach ($movies as $movie): ?>
<a href="movie.php?id=<?= $movie['tmdb_id'] ?>" class="movie-slide">
    <div class="movie-poster-wrapper">
        <?php if (!empty($movie['image_url'])): ?>
            <img src="<?= htmlspecialchars($movie['image_url']) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" loading="lazy" class="movie-poster" />
        <?php endif; ?>
    </div>
    <div class="movie-details">
        <h4 class="movie-title"><?= htmlspecialchars($movie['title']) ?></h4>
        <p class="movie-description"><?= nl2br(htmlspecialchars($movie['description'])) ?></p>
    </div>
</a>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </main>

    <script>
        // Карусель фільмів
        const carousel = document.querySelector('.movies-carousel');
        let isDown = false;
        let startX;
        let scrollLeft;

        if (carousel) {
            carousel.addEventListener('mousedown', (e) => {
                isDown = true;
                carousel.classList.add('active-drag');
                startX = e.pageX - carousel.offsetLeft;
                scrollLeft = carousel.scrollLeft;
            });
            carousel.addEventListener('mouseleave', () => {
                isDown = false;
                carousel.classList.remove('active-drag');
            });
            carousel.addEventListener('mouseup', () => {
                isDown = false;
                carousel.classList.remove('active-drag');
            });
            carousel.addEventListener('mousemove', (e) => {
                if (!isDown) return;
                e.preventDefault();
                const x = e.pageX - carousel.offsetLeft;
                const walk = (x - startX) * 2;
                carousel.scrollLeft = scrollLeft - walk;
            });
        }
    </script>
</body>
</html>