<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

require_auth();
session_start();

if (isset($_POST['logout'])) logout();

$profileId = (int)($_GET['id'] ?? 0);
if (!$profileId) {
    header('Location: friends.php');
    exit();
}

// --- Перевірка: чи є вони друзями? ---
$stmt = $pdo->prepare(
    "SELECT status FROM friendships
     WHERE ((requester_id = ? AND receiver_id = ?)
         OR (requester_id = ? AND receiver_id = ?))
       AND status = 'accepted'"
);
$stmt->execute([$CURRENT_USER_ID, $profileId, $profileId, $CURRENT_USER_ID]);
$isFriend = (bool)$stmt->fetchColumn();

if (!$isFriend) {
    header('Location: friends.php');
    exit();
}

// --- Дані профілю та токени друга ---
$stmt = $pdo->prepare("SELECT id, name, email, spotify_token, spotify_token_expires, spotify_refresh_token FROM users WHERE id = ?");
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

if (!$profile) {
    header('Location: friends.php');
    exit();
}

// --- Оновлення токена друга (якщо прострочений) ---
$access_token = $profile['spotify_token'] ?? null;

if ($profile['spotify_token_expires'] < (time() + 60) && !empty($profile['spotify_refresh_token'])) {
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $profile['spotify_refresh_token'],
            'client_id'     => SPOTIFY_CLIENT_ID,
        ]),
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($res['access_token'])) {
        $access_token     = $res['access_token'];
        $newRefresh       = $res['refresh_token'] ?? $profile['spotify_refresh_token'];
        $newExpires       = time() + ($res['expires_in'] ?? 3600);
        $pdo->prepare("UPDATE users SET spotify_token = ?, spotify_token_expires = ?, spotify_refresh_token = ? WHERE id = ?")
            ->execute([$access_token, $newExpires, $newRefresh, $profileId]);
    }
}

// --- Отримання Spotify статистики друга ---
$limit      = (int)($_GET['limit'] ?? 10);
$time_range = $_GET['time_range'] ?? 'medium_term';
$tab        = $_GET['tab'] ?? 'artists';

function getTopItems(string $token, string $type, int $limit, string $time_range): array {
    $url = "https://api.spotify.com/v1/me/top/{$type}?limit={$limit}&time_range={$time_range}";
    $res = @file_get_contents($url, false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $token", "ignore_errors" => true]
    ]));
    $data = $res ? json_decode($res, true) : [];
    return $data['items'] ?? [];
}

$topArtists = ($tab === 'artists') ? getTopItems($access_token, 'artists', $limit, $time_range) : [];
$topTracks  = ($tab === 'tracks')  ? getTopItems($access_token, 'tracks',  $limit, $time_range) : [];
$topGenres  = [];
$topAlbums  = [];

if ($tab === 'genres') {
    $all = getTopItems($access_token, 'artists', 50, $time_range);
    foreach ($all as $a) {
        foreach ($a['genres'] as $g) {
            $k = ucwords(str_replace('-', ' ', $g));
            $topGenres[$k] = ($topGenres[$k] ?? 0) + 1;
        }
    }
    arsort($topGenres);
    $topGenres = array_slice($topGenres, 0, $limit, true);
}

if ($tab === 'albums') {
    $tracks = getTopItems($access_token, 'tracks', 50, $time_range);
    $map = [];
    foreach ($tracks as $t) {
        $al = $t['album'];
        $id = $al['id'];
        if (!isset($map[$id])) {
            $map[$id] = [
                'name'    => $al['name'],
                'image'   => $al['images'][0]['url'] ?? '',
                'url'     => $al['external_urls']['spotify'] ?? '#',
                'artists' => array_map(fn($a) => $a['name'], $al['artists']),
                'count'   => 1,
            ];
        } else {
            $map[$id]['count']++;
        }
    }
    usort($map, fn($a, $b) => $b['count'] <=> $a['count']);
    $topAlbums = array_slice($map, 0, $limit);
}

