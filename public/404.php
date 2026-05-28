<?php
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';
if (session_status() === PHP_SESSION_NONE) session_start();

if (isset($_POST['logout'])) logout();

$pageTitle = "404 — Сторінку не знайдено";
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <?php include __DIR__ . '/../views/partials/head.php'; ?>
</head>
<body class="feed-body">
    
    <div class="glass-bg">
        <div class="glass-shape shape-1"></div>
        <div class="glass-shape shape-2"></div>
        <div class="glass-shape shape-3"></div>
    </div>

    <?php 
    $headerClass = 'main-header glass-header';
    include __DIR__ . '/../views/partials/header.php'; 
    ?>

    <main class="main-content" style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 70vh; text-align: center;">
        <div class="glass-panel" style="padding: 3rem 2rem; max-width: 500px; border-radius: 16px; border-color: rgba(138, 43, 226, 0.3);">
            <div style="font-size: 5rem; margin-bottom: 0px;">🛸</div>
            <h1 style="font-size: 4rem; margin: 0; color: #d8b4fe; text-shadow: 0 0 20px rgba(138,43,226,0.5);">404</h1>
            <h2 style="margin-top: 10px; color: #fff;">Ой! Цей вайб загубився.</h2>
            <p style="color: #ccc; margin-bottom: 2rem; line-height: 1.5;">
                <?php 
                if (strpos($_SERVER['REQUEST_URI'], 'movie.php') !== false) {
                    echo "Фільм, який ви шукаєте, розчинився у цифровому просторі.";
                } elseif (strpos($_SERVER['REQUEST_URI'], 'friend_profile.php') !== false) {
                    echo "Користувача з таким ID не існує, або ви не перебуваєте з ним у статусі друзів. Перевірте правильність посилання.";
                } else {
                    echo "Сторінка, яку ви шукаєте, загубилася або розчинила свій вайб у просторі.";
                }
                ?>
            </p>
            <a href="index.php" class="btn glass-btn" style="background: rgba(124, 58, 237, 0.8); color: white; border-color: #a78bfa;">
                🏠 Повернутися на головну
            </a>
        </div>
    </main>

    <script src="assets/app.js"></script>
</body>
</html>