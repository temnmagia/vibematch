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

// Отримання даних користувача
$userRes = file_get_contents("https://api.spotify.com/v1/me", false, stream_context_create([
    "http" => ["header" => "Authorization: Bearer $token"]
]));
$userData = json_decode($userRes, true);

// Функція для отримання топу з Spotify API
function getTopItems($token, $type, $limit, $time_range) {
    $url = "https://api.spotify.com/v1/me/top/$type?limit=$limit&time_range=$time_range";
    $res = file_get_contents($url, false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $token"]
    ]));
    $data = json_decode($res, true);
    return $data['items'] ?? [];
}

$topArtists = $tab === 'artists' ? getTopItems($token, 'artists', $limit, $time_range) : [];
$topTracks = $tab === 'tracks' ? getTopItems($token, 'tracks', $limit, $time_range) : [];

// Жанри
$topGenres = [];
if ($tab === 'genres') {
    $artistsForGenres = getTopItems($token, 'artists', 50, $time_range);
    foreach ($artistsForGenres as $artist) {
        foreach ($artist['genres'] as $genre) {
            if (!isset($topGenres[$genre])) $topGenres[$genre] = 0;
            $topGenres[$genre]++;
        }
    }
    arsort($topGenres);
    $topGenres = array_slice($topGenres, 0, $limit, true);
}

// Альбоми
$topAlbums = [];
if ($tab === 'albums') {
    $topTracksForAlbums = getTopItems($token, 'tracks', 50, $time_range);
    $albumMap = [];

    foreach ($topTracksForAlbums as $track) {
        $album = $track['album'];
        $albumId = $album['id'];
        if (!isset($albumMap[$albumId])) {
            $albumMap[$albumId] = [
                'name' => $album['name'],
                'image' => $album['images'][0]['url'] ?? '',
                'url' => $album['external_urls']['spotify'] ?? '',
                'artists' => array_map(fn($a) => $a['name'], $album['artists']),
                'count' => 1
            ];
        } else {
            $albumMap[$albumId]['count']++;
        }
    }

    usort($albumMap, fn($a, $b) => $b['count'] - $a['count']);
    $topAlbums = array_slice($albumMap, 0, $limit);
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
  <meta charset="UTF-8" />
  <title>VibeMatch — Кабінет</title>
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
<header class="cabinet-header">
  <h1>Кабінет</h1>
  <div class="cabinet-buttons">
    <form method="post" style="display:inline;">
      <button type="submit" name="logout" class="btn logout-btn">Вийти</button>
    </form>
    <a href="index.php" class="btn back-btn">Назад</a>
  </div>
</header>

<main class="cabinet-main">

  <?php if ($userData): ?>
  <section class="user-info">
    <img src="<?= htmlspecialchars($userData['images'][0]['url'] ?? '') ?>" alt="User Image" class="user-image" />
    <h2><?= htmlspecialchars($userData['display_name']) ?></h2>
    <p><?= htmlspecialchars($userData['email']) ?></p>
  </section>
  <?php endif; ?>

  <nav class="tabs">
    <a href="?tab=artists&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab <?= $tab==='artists' ? 'active' : '' ?>">Топ Виконавці</a>
    <a href="?tab=tracks&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab <?= $tab==='tracks' ? 'active' : '' ?>">Топ Пісні</a>
    <a href="?tab=genres&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab <?= $tab==='genres' ? 'active' : '' ?>">Топ Жанри</a>
    <a href="?tab=albums&limit=<?= $limit ?>&time_range=<?= $time_range ?>" class="tab <?= $tab==='albums' ? 'active' : '' ?>">Топ Альбоми</a>
  </nav>

  <form method="get" class="filter-form">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>" />
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

  <section class="content-list">
    <?php if ($tab === 'artists'): ?>
      <?php if (count($topArtists) === 0): ?>
        <p>Немає даних про артистів.</p>
      <?php else: ?>
        <ol class="list-cards">
          <?php foreach ($topArtists as $i => $artist): ?>
            <li class="card" title="Переглянути в Spotify">
              <div class="num-circle"><?= $i + 1 ?></div>
              <a href="<?= $artist['external_urls']['spotify'] ?>" target="_blank">
                <img src="<?= $artist['images'][0]['url'] ?? '' ?>" alt="Artist Image" width="80" />
              </a>
              <div class="info">
                <strong><?= htmlspecialchars($artist['name']) ?></strong>
                <small><?= htmlspecialchars(implode(', ', $artist['genres'])) ?></small>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

    <?php elseif ($tab === 'tracks'): ?>
      <?php if (count($topTracks) === 0): ?>
        <p>Немає даних про пісні.</p>
      <?php else: ?>
        <ol class="list-cards">
          <?php foreach ($topTracks as $i => $track): ?>
            <li class="card" title="Переглянути в Spotify">
              <div class="num-circle"><?= $i + 1 ?></div>
              <a href="<?= $track['external_urls']['spotify'] ?>" target="_blank">
                <img src="<?= $track['album']['images'][0]['url'] ?? '' ?>" alt="Track Image" width="80" />
              </a>
              <div class="info">
                <strong><?= htmlspecialchars($track['name']) ?></strong>
                <small><?= htmlspecialchars($track['artists'][0]['name']) ?></small>
              </div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

    <?php elseif ($tab === 'genres'): ?>
      <?php if (count($topGenres) === 0): ?>
        <p>Немає даних про жанри.</p>
      <?php else: ?>
        <ol class="list-genres">
          <?php $pos = 1; foreach ($topGenres as $genre => $count): ?>
            <li class="genre-item">
              <div class="num-circle"><?= $pos++ ?></div>
              <div class="genre-name"><?= htmlspecialchars($genre) ?></div>
              <div class="genre-count"><?= $count ?> разів</div>
            </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

    <?php elseif ($tab === 'albums'): ?>
      <?php if (count($topAlbums) === 0): ?>
        <p>Немає даних про альбоми.</p>
      <?php else: ?>
        <ol class="list-cards">
          <?php foreach ($topAlbums as $i => $album): ?>
            <li class="card" title="Альбом від <?= htmlspecialchars(implode(', ', $album['artists'])) ?>">
              <div class="num-circle"><?= $i + 1 ?></div>
              <a href="<?= htmlspecialchars($album['url']) ?>" target="_blank">
                <img src="<?= htmlspecialchars($album['image']) ?>" alt="Album Image" width="80" />
              </a>
              <div class="info">
                <strong><?= htmlspecialchars($album['name']) ?></strong>
                <small><?= htmlspecialchars(implode(', ', $album['artists'])) ?></small>
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
