<?php
require_once __DIR__ . '/../core/auth_check.php';
session_start();

if (isset($_POST['logout'])) logout();

$isLoggedIn = $IS_LOGGED_IN;
$showMovies = $_SESSION['show_movies'] ?? false;
$movies     = $_SESSION['last_movies'] ?? [];
$friendFeed = [];
$movieOfDay = null;
$suggestions = [];

if ($isLoggedIn) {
    require_once __DIR__ . '/../config/db_connect.php';

    // -------------------------------------------------------
    // 1. ГЕНЕРАЦІЯ НОВОЇ СТОРІНКИ (Тільки при кліку на кнопку)
    // -------------------------------------------------------
    if (isset($_POST['get_recommendations']) || isset($_POST['refresh_recommendations'])) {
        
        $_SESSION['shown_movie_ids'] = $_SESSION['shown_movie_ids'] ?? [];

        if (isset($_POST['get_recommendations'])) {
            // Перший запуск — скидаємо лічильник оновлень,
            // щоб "Фільм дня" показався саме тут і більше не повторювався
            $_SESSION['vibe_refresh_count'] = 0;
        }

        if (isset($_POST['refresh_recommendations'])) {
            unset($_SESSION['user_genres']); // Оновлюємо Spotify
            // Збільшуємо лічильник — give_movies.php перевіряє його,
            // щоб НЕ додавати "Фільм дня" при повторних оновленнях
            $_SESSION['vibe_refresh_count'] = ($_SESSION['vibe_refresh_count'] ?? 0) + 1;
            // ВАЖЛИВО: Ми більше НЕ робимо unset('shown_feed_activity_ids'), 
            // щоб система пам'ятала, що ти бачив на попередніх сторінках!
        }

        // --- А. Нова порція фільмів (з БД або TMDb) ---
        $movies = include __DIR__ . '/../services/give_movies.php';
        $_SESSION['last_movies'] = $movies;
        $_SESSION['show_movies'] = true;
        $showMovies = true;

        if (is_array($movies)) {
            foreach ($movies as $m) {
                if (!empty($m['id']) && $m['id'] !== -1) {
                    $_SESSION['shown_movie_ids'][] = $m['id'];
                }
            }
        }

        // --- Б. Нова порція активності друзів ---
        $friendFeed = [];
        $stmt = $pdo->prepare("
            SELECT u.id
            FROM friendships f
            JOIN users u ON u.id = IF(f.requester_id = ?, f.receiver_id, f.requester_id)
            WHERE (f.requester_id = ? OR f.receiver_id = ?) AND f.status = 'accepted'
        ");
        $stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID, $CURRENT_USER_ID]);
        $friendIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($friendIds)) {
            $placeholders = implode(',', array_fill(0, count($friendIds), '?'));
            $stmt = $pdo->prepare("
                SELECT af.id, af.type, af.created_at,
                       u.id AS user_id, u.name AS user_name, u.avatar_url AS user_avatar,
                       m.tmdb_id, m.title, m.image_url, m.description, m.description_en
                FROM activity_feed af
                JOIN users u ON u.id = af.user_id
                JOIN movies m ON m.tmdb_id = af.movie_id
                WHERE af.user_id IN ($placeholders)
                ORDER BY af.created_at DESC
                LIMIT 60
            ");
            $stmt->execute($friendIds);
            $rawFeed = $stmt->fetchAll();

            $shownFeedActivityIds = $_SESSION['shown_feed_activity_ids'] ?? [];
            $seenFeedCombos       = []; 

            foreach ($rawFeed as $item) {
                // Пропускаємо те, що вже бачили на минулих сторінках
                if (in_array($item['id'], $shownFeedActivityIds)) continue;

                // Дедуплікація в межах однієї сторінки
                $comboKey = $item['user_id'] . ':' . $item['tmdb_id'];
                if (isset($seenFeedCombos[$comboKey])) continue;

                $seenFeedCombos[$comboKey] = true;
                $friendFeed[] = $item;
                
                // Запам'ятовуємо, щоб більше ніколи не показувати
                $shownFeedActivityIds[] = $item['id'];
            }
            $_SESSION['shown_feed_activity_ids'] = $shownFeedActivityIds;
        }
        // Зберігаємо згенеровану стрічку для F5
        $_SESSION['last_friend_feed'] = $friendFeed;

        // --- В. Нова порція "Можливо знайомі" ---
        $suggestions = [];
        $stmt = $pdo->prepare("
            SELECT IF(requester_id = ?, receiver_id, requester_id)
            FROM friendships
            WHERE (requester_id = ? OR receiver_id = ?) AND status IN ('accepted', 'pending')
        ");
        $stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID, $CURRENT_USER_ID]);
        $excludedIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $excludedIds[] = $CURRENT_USER_ID; 
        
        $placeholders = implode(',', array_fill(0, count($excludedIds), '?'));

        $stmt = $pdo->prepare("
            SELECT u.id AS user_id, u.name, u.avatar_url,
                   COUNT(af2.movie_id) as score,
                   '🎬 Спільні фільми' AS reason_title,
                   CONCAT(COUNT(af2.movie_id), ' збігів у стрічці') AS reason_desc
            FROM users u
            JOIN activity_feed af2 ON af2.user_id = u.id
            JOIN activity_feed af1 ON af1.user_id = ? AND af1.movie_id = af2.movie_id
            WHERE u.id NOT IN ($placeholders)
            GROUP BY u.id
            ORDER BY score DESC
            LIMIT 5
        ");
        $params = array_merge([$CURRENT_USER_ID], $excludedIds);
        $stmt->execute($params);
        $suggestions = $stmt->fetchAll();

        if (count($suggestions) < 3) {
            $existingSuggIds = array_column($suggestions, 'user_id');
            $allExcluded = array_merge($excludedIds, $existingSuggIds);
            if (empty($allExcluded)) $allExcluded = [0]; 
            
            $pl = implode(',', array_fill(0, count($allExcluded), '?'));
            
            $stmt = $pdo->prepare("
                SELECT id AS user_id, name, avatar_url,
                       0 as score,
                       '🎧 Музичний вайб' AS reason_title,
                       'Варто познайомитись' AS reason_desc
                FROM users
                WHERE id NOT IN ($pl)
                ORDER BY RAND()
                LIMIT 3
            ");
            $stmt->execute($allExcluded);
            $randomUsers = $stmt->fetchAll();
            $suggestions = array_merge($suggestions, $randomUsers);
            $suggestions = array_slice($suggestions, 0, 5);
        }
        // Зберігаємо для F5
        $_SESSION['last_suggestions'] = $suggestions;

    } else {
        // -------------------------------------------------------
        // ЗВИЧАЙНЕ ЗАВАНТАЖЕННЯ (F5) - Беремо з сесії
        // -------------------------------------------------------
        $movies      = $_SESSION['last_movies'] ?? [];
        $friendFeed  = $_SESSION['last_friend_feed'] ?? [];
        $suggestions = $_SESSION['last_suggestions'] ?? [];
        $showMovies  = $_SESSION['show_movies'] ?? false;
    }

    // -------------------------------------------------------
    // 2. Фільм дня (не залежить від сесій/кнопок)
    // -------------------------------------------------------
    $todayKey = 'motd_' . date('Y-m-d');
    if (empty($_SESSION[$todayKey])) {
        $dayOfYear  = (int)date('z'); 
        $totalCount = (int)$pdo->query("SELECT COUNT(*) FROM movies")->fetchColumn();
        if ($totalCount > 0) {
            $offset     = $dayOfYear % $totalCount;
            $stmt       = $pdo->prepare("SELECT * FROM movies LIMIT 1 OFFSET ?");
            $stmt->execute([$offset]);
            $motd = $stmt->fetch();
            if ($motd) {
                $_SESSION[$todayKey] = $motd;
            }
        }
    }
    $movieOfDay = $_SESSION[$todayKey] ?? null;
}

