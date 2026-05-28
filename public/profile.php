<?php
// public/profile.php — Універсальний профіль (свій кабінет або чужа сторінка)
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../services/spotify_service.php';

require_auth();
session_start();

if (isset($_POST['logout'])) logout();

// Якщо параметр id відсутній або збігається з поточним юзером — це режим власного кабінету
$profileId = (int)($_GET['id'] ?? 0);
if (!$profileId || $profileId === $CURRENT_USER_ID) {
    $profileId = $CURRENT_USER_ID;
    $isMe = true;
} else {
    $isMe = false;
}

// --- Дані профілю з БД ---
$stmt = $pdo->prepare("SELECT id, name, email FROM users WHERE id = ?");
$stmt->execute([$profileId]);
$profile = $stmt->fetch();

// Якщо користувача з таким ID немає в базі взагалі — показуємо 404
if (!$profile) {
    include __DIR__ . '/404.php';
    exit();
}

// --- Визначаємо статус дружби (тільки якщо дивимось на чужий профіль) ---
$friendStatus = 'none';
if (!$isMe) {
    $stmt = $pdo->prepare(
        "SELECT status, requester_id FROM friendships
         WHERE (requester_id = ? AND receiver_id = ?)
            OR (requester_id = ? AND receiver_id = ?)"
    );
    $stmt->execute([$CURRENT_USER_ID, $profileId, $profileId, $CURRENT_USER_ID]);
    $friendRow = $stmt->fetch();

    if ($friendRow) {
        if ($friendRow['status'] === 'accepted') {
            $friendStatus = 'friends';
        } elseif ($friendRow['status'] === 'pending') {
            $friendStatus = ((int)$friendRow['requester_id'] === $CURRENT_USER_ID) ? 'sent' : 'incoming';
        }
    }
}

// --- Spotify Статистика ---
$access_token = get_valid_spotify_token($pdo, $profileId);

$limit      = (int)($_GET['limit'] ?? 10);
$time_range = $_GET['time_range'] ?? 'medium_term';
$tab        = $_GET['tab'] ?? 'artists';

$topArtists = [];
$topTracks  = [];
$topGenres  = [];
$topAlbums  = [];

