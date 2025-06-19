<?php
session_start();
if (!isset($_SESSION['spotify_token'])) {
    header("Location: index.php");
    exit();
}

if (isset($_POST['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit();
}

$token = $_SESSION['spotify_token']['access_token'];
// Ліміт та період залишаються тільки для Spotify контенту
$limit = $_GET['limit'] ?? 10;
$time_range = $_GET['time_range'] ?? 'medium_term';
$tab = $_GET['tab'] ?? 'artists'; // Залишаємо вкладки для Spotify

// Отримати дані користувача Spotify
$userRes = @file_get_contents("https://api.spotify.com/v1/me", false, stream_context_create([
    "http" => ["header" => "Authorization: Bearer $token"]
]));
$userData = $userRes ? json_decode($userRes, true) : null;

// Отримати топи Spotify
function getTopItems($token, $type, $limit, $time_range) {
    $url = "https://api.spotify.com/v1/me/top/{$type}?limit={$limit}&time_range={$time_range}";
    $res = @file_get_contents($url, false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $token"]
    ]));
    $data = $res ? json_decode($res, true) : [];
    return $data['items'] ?? [];
}

$topArtists = $tab === 'artists' ? getTopItems($token, 'artists', $limit, $time_range) : [];
$topTracks = $tab === 'tracks' ? getTopItems($token, 'tracks', $limit, $time_range) : [];

$topGenres = [];
if ($tab === 'genres') {
    $artistsForGenres = getTopItems($token, 'artists', 50, $time_range); // Для жанрів беремо більше артистів
    foreach ($artistsForGenres as $artist) {
        foreach ($artist['genres'] as $genre) {
            $formattedGenre = ucwords(str_replace('-', ' ', $genre));
            $topGenres[$formattedGenre] = ($topGenres[$formattedGenre] ?? 0) + 1;
        }
    }
    arsort($topGenres);
    $topGenres = array_slice($topGenres, 0, $limit, true);
}

$topAlbums = [];
if ($tab === 'albums') {
    $topTracksForAlbums = getTopItems($token, 'tracks', 50, $time_range); // Для альбомів беремо більше треків
    $albumMap = [];
    foreach ($topTracksForAlbums as $track) {
        $album = $track['album'];
        $albumId = $album['id'];
        if (!isset($albumMap[$albumId])) {
            $albumMap[$albumId] = [
                'name' => $album['name'],
                'image' => $album['images'][0]['url'] ?? '',
                'url' => $album['external_urls']['spotify'] ?? '#',
                'artists' => array_map(fn($a) => $a['name'], $album['artists']),
                'count' => 1
            ];
        } else {
            $albumMap[$albumId]['count']++;
        }
    }
    usort($albumMap, fn($a, $b) => $b['count'] <=> $a['count']);
    $topAlbums = array_slice($albumMap, 0, $limit);
}

// Завантаження обраних фільмів (завжди, без ліміту та фільтрів за періодом)
require_once 'db_connect.php'; // Переконайтесь, що цей файл існує та підключається до БД
$favMovies = [];
$userId = $_SESSION['user_id'] ?? null; // Припускаємо, що user_id встановлюється в сесії після входу

