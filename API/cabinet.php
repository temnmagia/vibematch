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

$limit = $_GET['limit'] ?? 10;
$time_range = $_GET['time_range'] ?? 'medium_term';
$tab = $_GET['tab'] ?? 'artists';

// Функція для отримання даних зі Spotify API
function fetchSpotifyData($url, $token) {
    $options = [
        "http" => [
            "header" => "Authorization: Bearer $token\r\n" .
                        "Content-Type: application/json\r\n",
            "method" => "GET",
            "ignore_errors" => true // Важливо для обробки помилок
        ]
    ];
    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        // Логування помилки, якщо потрібно
        // error_log("Failed to fetch data from Spotify API: " . $url);
        return null;
    }

    $http_response_header_str = implode("\r\n", $http_response_header);
    if (strpos($http_response_header_str, 'HTTP/1.1 200 OK') === false) {
        // error_log("Spotify API returned non-200 status for $url: " . $http_response_header_str);
        return null;
    }

    return json_decode($response, true);
}


// Отримання даних користувача
// Зверніть увагу: URL-адреси є ПРИКЛАДАМИ.
// Вам потрібно буде замінити їх на реальні кінцеві точки Spotify API.
$userData = fetchSpotifyData("https://api.spotify.com/v1/me", $token);

// Функція для отримання топу з Spotify API
function getTopItems($token, $type, $limit, $time_range) {
    // Приклад URL, вам потрібно використовувати реальні Spotify API
    $url = "https://api.spotify.com/v1/me/top/{$type}?limit={$limit}&time_range={$time_range}";
    $data = fetchSpotifyData($url, $token);
    return $data['items'] ?? [];
}

$topArtists = $tab === 'artists' ? getTopItems($token, 'artists', $limit, $time_range) : [];
$topTracks = $tab === 'tracks' ? getTopItems($token, 'tracks', $limit, $time_range) : [];

// Жанри (збираємо з топ-виконавців)
$topGenres = [];
if ($tab === 'genres') {
    // Запитуємо більше артистів, щоб отримати повнішу картину жанрів
    $artistsForGenres = getTopItems($token, 'artists', 50, $time_range);
    foreach ($artistsForGenres as $artist) {
        foreach ($artist['genres'] as $genre) {
            $formattedGenre = ucwords(str_replace('-', ' ', $genre)); // Форматування назви жанру
            if (!isset($topGenres[$formattedGenre])) {
                $topGenres[$formattedGenre] = 0;
            }
            $topGenres[$formattedGenre]++;
        }
    }
    arsort($topGenres); // Сортування за кількістю у спадаючому порядку
    $topGenres = array_slice($topGenres, 0, $limit, true); // Обрізаємо до потрібного ліміту
}