if ($access_token) {
    // 1. Створюємо унікальний ключ кешу (для конкретного юзера і періоду часу)
    $cacheKey = "spotify_data_{$profileId}_{$time_range}";
    
    // 2. Перевіряємо, чи є дані в кеші і чи вони не старіші за 1 годину (3600 секунд)
    if (!isset($_SESSION[$cacheKey]) || (time() - ($_SESSION[$cacheKey.'_time'] ?? 0)) > 3600) {
        // Якщо кешу немає або він старий — робимо запит до Spotify (це займе 1-2 секунди, але лише 1 раз!)
        $allArtists = get_spotify_top_items($access_token, 'artists', 50, $time_range);
        $allTracks  = get_spotify_top_items($access_token, 'tracks',  50, $time_range);
        
        // Зберігаємо свіжі дані в сесію
        $_SESSION[$cacheKey] = [
            'artists' => $allArtists,
            'tracks'  => $allTracks
        ];
        $_SESSION[$cacheKey.'_time'] = time();
    } else {
        // Якщо кеш є — МИТТЄВО дістаємо дані з пам'яті сервера (0 мілісекунд)
        $allArtists = $_SESSION[$cacheKey]['artists'];
        $allTracks  = $_SESSION[$cacheKey]['tracks'];
    }

    // --- Далі йде швидка локальна генерація вкладок з готових даних ---
    
    // Вкладки: Виконавці та Пісні
    $topArtists = array_slice($allArtists, 0, $limit);
    $topTracks  = array_slice($allTracks, 0, $limit);

    // Вкладка: Жанри
    foreach ($allArtists as $a) {
        foreach ($a['genres'] as $g) {
            $k = ucwords(str_replace('-', ' ', $g));
            $topGenres[$k] = ($topGenres[$k] ?? 0) + 1;
        }
    }
    arsort($topGenres);
    $topGenres = array_slice($topGenres, 0, $limit, true);

    // Вкладка: Альбоми
    $map = [];
    foreach ($allTracks as $t) {
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

// Аватарка та ім'я користувача зі Spotify
$spotifyProfileImage = null;
if ($access_token) {
    $spotifyData = get_spotify_profile($access_token);
    if (!empty($spotifyData['images'][0]['url'])) {
        $spotifyProfileImage = $spotifyData['images'][0]['url'];
    }
    if ($isMe && !empty($spotifyData['display_name'])) {
        $profile['name'] = $spotifyData['display_name'];
    }
}

// --- Списки фільмів для сайдбару ---
$stmt = $pdo->prepare(
    "SELECT m.id, m.tmdb_id, m.title, m.image_url, m.description, m.description_en
     FROM favorites f
     JOIN movies m ON m.tmdb_id = f.movie_id
     WHERE f.user_id = ?
     ORDER BY f.created_at DESC"
);
$stmt->execute([$profileId]);
$favMovies = $stmt->fetchAll();

$stmt = $pdo->prepare(
    "SELECT m.id, m.tmdb_id, m.title, m.image_url, m.description, m.description_en
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
    <?php 
    $pageTitle = $isMe ? "VibeMatch — Мій Кабінет" : "VibeMatch — " . $profile['name'];
    include __DIR__ . '/../views/partials/head.php'; 
    ?>
</head>
<body>

<?php include __DIR__ . '/../views/partials/header.php'; ?>

<main class="cabinet-main main-content">
    
    <div class="spotify-content">
        <section class="profile-header-card">
            <?php if (!empty($spotifyProfileImage)): ?>
                <img src="<?= htmlspecialchars($spotifyProfileImage) ?>" alt="User" class="profile-avatar-large">
            <?php else: ?>
                <div class="profile-avatar-placeholder">
                    <?= mb_strtoupper(mb_substr($profile['name'], 0, 1)) ?>
                </div>
            <?php endif; ?>
            
            <div class="user-details">
                <h2 class="user-name" style="margin:0; color:#d8b4fe;"><?= htmlspecialchars($profile['name']) ?></h2>
                <p class="user-email" style="margin:4px 0 0; color:#888;"><?= htmlspecialchars($profile['email']) ?></p>
            </div>

            <?php if (!$isMe): ?>
            <div class="profile-actions-wrapper">
                <?php if ($friendStatus === 'none'): ?>
                    <form method="post" action="../actions/friend_request.php">
                        <input type="hidden" name="action" value="send">
                        <input type="hidden" name="target_id" value="<?= $profileId ?>">
                        <button type="submit" class="btn glass-btn" style="background: rgba(124, 58, 237, 0.8); color: white;">+ Додати в друзі</button>
                    </form>
                <?php elseif ($friendStatus === 'sent'): ?>
                    <form method="post" action="../actions/friend_request.php">
                        <input type="hidden" name="action" value="remove">
                        <input type="hidden" name="target_id" value="<?= $profileId ?>">
                        <button type="submit" class="btn glass-btn" style="background: rgba(85, 85, 85, 0.8); color: white;">Скасувати запит</button>
                    </form>
                <?php elseif ($friendStatus === 'incoming'): ?>
                    <form method="post" action="../actions/friend_request.php">
                        <input type="hidden" name="action" value="accept">
                        <input type="hidden" name="target_id" value="<?= $profileId ?>">
                        <button type="submit" class="btn glass-btn" style="background: rgba(22, 163, 74, 0.8); color: white;">✓ Прийняти запит</button>
                    </form>
                <?php elseif ($friendStatus === 'friends'): ?>
                    <button class="btn glass-btn btn-following" onclick="openRemoveModal()">
                        <span class="text-normal">👤✓ Ви друзі</span>
                        <span class="text-hover">✖ Видалити</span>
                    </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </section>

        <nav class="tabs-navigation">
            <?php foreach (['artists'=>'🎤 Виконавці', 'tracks'=>'🎵 Пісні', 'genres'=>'🎭 Жанри', 'albums'=>'💿 Альбоми'] as $key => $label): ?>
                <button type="button" 
                        class="tab-item <?= $tab === $key ? 'active' : '' ?>" 
                        onclick="switchSpotifyTab('<?= $key ?>', this)">
                    <?= $label ?>
                </button>
            <?php endforeach; ?>
        </nav>

        <form method="get" class="filter-controls">
            <input type="hidden" name="id" value="<?= $profileId ?>">
            <input type="hidden" name="tab" id="activeTabInput" value="<?= htmlspecialchars($tab) ?>">
            <label class="filter-label">Кількість:
                <select class='filter-select' name="limit" onchange="this.form.submit()">
                    <?php foreach ([10,20,50] as $n): ?>
                    <option value="<?= $n ?>" <?= $limit==$n?'selected':'' ?>><?= $n ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="filter-label">Період:
                <select class='filter-select' name="time_range" onchange="this.form.submit()">
                    <option value="short_term"  <?= $time_range==='short_term' ?'selected':'' ?>>4 тижні</option>
                    <option value="medium_term" <?= $time_range==='medium_term'?'selected':'' ?>>6 місяців</option>
                    <option value="long_term"   <?= $time_range==='long_term'  ?'selected':'' ?>>Весь час</option>
                </select>
            </label>
        </form>

        <section class="content-display">
            <?php if (empty($topArtists) && empty($topTracks) && empty($topGenres) && empty($topAlbums)): ?>
                <div class="no-data-message">Не вдалося завантажити музичні дані за цей період.</div>
            <?php endif; ?>

            <div id="content-artists" class="spotify-tab-content" style="<?= $tab === 'artists' ? 'display:block; opacity:1;' : 'display:none; opacity:0;' ?>; transition: opacity 0.3s ease;">
                <ol class="items-list">
                    <?php foreach ($topArtists as $i => $a): ?>
                    <li class="item-card">
                        <div class="item-rank"><?= $i+1 ?></div>
                        <a href="<?= $a['external_urls']['spotify'] ?>" target="_blank"><img src="<?= $a['images'][0]['url'] ?? 'https://via.placeholder.com/70?text=A' ?>" class="item-image"></a>
                        <div class="item-info">
                            <strong class="item-title"><?= htmlspecialchars($a['name']) ?></strong>
                            <small class="item-subtitle"><?= htmlspecialchars(implode(', ', array_slice($a['genres'], 0, 3))) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <div id="content-tracks" class="spotify-tab-content" style="<?= $tab === 'tracks' ? 'display:block; opacity:1;' : 'display:none; opacity:0;' ?>; transition: opacity 0.3s ease;">
                <ol class="items-list">
                    <?php foreach ($topTracks as $i => $t): ?>
                    <li class="item-card">
                        <div class="item-rank"><?= $i+1 ?></div>
                        <a href="<?= $t['external_urls']['spotify'] ?>" target="_blank"><img src="<?= $t['album']['images'][0]['url'] ?? 'https://via.placeholder.com/70?text=T' ?>" class="item-image"></a>
                        <div class="item-info">
                            <strong class="item-title"><?= htmlspecialchars($t['name']) ?></strong>
                            <small class="item-subtitle"><?= htmlspecialchars($t['artists'][0]['name']) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </div>

            <div id="content-genres" class="spotify-tab-content" style="<?= $tab === 'genres' ? 'display:block; opacity:1;' : 'display:none; opacity:0;' ?>; transition: opacity 0.3s ease;">
                <?php 
                $maxGenreCount = !empty($topGenres) ? max($topGenres) : 1; 
                $rank = 1;
                ?>
                <div class="genre-list">
                    <?php foreach ($topGenres as $g => $c): ?>
                        <?php 
                        $percent = ($c / $maxGenreCount) * 100; 
                        $word = ($c % 10 == 1 && $c % 100 != 11) ? 'раз' : (($c % 10 >= 2 && $c % 10 <= 4 && ($c % 100 < 10 || $c % 100 >= 20)) ? 'рази' : 'разів');
                        ?>
                        <div class="genre-card">
                            <div class="genre-progress" style="width: <?= $percent ?>%;"></div>
                            <div class="genre-content">
                                <span class="genre-rank"><?= $rank++ ?></span>
                                <span class="genre-name"><?= htmlspecialchars($g) ?></span>
                                <span class="genre-count"><?= $c ?> <?= $word ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div id="content-albums" class="spotify-tab-content" style="<?= $tab === 'albums' ? 'display:block; opacity:1;' : 'display:none; opacity:0;' ?>; transition: opacity 0.3s ease;">
                <ol class="items-list">
                    <?php foreach ($topAlbums as $i => $al): ?>
                    <li class="item-card">
                        <div class="item-rank"><?= $i+1 ?></div>
                        <a href="<?= $al['url'] ?>" target="_blank"><img src="<?= $al['image'] ?>" class="item-image"></a>
                        <div class="item-info">
                            <strong class="item-title"><?= htmlspecialchars($al['name']) ?></strong>
                            <small class="item-subtitle"><?= htmlspecialchars(implode(', ', $al['artists'])) ?></small>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        </section>
    </div>

    <div class="movies-sidebar">
        <div class="movie-tabs">
            <button class="movie-tab-btn active" onclick="switchMovieTab('favs', this)">💜 Обрані (<?= count($favMovies) ?>)</button>
            <button class="movie-tab-btn" onclick="switchMovieTab('liked', this)">⬆️ Лайкнуті (<?= count($likedMovies) ?>)</button>
        </div>

        <div id="list-favs">
            <?php if (empty($favMovies)): ?>
                <p style="color:#666;">Немає фільмів у закладках.</p>
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
                        <?php if ($isMe): ?>
                        <form method="post" action="../actions/toggle_favorite.php">
                            <input type="hidden" name="movie_id" value="<?= $movie['id'] ?>">
                            <button type="submit" class="remove-fav" title="Видалити">×</button>
                        </form>
                        <?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <div id="list-liked" style="display:none;">
            <?php if (empty($likedMovies)): ?>
                <p style="color:#666;">Немає оцінених фільмів.</p>
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

<?php if (!$isMe): ?>
<div id="removeFriendModal" class="modal-backdrop" style="display: none;">
    <div class="vibe-card glass-panel modal-content">
        <h3>Видалити з друзів?</h3>
        <p>Ви впевнені, що хочете видалити <strong><?= htmlspecialchars($profile['name']) ?></strong> з друзів?</p>
        <div class="modal-actions">
            <button class="btn glass-btn" onclick="closeRemoveModal()">Скасувати</button>
            <form method="post" action="../actions/friend_request.php" style="margin: 0; display: inline;">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="target_id" value="<?= $profileId ?>">
                <button type="submit" class="btn glass-btn btn-danger-filled">Так, видалити</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="assets/app.js"></script>
<script>
function switchMovieTab(tabName, btn) {
    document.getElementById('list-favs').style.display = 'none';
    document.getElementById('list-liked').style.display = 'none';
    document.querySelectorAll('.movie-tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('list-' + tabName).style.display = 'block';
    btn.classList.add('active');
}

<?php if (!$isMe): ?>
function openRemoveModal() { document.getElementById('removeFriendModal').style.display = 'flex'; }
function closeRemoveModal() { document.getElementById('removeFriendModal').style.display = 'none'; }
window.onclick = function(event) {
    const modal = document.getElementById('removeFriendModal');
    if (event.target === modal) closeRemoveModal();
}
<?php endif; ?>
function switchSpotifyTab(tabName, btn) {
    // 1. Оновлюємо прихований інпут, щоб при зміні фільтрів ми залишилися на цій же вкладці
    document.getElementById('activeTabInput').value = tabName;

    // 2. Знімаємо підсвітку з усіх кнопок і ставимо на активну
    document.querySelectorAll('.tabs-navigation .tab-item').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    // 3. Плавно ховаємо всі списки
    document.querySelectorAll('.spotify-tab-content').forEach(content => {
        content.style.opacity = '0';
        // Чекаємо завершення анімації згасання (0.3с)
        setTimeout(() => {
            content.style.display = 'none';
        }, 150); 
    });

    // 4. Плавно показуємо потрібний список
    setTimeout(() => {
        const activeContent = document.getElementById('content-' + tabName);
        if (activeContent) {
            activeContent.style.display = 'block';
            // Затримка 50мс для того, щоб браузер встиг застосувати display:block перед зміною прозорості
            setTimeout(() => activeContent.style.opacity = '1', 50);
        }
    }, 150);
}
</script>
</body>
</html>