// -------------------------------------------------------
// 5. БУДУЄМО СТРІЧКУ
//
// Two shuffled sections separated by a fixed divider:
//
// SECTION A (shuffled):
//   daily recs + movie_of_day + suggestions + friend activity
//
// [ alltime_divider ] — fixed, never moves
//
// SECTION B (shuffled):
//   all-time recs + friend activity
// -------------------------------------------------------

// Split $movies into daily items and all-time items
// give_movies.php puts alltime_divider as a marker between them
$dailyItems   = [];
$allTimeItems = [];
$dividerItem  = null;
$pastDivider  = false;

foreach ($movies as $m) {
    if (isset($m['feed_item_type']) && $m['feed_item_type'] === 'alltime_divider') {
        $dividerItem = $m;
        $pastDivider = true;
        continue;
    }
    if (!$pastDivider) {
        $m['feed_item_type'] = $m['feed_item_type'] ?? 'recommendation';
        $dailyItems[] = $m;
    } else {
        $m['feed_item_type'] = $m['feed_item_type'] ?? 'recommendation';
        $allTimeItems[] = $m;
    }
}

// Prepare friend activity — split half for each section
$friendItems = [];
foreach ($friendFeed as $f) {
    $f['feed_item_type'] = 'friend_activity';
    $friendItems[] = $f;
}
shuffle($friendItems);
$halfFriend  = (int)ceil(count($friendItems) / 2);
$friendsForA = array_slice($friendItems, 0, $halfFriend);
$friendsForB = array_slice($friendItems, $halfFriend);