// Альбоми (збираємо з топ-пісень)
$topAlbums = [];
if ($tab === 'albums') {
    // Запитуємо більше треків, щоб отримати більше унікальних альбомів
    $topTracksForAlbums = getTopItems($token, 'tracks', 50, $time_range);
    $albumMap = [];

    foreach ($topTracksForAlbums as $track) {
        $album = $track['album'];
        $albumId = $album['id'];
        if (!isset($albumMap[$albumId])) {
            $albumMap[$albumId] = [
                'name' => $album['name'],
                'image' => $album['images'][0]['url'] ?? 'https://via.placeholder.com/80?text=No+Image', // Запасне зображення
                'url' => $album['external_urls']['spotify'] ?? '#',
                'artists' => array_map(fn($a) => $a['name'], $album['artists']),
                'count' => 1
            ];
        } else {
            $albumMap[$albumId]['count']++;
        }
    }

    // Сортування альбомів за кількістю треків у них
    usort($albumMap, fn($a, $b) => $b['count'] - $a['count']);
    $topAlbums = array_slice($albumMap, 0, $limit); // Обрізаємо до потрібного ліміту
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VibeMatch — Кабінет</title>
    <link rel="stylesheet" href="styles.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
</head>
<body>
<header class="main-header">
    <h1 class="app-title">VibeMatch</h1>
    <nav class="header-nav">
        <form method="post" style="display:inline;">
            <button type="submit" name="logout" class="btn logout-btn">Вийти</button>
        </form>
        <a href="index.php" class="btn back-btn">На головну</a>
    </nav>
</header>

<main class="cabinet-main">
    <?php if ($userData): ?>
        <section class="user-profile">
            <img src="<?= htmlspecialchars($userData['images'][0]['url'] ?? 'https://via.placeholder.com/80?text=User') ?>" alt="User Image" class="user-image" />
            <div class="user-details">
                <h2 class="user-name"><?= htmlspecialchars($userData['display_name']) ?></h2>
                <p class="user-email"><?= htmlspecialchars($userData['email']) ?></p>
            </div>
        </section>
    <?php else: ?>
        <section class="user-profile no-data">
            <p>Не вдалося завантажити дані користувача Spotify. Будь ласка, спробуйте ще раз або перевірте своє з'єднання.</p>
        </section>
    <?php endif; ?>

    <nav class="tabs-navigation">
        <a href="?tab=artists&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab-item <?= $tab==='artists' ? 'active' : '' ?>">Топ Виконавці</a>
        <a href="?tab=tracks&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab-item <?= $tab==='tracks' ? 'active' : '' ?>">Топ Пісні</a>
        <a href="?tab=genres&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab-item <?= $tab==='genres' ? 'active' : '' ?>">Топ Жанри</a>
        <a href="?tab=albums&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab-item <?= $tab==='albums' ? 'active' : '' ?>">Топ Альбоми</a>
    </nav>

    <form method="get" class="filter-controls">
        <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>" />
        <label class="filter-label">Кількість:
            <select name="limit" onchange="this.form.submit()" class="filter-select">
                <?php foreach ([10, 20, 50] as $num): ?>
                    <option value="<?= $num ?>" <?= $limit == $num ? 'selected' : '' ?>><?= $num ?></option>
                <?php endforeach; ?>
            </select>
        </label>

        <label class="filter-label filter-label--time-range">Період:
            <select name="time_range" onchange="this.form.submit()" class="filter-select">
                <option value="short_term" <?= $time_range === 'short_term' ? 'selected' : '' ?>>4 тижні</option>
                <option value="medium_term" <?= $time_range === 'medium_term' ? 'selected' : '' ?>>6 місяців</option>
                <option value="long_term" <?= $time_range === 'long_term' ? 'selected' : '' ?>>Весь час</option>
            </select>
        </label>
    </form>

    <section class="content-display">
        <?php if ($tab === 'artists'): ?>
            <?php if (count($topArtists) === 0): ?>
                <p class="message-card no-data-message">Немає даних про артистів за обраний період.</p>
            <?php else: ?>
                <ol class="items-list">
                    <?php foreach ($topArtists as $i => $artist): ?>
                        <li class="item-card" title="Переглянути в Spotify">
                            <div class="item-rank"><?= $i + 1 ?></div>
                            <a href="<?= htmlspecialchars($artist['external_urls']['spotify']) ?>" target="_blank" class="item-link">
                                <img src="<?= htmlspecialchars($artist['images'][0]['url'] ?? 'https://via.placeholder.com/80?text=Artist') ?>" alt="Artist Image" class="item-image" />
                            </a>
                            <div class="item-info">
                                <strong class="item-title"><?= htmlspecialchars($artist['name']) ?></strong>
                                <small class="item-subtitle"><?= htmlspecialchars(implode(', ', $artist['genres'])) ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

        <?php elseif ($tab === 'tracks'): ?>
            <?php if (count($topTracks) === 0): ?>
                <p class="message-card no-data-message">Немає даних про пісні за обраний період.</p>
            <?php else: ?>
                <ol class="items-list">
                    <?php foreach ($topTracks as $i => $track): ?>
                        <li class="item-card" title="Переглянути в Spotify">
                            <div class="item-rank"><?= $i + 1 ?></div>
                            <a href="<?= htmlspecialchars($track['external_urls']['spotify']) ?>" target="_blank" class="item-link">
                                <img src="<?= htmlspecialchars($track['album']['images'][0]['url'] ?? 'https://via.placeholder.com/80?text=Track') ?>" alt="Track Image" class="item-image" />
                            </a>
                            <div class="item-info">
                                <strong class="item-title"><?= htmlspecialchars($track['name']) ?></strong>
                                <small class="item-subtitle"><?= htmlspecialchars($track['artists'][0]['name']) ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

        <?php elseif ($tab === 'genres'): ?>
            <?php if (count($topGenres) === 0): ?>
                <p class="message-card no-data-message">Немає даних про жанри за обраний період. (Завантажується з топ-виконавців)</p>
            <?php else: ?>
                <ol class="items-list">
                    <?php $pos = 1; foreach ($topGenres as $genre => $count): ?>
                        <li class="item-card">
                            <div class="item-rank"><?= $pos++ ?></div>
                            <div class="item-info" style="flex-direction: row; justify-content: space-between; align-items: center;">
                                <strong class="item-title"><?= htmlspecialchars($genre) ?></strong>
                                <small class="item-subtitle" style="flex-shrink: 0; margin-left: 15px;"><?= $count ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>

        <?php elseif ($tab === 'albums'): ?>
            <?php if (count($topAlbums) === 0): ?>
                <p class="message-card no-data-message">Немає даних про альбоми за обраний період. (Завантажується з топ-пісень)</p>
            <?php else: ?>
                <ol class="items-list">
                    <?php foreach ($topAlbums as $i => $album): ?>
                        <li class="item-card" title="Альбом від <?= htmlspecialchars(implode(', ', $album['artists'])) ?>">
                            <div class="item-rank"><?= $i + 1 ?></div>
                            <a href="<?= htmlspecialchars($album['url']) ?>" target="_blank" class="item-link">
                                <img src="<?= htmlspecialchars($album['image']) ?>" alt="Album Image" class="item-image" />
                            </a>
                            <div class="item-info">
                                <strong class="item-title"><?= htmlspecialchars($album['name']) ?></strong>
                                <small class="item-subtitle"><?= htmlspecialchars(implode(', ', $album['artists'])) ?></small>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>