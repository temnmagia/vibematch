<?php
session_start();
$isLoggedIn = isset($_SESSION['spotify_token']);

require_once 'db_connect.php'; // Підключення до бази

$movies = [];
if ($isLoggedIn) {
    // Витягуємо 10 фільмів для авторизованого користувача
    $stmt = $pdo->prepare("SELECT * FROM movies LIMIT 10");
    $stmt->execute();
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8" />
  <title>VibeMatch — Головна</title>
  <link rel="stylesheet" href="styles.css" />
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