// --- Зображення профілю Spotify ---
$spotifyProfileImage = null;
if ($access_token) {
    $res = @file_get_contents("https://api.spotify.com/v1/me", false, stream_context_create([
        "http" => ["header" => "Authorization: Bearer $access_token", "ignore_errors" => true]
    ]));
    $friendSpotifyData = $res ? json_decode($res, true) : null;
    if (!empty($friendSpotifyData['images'][0]['url'])) {
        $spotifyProfileImage = $friendSpotifyData['images'][0]['url'];
    }
}

// --- Закладки друга ---
$stmt = $pdo->prepare(
    "SELECT m.id, m.tmdb_id, m.title, m.image_url, m.description, m.description_en
     FROM favorites f
     JOIN movies m ON m.tmdb_id = f.movie_id
     WHERE f.user_id = ?
     ORDER BY f.created_at DESC"
);
$stmt->execute([$profileId]);
$favMovies = $stmt->fetchAll();

// --- Голоси друга (upvotes) ---
$stmt = $pdo->prepare(
    "SELECT m.tmdb_id, m.title, m.image_url, m.description, m.description_en
     FROM votes v
     JOIN movies m ON m.id = v.movie_id
     WHERE v.user_id = ? AND v.vote = 1
     ORDER BY m.title"
);
$stmt->execute([$profileId]);
$likedMovies = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>VibeMatch — <?= htmlspecialchars($profile['name']) ?></title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .cabinet-main { display: flex; gap: 2rem; align-items: flex-start; }
        .spotify-content { flex: 1; min-width: 300px; }
        .movies-sidebar { width: 300px; flex-shrink: 0; }

        .tabs-navigation { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; border-bottom: 1px solid #2a1a3a; padding-bottom: 0.5rem; flex-wrap: wrap; }
        .tab-item { padding: 7px 14px; text-decoration: none; color: #aaa; border-radius: 8px; font-size: 0.9rem; transition: background 0.2s; }
        .tab-item:hover { background: #1a0a24; }
        .tab-item.active { background: #2a1a3a; color: #d8b4fe; font-weight: 600; }

        .filter-controls { display: flex; gap: 1rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .filter-controls label { color: #aaa; font-size: 0.9em; }
        .filter-controls select { background: #1a0a24; color: #fff; border: 1px solid #3a1a4a; border-radius: 6px; padding: 5px 10px; cursor: pointer; outline: none; }

        .items-list { list-style: none; padding: 0; margin: 0; }
        .item-card { display: flex; align-items: center; gap: 14px; padding: 10px 0; border-bottom: 1px solid #1a0a24; }
        .item-card:last-child { border-bottom: none; }
        .item-rank { width: 28px; text-align: right; font-weight: 700; color: #666; }
        .item-image { width: 70px; height: 70px; object-fit: cover; border-radius: 6px; }
        .item-info strong { display: block; color: #eee; }
        .item-info small { color: #888; font-size: 0.82rem; }

        .movies-list { list-style: none; padding: 0; margin: 0; }
        .movie-list-item { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #1a0a24; }
        .movie-list-item:last-child { border-bottom: none; }
        .movie-list-item img { width: 52px; height: 78px; object-fit: cover; border-radius: 5px; }
        .movie-list-item .movie-info strong { display: block; color: #eee; font-size: 0.9rem; }
        .movie-list-item .movie-info small { color: #888; font-size: 0.8rem; }

        /* Стилі для вкладок фільмів справа */
        .movie-tabs { display: flex; gap: 0.5rem; margin-bottom: 1rem; border-bottom: 1px solid #2a1a3a; padding-bottom: 0.5rem; }
        .movie-tab-btn { background: none; border: none; color: #888; font-size: 0.9rem; font-weight: 600; cursor: pointer; padding: 5px 10px; border-radius: 6px; }
        .movie-tab-btn.active { background: #2a1a3a; color: #d8b4fe; }

        @media(max-width: 768px) {
            .cabinet-main { flex-direction: column; }
            .movies-sidebar { width: 100%; }
        }
    </style>
</head>
<body>
<header class="main-header">
    <h1 class="app-title"><a href="index.php">VibeMatch</a></h1>
    <nav class="header-nav">
        <a href="friends.php" class="btn" style="background:#3a1a5a;">← Друзі</a>
        <a href="cabinet.php" class="btn cabinet-btn">Мій Кабінет</a>
        <form method="post" style="display:inline;">
            <button type="submit" name="logout" class="btn logout-btn">Вийти</button>
        </form>
    </nav>
</header>

<main class="cabinet-main main-content">
    <div class="spotify-content">
        <section class="user-profile" style="margin-bottom:2rem; display:flex; align-items:center; gap:1.5rem; background:#1a0a24; padding:1.5rem; border-radius:12px; border:1px solid #3a1a4a;">
            <?php if ($spotifyProfileImage): ?>
                <img src="<?= htmlspecialchars($spotifyProfileImage) ?>" alt="User" style="width:72px; height:72px; border-radius:50%; object-fit:cover;">
            <?php else: ?>
                <div style="width:72px; height:72px; border-radius:50%; background:#3a1a5a; display:flex; align-items:center; justify-content:center; font-size:2rem; font-weight:bold; color:#fff;">
                    <?= mb_strtoupper(mb_substr($profile['name'], 0, 1)) ?>
                </div>
            <?php endif; ?>
            <div class="user-details">
                <h2 class="user-name" style="margin:0; color:#d8b4fe;"><?= htmlspecialchars($profile['name']) ?></h2>
                <p class="user-email" style="margin:4px 0 0; color:#888;"><?= htmlspecialchars($profile['email']) ?></p>
            </div>
        </section>

        <nav class="tabs-navigation">
            <?php foreach (['artists'=>'🎤 Виконавці','tracks'=>'🎵 Пісні','genres'=>'🎭 Жанри','albums'=>'💿 Альбоми'] as $key=>$label): ?>
            <a href="?id=<?= $profileId ?>&tab=<?= $key ?>&limit=<?= $limit ?>&time_range=<?= $time_range ?>"
               class="tab-item <?= $tab===$key?'active':'' ?>"><?= $label ?></a>
            <?php endforeach; ?>
        </nav>

        <form method="get" class="filter-controls">
            <input type="hidden" name="id" value="<?= $profileId ?>">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <label>Кількість:
                <select name="limit" onchange="this.form.submit()">
                    <?php foreach ([10,20,50] as $n): ?>
                    <option value="<?= $n ?>" <?= $limit==$n?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Період:
                <select name="time_range" onchange="this.form.submit()">
                    <option value="short_term"  <?= $time_range==='short_term' ?'selected':'' ?>>4 тижні</option>
                    <option value="medium_term" <?= $time_range==='medium_term'?'selected':'' ?>>6 місяців</option>
                    <option value="long_term"   <?= $time_range==='long_term'  ?'selected':'' ?>>Весь час</option>
                </select>
            </label>
        </form>

        <section>
            <?php if (empty($topArtists) && empty($topTracks) && empty($topGenres) && empty($topAlbums)): ?>
                <p style="color:#888;">Не вдалося завантажити музику. Можливо, у <?= htmlspecialchars($profile['name']) ?> немає даних за цей період.</p>
            <?php endif; ?>

            <?php if ($tab === 'artists'): ?>
                <ol class="items-list">
                    <?php foreach ($topArtists as $i => $a): ?>
                    <li class="item-card">
                        <div class="item-rank"><?= $i+1 ?></div>
                        <a href="<?= $a['external_urls']['spotify'] ?>" target="_blank">
                            <img src="<?= $a['images'][0]['url'] ?? 'https://via.placeholder.com/70?text=A' ?>" class="item-image">
                        </a>
                        <div class="item-info">
                            <strong><?= htmlspecialchars($a['name']) ?></strong>
                            <small><?= htmlspecialchars(implode(', ', array_slice($a['genres'], 0, 3))) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>

            <?php elseif ($tab === 'tracks'): ?>
                <ol class="items-list">
                    <?php foreach ($topTracks as $i => $t): ?>
                    <li class="item-card">
                        <div class="item-rank"><?= $i+1 ?></div>
                        <a href="<?= $t['external_urls']['spotify'] ?>" target="_blank">
                            <img src="<?= $t['album']['images'][0]['url'] ?? 'https://via.placeholder.com/70?text=T' ?>" class="item-image">
                        </a>
                        <div class="item-info">
                            <strong><?= htmlspecialchars($t['name']) ?></strong>
                            <small><?= htmlspecialchars($t['artists'][0]['name']) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>

            <?php elseif ($tab === 'genres'): ?>
                <ol style="color:#ccc; padding-left:1.5rem;">
                    <?php foreach ($topGenres as $g => $c): ?>
                    <li style="margin-bottom:6px;"><?= htmlspecialchars($g) ?> <span style="color:#888;">— <?= $c ?> разів</span></li>
                    <?php endforeach; ?>
                </ol>

            <?php elseif ($tab === 'albums'): ?>
                <ol class="items-list">
                    <?php foreach ($topAlbums as $i => $al): ?>
                    <li class="item-card">
                        <div class="item-rank"><?= $i+1 ?></div>
                        <a href="<?= $al['url'] ?>" target="_blank">
                            <img src="<?= $al['image'] ?>" class="item-image">
                        </a>
                        <div class="item-info">
                            <strong><?= htmlspecialchars($al['name']) ?></strong>
                            <small><?= htmlspecialchars(implode(', ', $al['artists'])) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>
    </div>

    <div class="movies-sidebar">
        
        <div class="movie-tabs">
            <button class="movie-tab-btn active" onclick="switchMovieTab('favs', this)">💜 Обрані (<?= count($favMovies) ?>)</button>
            <button class="movie-tab-btn" onclick="switchMovieTab('liked', this)">⬆️ Лайкнуті (<?= count($likedMovies) ?>)</button>
        </div>

        <div id="list-favs">
            <?php if (empty($favMovies)): ?>
                <p style="color:#666;"><?= htmlspecialchars($profile['name']) ?> ще не додав жодного фільму в закладки.</p>
            <?php else: ?>
                <ul class="movies-list">
                    <?php foreach ($favMovies as $movie): ?>
                    <li class="movie-list-item">
                        <a href="movie.php?id=<?= $movie['tmdb_id'] ?>">
                            <?php if (!empty($movie['image_url'])): ?>
                            <img src="<?= htmlspecialchars($movie['image_url']) ?>" alt="Постер">
                            <?php endif; ?>
                        </a>
                        <div class="movie-info">
                            <strong><?= htmlspecialchars($movie['title']) ?></strong>
                            <small><?= htmlspecialchars(mb_strimwidth($movie['description'] ?: $movie['description_en'], 0, 80, '…')) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div id="list-liked" style="display:none;">
            <?php if (empty($likedMovies)): ?>
                <p style="color:#666;"><?= htmlspecialchars($profile['name']) ?> ще не лайкнув жодного фільму.</p>
            <?php else: ?>
                <ul class="movies-list">
                    <?php foreach ($likedMovies as $movie): ?>
                    <li class="movie-list-item">
                        <a href="movie.php?id=<?= $movie['tmdb_id'] ?>">
                            <?php if (!empty($movie['image_url'])): ?>
                            <img src="<?= htmlspecialchars($movie['image_url']) ?>" alt="Постер">
                            <?php endif; ?>
                        </a>
                        <div class="movie-info">
                            <strong><?= htmlspecialchars($movie['title']) ?></strong>
                            <small><?= htmlspecialchars(mb_strimwidth($movie['description'] ?: $movie['description_en'], 0, 80, '…')) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

    </div>
</main>

<script>
function switchMovieTab(tabName, btn) {
    document.getElementById('list-favs').style.display = 'none';
    document.getElementById('list-liked').style.display = 'none';
    
    document.querySelectorAll('.movie-tab-btn').forEach(b => b.classList.remove('active'));
    
    document.getElementById('list-' + tabName).style.display = 'block';
    btn.classList.add('active');
}
</script>
</body>
</html>