<?php
session_start();
$isLoggedIn = isset($_SESSION['spotify_token']);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8" />
  <title>VibeMatch — Головна</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="centered-container">
    <h1>Ласкаво просимо в <span class="highlight">VibeMatch</span></h1>
    <p>Підбирай фільми за своїми музичними вподобаннями</p>

    <?php if (!$isLoggedIn): ?>
      <a href="spotify_auth.php" class="btn main-btn">Увійти через Spotify</a>
    <?php else: ?>
      <a href="cabinet.php" class="btn main-btn">Перейти в Кабінет</a>
    <?php endif; ?>
  </div>
</body>
</html>
