<?php
session_start();
$isLoggedIn = isset($_SESSION['spotify_token']);

$movies = [];

if ($isLoggedIn) {
    // Підключаємо import_movies і отримуємо масив фільмів
    $movies = include 'give_movies.php';
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8" />
  <title>VibeMatch — Головна</title>
  <style>
    /* Загальні стилі */
    body {
      background-color: #121212;
      color: #eee;
      font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
      margin: 0;
      padding: 0;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
      user-select: none;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    /* Хедер */
    header.cabinet-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      background: #1e1e2f;
      padding: 20px 30px;
      box-shadow: 0 2px 10px rgba(162, 89, 255, 0.5);
      font-weight: 700;
      font-size: 1.8rem;
      color: #a259ff;
    }

    .cabinet-buttons {
      display: flex;
      gap: 15px;
      align-items: center;
    }

    /* Кнопки */
    .btn {
      background: linear-gradient(45deg, #7e57ff, #a259ff);
      border: none;
      border-radius: 30px;
      padding: 10px 28px;
      color: white;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.3s ease;
      user-select: none;
      font-size: 1rem;
      box-shadow: 0 0 10px #a259ffcc;
    }
    .btn:hover {
      background: linear-gradient(45deg, #a259ff, #7e57ff);
      box-shadow: 0 0 20px #a259ffee;
    }
    .logout-btn {
      background: #ff4d6d;
      box-shadow: 0 0 10px #ff4d6dcc;
    }
    .logout-btn:hover {
      background: #ff3853;
      box-shadow: 0 0 20px #ff3853ee;
    }

    /* Головний контейнер */
    main {
      max-width: 400px;
      margin: 30px auto 50px;
      padding: 0 15px;
      text-align: center;
    }
    main h2 {
      font-weight: 700;
      font-size: 2.2rem;
      margin-bottom: 8px;
    }
    .highlight {
      color: #a259ff;
    }
    main p {
      color: #ccc;
      font-size: 1.1rem;
      margin-bottom: 20px;
    }

    /* Вертикальний список фільмів з snap scrolling */
    .movies-list {
      height: 75vh;
      overflow-y: auto;
      scroll-snap-type: y mandatory;
      scroll-behavior: smooth;
      border-radius: 15px;
      box-shadow: 0 0 30px #a259ff99;
      background: #1a1a2e;
      padding: 20px 10px;

        scrollbar-width: none; /* Firefox */
  -ms-overflow-style: none;  /* IE, Edge */
    }

    .movies-list::-webkit-scrollbar {
  display: none; /* Chrome, Safari, Opera */
}

    .movie-item {
      scroll-snap-align: center;
      margin: 15px 20px;
      background: #2a2a4d;
      border-radius: 20px;
      padding: 20px;
      box-shadow: 0 0 20px #a259ffbb;
      text-align: center;
      user-select: none;
      transition: transform 0.3s ease;
    }
    .movie-item:hover {
      transform: scale(1.02);
      box-shadow: 0 0 35px #d899ffcc;
    }
    .movie-item h3 {
      margin-bottom: 12px;
      font-weight: 700;
      font-size: 1.4rem;
      color: #d4bfff;
    }
    .image-container {
      margin-bottom: 15px;
      overflow: hidden;
      border-radius: 15px;
      box-shadow: 0 0 15px #a259ff88;
    }
    .image-container img {
      width: 100%;
      height: auto;
      object-fit: cover;
      border-radius: 15px;
      display: block;
      margin: 0 auto;
    }
    .movie-item p {
      color: #ccc;
      font-size: 1rem;
      line-height: 1.4;
      overflow: hidden;
      text-overflow: ellipsis;
      user-select: text;
    }

    hr {
      border: 0;
      height: 1px;
      background: #3a3a5a;
      margin: 20px 0;
    }
  </style>
</head>
<body>
  <header class="cabinet-header">
    <h1>VibeMatch</h1>
    <div class="cabinet-buttons">
      <?php if (!$isLoggedIn): ?>
        <a href="spotify_auth.php" class="btn main-btn">Авторизуватись</a>
      <?php else: ?>
        <a href="cabinet.php" class="btn main-btn">Кабінет</a>
        <form method="post" style="display:inline;">
          <button type="submit" name="logout" class="btn logout-btn">Вийти</button>
        </form>
      <?php endif; ?>
    </div>
  </header>

  <main>
    <div class="centered-container">
      <h2>Ласкаво просимо в <span class="highlight">VibeMatch</span></h2>
      <p>Підбирай фільми за своїми музичними вподобаннями</p>

      <?php if (!$isLoggedIn): ?>
        <p>Будь ласка, авторизуйтесь, щоб бачити рекомендації фільмів.</p>
        <a href="spotify_auth.php" class="btn main-btn">Увійти через Spotify</a>
      <?php else: ?>
        <?php if (!$movies): ?>
          <p>Рекомендації фільмів поки що відсутні.</p>
        <?php else: ?>
          <div class="movies-list">
            <?php foreach ($movies as $movie): ?>
              <div class="movie-item">
                <h3><?= htmlspecialchars($movie['title']) ?></h3>
                <?php if (!empty($movie['image_url'])): ?>
                  <div class="image-container">
                    <img src="<?= htmlspecialchars($movie['image_url']) ?>" alt="<?= htmlspecialchars($movie['title']) ?>" />
                  </div>
                <?php endif; ?>
                <p><?= nl2br(htmlspecialchars($movie['description'])) ?></p>
              </div>
              <hr>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </main>
</body>
</html>