if ($userId) {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt = $pdo->prepare("
            SELECT m.*
            FROM favorites f
            JOIN movies m ON f.movie_id = m.tmdb_id
            WHERE f.user_id = ?
        ");
        $stmt->execute([$userId]);
        $favMovies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        error_log("PDO object not initialized in db_connect.php");
    }
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>VibeMatch — Кабінет</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* Стилі для основного макета з двома колонками */
        .cabinet-main {
            display: flex; /* Використовуємо flexbox для колонок */
            gap: 2rem; /* Простір між колонками */
            align-items: flex-start; /* Вирівнювання елементів вгорі */
        }

        .spotify-content {
            flex: 1; /* Spotify колонка займає доступний простір */
            min-width: 400px; /* Мінімальна ширина для Spotify колонки */
        }

        .movies-sidebar {
            width: 320px; /* Фіксована ширина для колонки фільмів */
            flex-shrink: 0; /* Забороняємо колонці фільмів зменшуватися */
        }

        /* Стилі для компактного списку фільмів */
        .movies-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .movie-list-item {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid #333; /* Опціонально: розділювач */
        }

        .movie-list-item:last-child {
            border-bottom: none;
        }

        .movie-list-item img {
            width: 60px; /* Менший розмір зображення */
            height: 90px; /* Зберігаємо співвідношення сторін */
            object-fit: cover;
            border-radius: 5px;
        }

        .movie-list-item .movie-info {
            flex-grow: 1;
        }

        .movie-list-item .movie-info strong {
            display: block;
            font-size: 1.1em;
            color: #eee;
        }

        .movie-list-item .movie-info small {
            color: #aaa;
            font-size: 0.9em;
        }

        /* Додаткові стилі для карток зі Spotify, щоб вони виглядали охайно */
        .items-list {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .item-card {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 10px 0;
            border-bottom: 1px solid #333;
        }

        .item-card:last-child {
            border-bottom: none;
        }

        .item-rank {
            width: 30px; /* Фіксована ширина для номера */
            text-align: right;
            font-weight: bold;
            color: #fff;
        }

        .item-image {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 5px;
        }

        .item-info {
            flex-grow: 1;
        }

        .item-info strong {
            display: block;
            font-size: 1.2em;
            color: #eee;
        }

        .item-info small {
            color: #aaa;
            font-size: 0.9em;
        }

        .tabs-navigation {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #333;
            padding-bottom: 0.5rem;
        }

        .tab-item {
            padding: 8px 15px;
            text-decoration: none;
            color: #ccc;
            border-radius: 5px;
            transition: background-color 0.3s ease;
        }

        .tab-item:hover {
            background-color: #333;
        }

        .tab-item.active {
            background-color: #555;
            color: #fff;
            font-weight: bold;
        }

        .filter-controls {
            display: flex;
            gap: 1rem;
            margin-bottom: 1.5rem;
            align-items: center;
        }

        .filter-controls label {
            color: #ccc;
            font-size: 0.9em;
        }

        .filter-controls select {
            background-color: #333;
            color: #fff;
            border: 1px solid #555;
            border-radius: 5px;
            padding: 5px 10px;
            cursor: pointer;
            outline: none;
        }
        .filter-controls select:focus {
            border-color: #777;
        }
    </style>
</head>
<body>
<header class="main-header">
    <h1 class="app-title"><a href="index.php">VIBEMATCH</a></h1>
    <nav class="header-nav">
        <form method="post" style="display:inline;">
            <button type="submit" name="logout" class="btn logout-btn">Вийти</button>
        </form>
        <a href="index.php" class="btn back-btn">На головну</a>
    </nav>
</header>

<main class="cabinet-main">
    <div class="spotify-content">
        <?php if ($userData): ?>
            <section class="user-profile">
                <img src="<?= htmlspecialchars($userData['images'][0]['url'] ?? 'https://via.placeholder.com/80?text=User') ?>" alt="User Image" class="user-image" />
                <div class="user-details">
                    <h2 class="user-name"><?= htmlspecialchars($userData['display_name']) ?></h2>
                    <p class="user-email"><?= htmlspecialchars($userData['email']) ?></p>
                </div>
            </section>
        <?php endif; ?>

        <nav class="tabs-navigation">
            <a href="?tab=artists&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab-item <?= $tab==='artists' ? 'active' : '' ?>">Топ Виконавці</a>
            <a href="?tab=tracks&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab-item <?= $tab==='tracks' ? 'active' : '' ?>">Топ Пісні</a>
            <a href="?tab=genres&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab-item <?= $tab==='genres' ? 'active' : '' ?>">Топ Жанри</a>
            <a href="?tab=albums&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab-item <?= $tab==='albums' ? 'active' : '' ?>">Топ Альбоми</a>
        </nav>

        <form method="get" class="filter-controls">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <label>Кількість:
                <select name="limit" onchange="this.form.submit()">
                    <?php foreach ([10, 20, 50] as $num): ?>
                        <option value="<?= $num ?>" <?= $limit == $num ? 'selected' : '' ?>><?= $num ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Період:
                <select name="time_range" onchange="this.form.submit()">
                    <option value="short_term" <?= $time_range === 'short_term' ? 'selected' : '' ?>>4 тижні</option>
                    <option value="medium_term" <?= $time_range === 'medium_term' ? 'selected' : '' ?>>6 місяців</option>
                    <option value="long_term" <?= $time_range === 'long_term' ? 'selected' : '' ?>>Весь час</option>
                </select>
            </label>
        </form>

        <section class="content-display">
            <?php if ($tab === 'artists'): ?>
                <ol class="items-list">
                    <?php foreach ($topArtists as $i => $artist): ?>
                        <li class="item-card">
                            <div class="item-rank"><?= $i + 1 ?></div>
                            <a href="<?= $artist['external_urls']['spotify'] ?>" target="_blank">
                                <img src="<?= $artist['images'][0]['url'] ?? 'https://via.placeholder.com/80?text=Artist' ?>" class="item-image" />
                            </a>
                            <div class="item-info">
                                <strong><?= htmlspecialchars($artist['name']) ?></strong>
                                <small><?= htmlspecialchars(implode(', ', $artist['genres'])) ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php elseif ($tab === 'tracks'): ?>
                <ol class="items-list">
                    <?php foreach ($topTracks as $i => $track): ?>
                        <li class="item-card">
                            <div class="item-rank"><?= $i + 1 ?></div>
                            <a href="<?= $track['external_urls']['spotify'] ?>" target="_blank">
                                <img src="<?= $track['album']['images'][0]['url'] ?? 'https://via.placeholder.com/80?text=Track' ?>" class="item-image" />
                            </a>
                            <div class="item-info">
                                <strong><?= htmlspecialchars($track['name']) ?></strong>
                                <small><?= htmlspecialchars($track['artists'][0]['name']) ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php elseif ($tab === 'genres'): ?>
                <ol class="genres-list">
                    <?php $pos = 1; foreach ($topGenres as $genre => $count): ?>
                        <li>
                            <strong><?= htmlspecialchars($genre) ?></strong> — <?= $count ?> разів
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php elseif ($tab === 'albums'): ?>
                <ol class="items-list">
                    <?php foreach ($topAlbums as $i => $album): ?>
                        <li class="item-card">
                            <div class="item-rank"><?= $i + 1 ?></div>
                            <a href="<?= $album['url'] ?>" target="_blank">
                                <img src="<?= $album['image'] ?>" class="item-image" />
                            </a>
                            <div class="item-info">
                                <strong><?= htmlspecialchars($album['name']) ?></strong>
                                <small><?= htmlspecialchars(implode(', ', $album['artists'])) ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>

    <div class="movies-sidebar">
        <h3 style="color: #ccc;">Обрані фільми</h3>
        <?php if (!empty($favMovies)): ?>
            <ol class="movies-list">
                <?php foreach ($favMovies as $i => $movie): ?>
<li class="movie-list-item">
    <form method="post" action="toggle_favorite.php" style="position: relative;">
        <input type="hidden" name="movie_id" value="<?= $movie['id'] ?>">
        <button type="submit" title="Видалити з обраного" style="
            position: absolute;
            top: -10px;
            right: -10px;
            background: #990000;
            color: white;
            border: none;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            cursor: pointer;
        ">×</button>
        <a href="movie.php?id=<?= $movie['tmdb_id'] ?>">
            <img src="<?= htmlspecialchars($movie['image_url']) ?>" alt="Постер">
        </a>
    </form>
    <div class="movie-info">
        <strong><?= htmlspecialchars($movie['title']) ?></strong>
        <small><?= htmlspecialchars(mb_strimwidth($movie['description'], 0, 100, '...')) ?></small>
    </div>
</li>
                <?php endforeach; ?>
            </ol>
        <?php else: ?>
            <p style="color: #777;">Немає фільмів у закладках.</p>
        <?php endif; ?>
    </div>
</main>
</body>
</html>