// Section A: daily + motd + suggestions + first half of friends → shuffle
$sectionA = $dailyItems;
if ($movieOfDay && ($_SESSION['show_movie_of_day'] ?? false)) {
    $motdItem = $movieOfDay;
    $motdItem['feed_item_type'] = 'movie_of_day';
    $sectionA[] = $motdItem;
}
if (!empty($suggestions)) {
    $sectionA[] = ['feed_item_type' => 'suggestions', 'data' => $suggestions];
}
$sectionA = array_merge($sectionA, $friendsForA);
shuffle($sectionA);

// Section B: all-time + second half of friends → shuffle
$sectionB = array_merge($allTimeItems, $friendsForB);
shuffle($sectionB);

// Final feed: A + divider + B
$mixedFeed = $sectionA;
if ($dividerItem) $mixedFeed[] = $dividerItem;
$mixedFeed = array_merge($mixedFeed, $sectionB);
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <?php 
    $pageTitle = "VibeMatch — Стрічка";
    include __DIR__ . '/../views/partials/head.php'; 
    ?>
</head>
<body class="feed-body snapping-active">
    
    <div class="glass-bg">
        <div class="glass-shape shape-1"></div>
        <div class="glass-shape shape-2"></div>
        <div class="glass-shape shape-3"></div>
        <div class="glass-shape shape-4"></div>
        <div class="glass-shape shape-5"></div>
    </div>

    <?php if ($isLoggedIn): ?>
    <?php 
    $headerClass = 'main-header mobile-header glass-header'; 
    include __DIR__ . '/../views/partials/header.php'; 
    ?>
    <?php endif; ?>

    <main class="ab-feed-wrapper">
        <?php if (!$isLoggedIn): ?>
        <!-- ====== ОНБОРДИНГ (незмінений) ====== -->
        <div class="onboarding-screen">
            <div class="ob-blob ob-blob-1"></div>
            <div class="ob-blob ob-blob-2"></div>
            <div class="ob-blob ob-blob-3"></div>
            <div class="ob-top">
                <div class="ob-logo-wrap">
                    <img src="assets/logo.png" alt="VibeMatch" class="ob-logo">
                </div>
                <h1 class="ob-appname">VibeMatch</h1>
                <p class="ob-tagline">Фільми під твій музичний настрій</p>
            </div>
            <div class="ob-features">
                <div class="ob-feature-pill">🎵 Аналіз твого Spotify</div>
                <div class="ob-feature-pill">🎬 Персональні рекомендації</div>
                <div class="ob-feature-pill">👥 Активність друзів</div>
            </div>
            <div class="ob-bottom">
                <a href="../actions/spotify_auth.php" class="ob-spotify-btn">
                    <svg viewBox="0 0 24 24" class="ob-spotify-icon" fill="currentColor">
                        <path d="M12 0C5.4 0 0 5.4 0 12s5.4 12 12 12 12-5.4 12-12S18.66 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/>
                    </svg>
                    Увійти через Spotify
                </a>
                <p class="ob-disclaimer">Ми не зберігаємо твою музику — лише аналізуємо вайб 🎧</p>
            </div>
        </div>

        <?php else: ?>

            <?php if ($showMovies && !empty($mixedFeed)): ?>
                <?php foreach ($mixedFeed as $item): ?>

                    <?php 
                    // ====================================================
                    // ФІЛЬМ ДНЯ
                    // ====================================================
                    if ($item['feed_item_type'] === 'movie_of_day'): ?>
                        <section class="ab-snap-section" data-action-type="movie_of_day">
                            <div class="ab-context-header">
                                <h2 class="ab-headline" style="color: var(--accent-gold, #FFD700); text-shadow: 0 4px 20px rgba(255,215,0,0.4);">фільм дня</h2>
                                <div class="ab-user-badge-wrapper">
                                    <div class="ab-user-avatar">🏆</div>
                                    <span class="ab-listen-pill" style="background: linear-gradient(135deg, #b8860b, #ffd700); color: #000;">
                                        <?= date('d.m') ?>
                                    </span>
                                </div>
                            </div>

                            <div class="ab-media-wrapper">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($item['image_url']) ?>" class="ab-poster" alt="Cover">
                                <?php else: ?>
                                    <div class="ab-poster" style="background: var(--bg-mid);"></div>
                                <?php endif; ?>
                                <div class="ab-floating-reactions">
                                    <button class="ab-reaction-circle" onclick="sendReaction('🔥', <?= $item['tmdb_id'] ?>, this)">🔥</button>
                                    <button class="ab-reaction-circle" onclick="sendReaction('😭', <?= $item['tmdb_id'] ?>, this)">😭</button>
                                    <button class="ab-reaction-circle" onclick="sendReaction('👽', <?= $item['tmdb_id'] ?>, this)">👽</button>
                                </div>
                            </div>

                            <div class="ab-info">
                                <h3 class="ab-title"><?= htmlspecialchars($item['title']) ?></h3>
                                <p class="ab-subtitle">🌍 Вибір дня для всього VibeMatch</p>
                            </div>

                            <div class="ab-actions-bar">
                                <a href="movie.php?id=<?= $item['tmdb_id'] ?>" class="ab-btn ab-btn-icon" title="Детальніше">ℹ️</a>
                                <a href="movie.php?id=<?= $item['tmdb_id'] ?>" class="ab-btn ab-btn-main">
                                    Дивитись 🎬
                                </a>
                                <button class="ab-btn ab-btn-icon" onclick="toggleFavorite(<?= $item['tmdb_id'] ?>, this)" title="В закладки">
                                    💜
                                </button>
                            </div>
                        </section>

                    <?php 
                    // ====================================================
                    // МОЖЛИВО ЗНАЙОМІ
                    // ====================================================
                    elseif ($item['feed_item_type'] === 'suggestions'): ?>
                        <section class="ab-snap-section" data-action-type="suggestions">
                            <div class="ab-context-header">
                                <h2 class="ab-headline" style="color: var(--accent-teal, #4dd9ac); text-shadow: 0 4px 20px rgba(77,217,172,0.4);">можливо знайомі</h2>
                                <div class="ab-user-badge-wrapper">
                                    <div class="ab-user-avatar">🤝</div>
                                    <span class="ab-listen-pill" style="background: var(--accent-teal, #4dd9ac); color: #000;">
                                        нові люди
                                    </span>
                                </div>
                            </div>

                            <div class="glass-panel" style="width: 100%; max-width: 450px; margin: 0 auto; padding: 1.5rem; border-radius: 24px; box-sizing: border-box;">
                                <div class="suggestions-list" style="display: flex; flex-direction: column; gap: 1rem; width: 100%;">
                                    
                                    <?php foreach ($item['data'] as $sugg): ?>
                                    <div class="suggestion-card" style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; background: rgba(0, 0, 0, 0.25); border-radius: 16px; padding: 1rem; border: 1px solid rgba(255, 255, 255, 0.05);">
                                        
                                        <?php if (!empty($sugg['avatar_url'])): ?>
                                            <img src="<?= htmlspecialchars($sugg['avatar_url']) ?>" alt="Avatar" style="width: 50px; height: 50px; border-radius: 50%; object-fit: cover; border: 2px solid var(--accent-teal, #4dd9ac); flex-shrink: 0;">
                                        <?php else: ?>
                                            <div style="width: 50px; height: 50px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: var(--accent-violet-deep); color: #fff; font-size: 1.2rem; font-weight: bold; border: 2px solid var(--accent-teal, #4dd9ac); flex-shrink: 0;">
                                                <?= mb_strtoupper(mb_substr($sugg['name'], 0, 1)) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div style="flex: 1; min-width: 0; text-align: left;">
                                            <div style="font-weight: 700; font-size: 1rem; margin-bottom: 0.2rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #fff;">
                                                <?= htmlspecialchars($sugg['name']) ?>
                                            </div>
                                            <div style="font-size: 0.8rem; color: var(--accent-teal, #4dd9ac); font-weight: 500; margin-bottom: 0.1rem; display: flex; align-items: center; gap: 4px;">
                                                <?= htmlspecialchars($sugg['reason_title']) ?>
                                            </div>
                                            <div style="font-size: 0.75rem; color: rgba(255,255,255,0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <?= htmlspecialchars($sugg['reason_desc']) ?>
                                            </div>
                                        </div>

                                        <button class="ab-btn" style="background: var(--accent-teal, #4dd9ac); color: #000; padding: 0.5rem 0.8rem; border-radius: 12px; font-weight: 600; font-size: 0.85rem; flex-shrink: 0; border: none; cursor: pointer; box-shadow: 0 4px 15px rgba(77,217,172,0.2);" onclick="handleFriendAction('send', <?= $sugg['user_id'] ?>, this)">
                                            ✚
                                        </button>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                </div>
                            </div>
                        </section>

                    <?php
                    // ====================================================
                    // РОЗПОДІЛЬНИК: ЩОДЕННИЙ → ВСІ ЧАСИ
                    // ====================================================
                    elseif ($item['feed_item_type'] === 'alltime_divider'): ?>
                        <section class="ab-snap-section" data-action-type="alltime_divider">
                            <div style="display:flex; flex-direction:column; align-items:center; justify-content:center; gap:1.2rem; text-align:center; padding: 2rem;">
                                <div style="font-size:2.5rem;">🎬</div>
                                <h2 class="ab-headline" style="color:var(--accent-violet-soft); margin:0;">найкраще за всі часи</h2>
                                <p style="color:rgba(255,255,255,0.5); font-size:0.9rem; margin:0;">
                                    Твій денний вайб — переглянуто.<br>
                                    Далі — топові фільми під твій музичний смак.
                                </p>
                                <?php if (!empty($item['daily_genres'])): ?>
                                <div style="display:flex; flex-wrap:wrap; gap:0.5rem; justify-content:center; margin-top:0.5rem;">
                                    <?php foreach (array_slice($item['daily_genres'], 0, 3) as $dg): ?>
                                        <span class="ab-listen-pill" style="font-size:0.75rem;"><?= htmlspecialchars($dg) ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </section>

                    <?php 
                    // ====================================================
                    // ПЕРСОНАЛЬНА РЕКОМЕНДАЦІЯ
                    // ====================================================
                    elseif ($item['feed_item_type'] === 'recommendation'): ?>
                        <section class="ab-snap-section" data-action-type="recommendation">
                            <div class="ab-context-header">
                                <?php if (!empty($item['is_daily'])): ?>
                                    <h2 class="ab-headline" style="
                                        background: linear-gradient(90deg, #f472b6, #a78bfa);
                                        -webkit-background-clip: text;
                                        -webkit-text-fill-color: transparent;
                                        background-clip: text;
                                        text-shadow: none;
                                        filter: drop-shadow(0 0 12px rgba(167,139,250,0.5));
                                    ">✦ денний вайб ✦</h2>
                                <?php else: ?>
                                    <h2 class="ab-headline">твій вайб</h2>
                                <?php endif; ?>
                                <div class="ab-user-badge-wrapper">
                                    <div class="ab-user-avatar"><?= !empty($item['is_daily']) ? '🌟' : '🎧' ?></div>
                                    <span class="ab-listen-pill" <?= !empty($item['is_daily']) ? 'style="background: linear-gradient(135deg, #f472b6, #a78bfa); color: #fff;"' : '' ?>><?= $item['match_percent'] ?? rand(75,99) ?>% метч</span>
                                </div>
                            </div>

                            <div class="ab-media-wrapper">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($item['image_url']) ?>" class="ab-poster" alt="Cover">
                                <?php else: ?>
                                    <div class="ab-poster" style="background: var(--bg-mid);"></div>
                                <?php endif; ?>
                                <div class="ab-floating-reactions">
                                    <button class="ab-reaction-circle" onclick="sendReaction('🔥', <?= $item['tmdb_id'] ?>, this)">🔥</button>
                                    <button class="ab-reaction-circle" onclick="sendReaction('😭', <?= $item['tmdb_id'] ?>, this)">😭</button>
                                    <button class="ab-reaction-circle" onclick="sendReaction('👽', <?= $item['tmdb_id'] ?>, this)">👽</button>
                                </div>
                            </div>

                            <div class="ab-info">
                                <h3 class="ab-title"><?= htmlspecialchars($item['title']) ?></h3>
                                <p class="ab-subtitle">Жанр: <?= htmlspecialchars($item['trigger_genre'] ?? 'Музика') ?></p>
                            </div>

                            <div class="ab-actions-bar">
                                <a href="movie.php?id=<?= $item['tmdb_id'] ?>" class="ab-btn ab-btn-icon">ℹ️</a>
                                <button class="ab-btn ab-btn-main" onclick="rateGenreMatch(<?= $item['tmdb_id'] ?>, this)">
                                    В точку! 🎯
                                </button>
                                <button class="ab-btn ab-btn-icon" onclick="toggleFavorite(<?= $item['tmdb_id'] ?>, this)" title="В закладки">
                                    💜
                                </button>
                            </div>
                        </section>

                    <?php 
                    // ====================================================
                    // АКТИВНІСТЬ ДРУГА
                    // ====================================================
                    elseif ($item['feed_item_type'] === 'friend_activity'): 
                        $actionText = match($item['type']) {
                            'favorite'       => 'у закладках',
                            'vote_up'        => 'лайкнув(ла)',
                            'vote_down'      => 'дизлайкнув(ла)',
                            'react_fire'     => 'вогонь',
                            'react_cry'      => 'до сліз',
                            'react_alien'    => 'дивний вайб',
                            'accuracy_match' => 'точність',
                            default          => 'взаємодія',
                        };
                    ?>
                        <section class="ab-snap-section" data-action-type="<?= htmlspecialchars($item['type']) ?>">
                            <div class="ab-context-header">
                                <h2 class="ab-headline" style="color: var(--accent-violet-soft); text-shadow: 0 4px 20px rgba(196, 173, 255, 0.4);">
                                    <?= htmlspecialchars($item['user_name']) ?>
                                </h2>
                                <div class="ab-user-badge-wrapper">
                                    <?php if (!empty($item['user_avatar'])): ?>
                                        <img src="<?= htmlspecialchars($item['user_avatar']) ?>" class="ab-user-avatar" alt="Avatar">
                                    <?php else: ?>
                                        <div class="ab-user-avatar"><?= mb_strtoupper(mb_substr($item['user_name'], 0, 1)) ?></div>
                                    <?php endif; ?>
                                    <span class="ab-listen-pill" style="background: var(--accent-violet-deep);"><?= $actionText ?></span>
                                </div>
                            </div>

                            <div class="ab-media-wrapper">
                                <?php if (!empty($item['image_url'])): ?>
                                    <img src="<?= htmlspecialchars($item['image_url']) ?>" class="ab-poster" alt="Cover">
                                <?php endif; ?>
                                <?php 
                                $ctxData = [
                                    'type'        => 'reply',
                                    'title'       => $item['title'],
                                    'image'       => $item['image_url'],
                                    'friend_name' => $item['user_name'],
                                ];
                                $ctxJson = htmlspecialchars(json_encode($ctxData), ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="ab-floating-reactions">
                                    <button class="ab-reaction-circle" onclick="sendReaction('🔥', <?= $item['tmdb_id'] ?>, this, <?= $item['user_id'] ?>, '<?= $ctxJson ?>')">🔥</button>
                                    <button class="ab-reaction-circle" onclick="sendReaction('😭', <?= $item['tmdb_id'] ?>, this, <?= $item['user_id'] ?>, '<?= $ctxJson ?>')">😭</button>
                                    <button class="ab-reaction-circle" onclick="sendReaction('👽', <?= $item['tmdb_id'] ?>, this, <?= $item['user_id'] ?>, '<?= $ctxJson ?>')">👽</button>
                                </div>
                            </div>

                            <div class="ab-info">
                                <h3 class="ab-title"><?= htmlspecialchars($item['title']) ?></h3>
                                <p class="ab-subtitle time-ago feed-time" data-utc="<?= htmlspecialchars($item['created_at']) ?>"></p>
                            </div>

                            <div class="ab-actions-bar">
                                <a href="movie.php?id=<?= $item['tmdb_id'] ?>" class="ab-btn ab-btn-icon">ℹ️</a>
                                <button class="ab-btn ab-btn-main" onclick="openChat(<?= $item['user_id'] ?>, '<?= htmlspecialchars(addslashes($item['user_name'])) ?>', '<?= $ctxJson ?>')">
                                    💬 Відповісти
                                </button>
                            </div>
                        </section>
                    <?php endif; ?>

                <?php endforeach; ?>

            <?php elseif ($showMovies): ?>
                <div class="ab-snap-section">
                    <div class="message-card no-recommendations glass-panel" style="max-width: 400px;">
                        <p>Стрічка порожня. Поки немає ні рекомендацій, ні активності друзів.</p>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Кнопка генерації/оновлення вайбу -->
            <div class="ab-snap-section">
                <form method="post" id="vibe-form">
                    <button 
                        type="submit" 
                        name="<?= $showMovies ? 'refresh_recommendations' : 'get_recommendations' ?>" 
                        class="btn refresh-btn glass-btn glow-effect" 
                        id="vibe-btn"
                        style="padding: 15px 30px; font-size: 1.1rem;"
                    >
                        <?= $showMovies ? '🔄 Оновити мій вайб' : '✨ Згенерувати мій вайб' ?>
                    </button>
                </form>
            </div>

            <!-- Екран завантаження — прихований за замовчуванням -->
            <div id="vibe-loading-screen" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(10,8,20,0.97); flex-direction:column; align-items:center; justify-content:center; gap:1.5rem;">
                <div style="font-size:3rem; animation: vibe-spin 1.8s linear infinite;">🎧</div>
                <p style="font-size:1.3rem; font-weight:700; color:#fff; letter-spacing:0.03em;" id="vibe-loading-text">Аналізуємо твій вайб...</p>
                <p style="font-size:0.85rem; color:rgba(255,255,255,0.45);">Перший раз може зайняти до 30 секунд</p>
                <div style="width:220px; height:4px; background:rgba(255,255,255,0.1); border-radius:99px; overflow:hidden;">
                    <div id="vibe-loading-bar" style="height:100%; width:0%; background:linear-gradient(90deg,#a78bfa,#38bdf8); border-radius:99px; transition:width 0.4s ease;"></div>
                </div>
            </div>
            <style>
                @keyframes vibe-spin { to { transform: rotate(360deg); } }
            </style>

        <?php endif; ?>
    </main>

    <?php include __DIR__ . '/../views/partials/chat_drawer.php'; ?>
    <script src="assets/app.js"></script>
    <script>
    (function () {
        var form   = document.getElementById('vibe-form');
        var loader = document.getElementById('vibe-loading-screen');
        var bar    = document.getElementById('vibe-loading-bar');
        var text   = document.getElementById('vibe-loading-text');
        if (!form || !loader) return;

        var messages = [
            'Аналізуємо твій вайб...',
            'Запитуємо Spotify...',
            'Підбираємо фільми під настрій...',
            'Майже готово...',
        ];

        // Total fake duration in ms. Progress will crawl from 0 → 92% over this time,
        // slowing down near the end so it never "finishes" before the server responds.
        var DURATION = 28000;
        var TICK     = 500; // ms between ticks

        form.addEventListener('submit', function () {
            loader.style.display = 'flex';

            var startTime = Date.now();

            var interval = setInterval(function () {
                var elapsed  = Date.now() - startTime;
                var linear   = Math.min(elapsed / DURATION, 1);

                // Ease-out curve: fast start, slows to a crawl near 92%
                // Uses sqrt so the bar rushes to ~50% quickly then drags
                var eased    = Math.sqrt(linear) * 92;
                bar.style.width = eased.toFixed(1) + '%';

                // Switch message based on real elapsed time, not progress %
                // This guarantees messages change even if bar moves differently
                if (elapsed > 4000  && text.textContent === messages[0]) text.textContent = messages[1];
                if (elapsed > 10000 && text.textContent === messages[1]) text.textContent = messages[2];
                if (elapsed > 20000 && text.textContent === messages[2]) text.textContent = messages[3];

            }, TICK);

            // Do NOT use beforeunload — it fires during form submit navigation
            // and would immediately jump the bar to 100% before the server even responds.
            // The interval just keeps running until the page unloads naturally.
        });
    })();
    </script>

    <?php if ($isLoggedIn && !empty($suggestions)): ?>
    <script>
    // handleFriendAction вже повинна бути у app.js
    // Цей блок — на випадок, якщо її потрібно ініціалізувати зі стрічки
    document.addEventListener('DOMContentLoaded', function() {
        // Нічого додаткового не потрібно — handleFriendAction вже є в app.js
    });
    </script>
    <?php endif; ?>

</body>
</html>