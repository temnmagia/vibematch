<?php
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db_connect.php';

require_auth();
session_start();

if (isset($_POST['logout'])) logout();

// --- Пошук користувачів ---
$searchResults = [];
$searchQuery   = trim($_GET['search'] ?? '');

if ($searchQuery !== '') {
    $stmt = $pdo->prepare(
        "SELECT id, name, email FROM users
         WHERE (name LIKE ? OR email LIKE ?) AND id <> ?
         LIMIT 20"
    );
    $stmt->execute(["%$searchQuery%", "%$searchQuery%", $CURRENT_USER_ID]);
    $searchResults = $stmt->fetchAll();
}

// --- Статус дружби для результатів пошуку ---
function getFriendshipStatus(PDO $pdo, int $me, int $other): string {
    $stmt = $pdo->prepare(
        "SELECT status, requester_id FROM friendships
         WHERE (requester_id = ? AND receiver_id = ?)
            OR (requester_id = ? AND receiver_id = ?)"
    );
    $stmt->execute([$me, $other, $other, $me]);
    $row = $stmt->fetch();
    if (!$row) return 'none';
    if ($row['status'] === 'accepted') return 'friends';
    if ($row['status'] === 'pending' && (int)$row['requester_id'] === $me) return 'sent';
    if ($row['status'] === 'pending' && (int)$row['requester_id'] === $other) return 'incoming';
    return 'none';
}

// --- Список друзів ---
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.spotify_token
     FROM friendships f
     JOIN users u ON u.id = IF(f.requester_id = ?, f.receiver_id, f.requester_id)
     WHERE (f.requester_id = ? OR f.receiver_id = ?)
       AND f.status = 'accepted'
     ORDER BY u.name"
);
$stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID, $CURRENT_USER_ID]);
$friends = $stmt->fetchAll();

// Підтягуємо аватарки друзів зі Spotify
foreach ($friends as &$f) {
    $f['avatar'] = null;
    if (!empty($f['spotify_token'])) {
        $res = @file_get_contents("https://api.spotify.com/v1/me", false, stream_context_create([
            "http" => ["header" => "Authorization: Bearer {$f['spotify_token']}", "timeout" => 1, "ignore_errors" => true]
        ]));
        if ($res) {
            $data = json_decode($res, true);
            $f['avatar'] = $data['images'][0]['url'] ?? null;
        }
    }
}
unset($f);

// --- Вхідні запити ---
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, f.created_at
     FROM friendships f
     JOIN users u ON u.id = f.requester_id
     WHERE f.receiver_id = ? AND f.status = 'pending'
     ORDER BY f.created_at DESC"
);
$stmt->execute([$CURRENT_USER_ID]);
$incomingRequests = $stmt->fetchAll();

// --- Надіслані запити ---
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email
     FROM friendships f
     JOIN users u ON u.id = f.receiver_id
     WHERE f.requester_id = ? AND f.status = 'pending'
     ORDER BY f.created_at DESC"
);
$stmt->execute([$CURRENT_USER_ID]);
$sentRequests = $stmt->fetchAll();

// --- Стрічка активності друзів ---
$friendIds = array_column($friends, 'id');
$friendFeed = [];
if (!empty($friendIds)) {
    $placeholders = implode(',', array_fill(0, count($friendIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT af.type, af.created_at,
                u.id AS user_id, u.name AS user_name,
                m.tmdb_id, m.title, m.image_url
         FROM activity_feed af
         JOIN users u ON u.id = af.user_id
         JOIN movies m ON m.tmdb_id = af.movie_id
         WHERE af.user_id IN ($placeholders)
         ORDER BY af.created_at DESC
         LIMIT 40"
    );
    $stmt->execute($friendIds);
    $friendFeed = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <title>VibeMatch — Друзі</title>
    <link rel="stylesheet" href="styles.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        .friends-layout { display: flex; gap: 2rem; align-items: flex-start; flex-wrap: wrap; }
        .friends-main   { flex: 1; min-width: 320px; }
        .friends-feed   { width: 340px; flex-shrink: 0; }

        .section-card {
            background: #1a0a24;
            border: 1px solid #3a1a4a;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .section-card h3 { margin: 0 0 1rem; color: #d8b4fe; font-size: 1.1rem; }

        .user-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid #2a1a3a;
        }
        .user-row:last-child { border-bottom: none; }
        .user-avatar {
            width: 42px; height: 42px;
            border-radius: 50%;
            background: #3a1a5a;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; color: #d8b4fe; font-size: 1.1rem;
            flex-shrink: 0;
        }
        .user-info { flex: 1; min-width: 0; }
        .user-info strong { display: block; color: #eee; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .user-info small { color: #888; font-size: 0.8rem; }
        .user-actions { display: flex; gap: 6px; flex-shrink: 0; }

        .btn-sm {
            padding: 5px 12px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 0.82rem;
            font-weight: 600;
            transition: opacity 0.2s;
        }
        .btn-sm:hover { opacity: 0.85; }
        .btn-add     { background: #7c3aed; color: #fff; }
        .btn-accept  { background: #16a34a; color: #fff; }
        .btn-reject  { background: #991b1b; color: #fff; }
        .btn-remove  { background: #374151; color: #ccc; }
        .btn-pending { background: #374151; color: #888; cursor: default; }

        .search-form {
            display: flex; gap: 8px; margin-bottom: 1rem;
        }
        .search-form input {
            flex: 1;
            padding: 8px 12px;
            background: #120820;
            border: 1px solid #3a1a4a;
            border-radius: 8px;
            color: #eee;
            outline: none;
        }
        .search-form input:focus { border-color: #7c3aed; }
        .search-form button {
            padding: 8px 16px;
            background: #7c3aed;
            color: #fff;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }

        /* Стрічка активності */
        .feed-item {
            display: flex;
            gap: 10px;
            padding: 10px 0;
            border-bottom: 1px solid #2a1a3a;
            align-items: center;
        }
        .feed-item:last-child { border-bottom: none; }
        .feed-poster {
            width: 40px; height: 60px;
            object-fit: cover;
            border-radius: 4px;
            flex-shrink: 0;
        }
        .feed-poster-placeholder {
            width: 40px; height: 60px;
            background: #2a1a3a;
            border-radius: 4px;
            flex-shrink: 0;
        }
        .feed-text { flex: 1; font-size: 0.85rem; color: #ccc; }
        .feed-text strong { color: #d8b4fe; }
        .feed-text a { color: #a78bfa; text-decoration: none; }
        .feed-text a:hover { text-decoration: underline; }
        .feed-time { font-size: 0.75rem; color: #666; display: block; margin-top: 3px; }

        .badge {
            display: inline-block;
            background: #7c3aed;
            color: #fff;
            font-size: 0.7rem;
            padding: 2px 7px;
            border-radius: 10px;
            margin-left: 6px;
            vertical-align: middle;
        }

        .empty-state { color: #666; font-size: 0.9rem; padding: 0.5rem 0; }

        @media (max-width: 768px) {
            .friends-feed { width: 100%; }
        }
    </style>
</head>
<body>
<header class="main-header">
    <h1 class="app-title"><a href="index.php">VibeMatch</a></h1>
    <nav class="header-nav">
        <a href="cabinet.php" class="btn cabinet-btn">Кабінет</a>
        <a href="friends.php" class="btn" style="background:#3a1a5a;">Друзі
            <?php if (count($incomingRequests) > 0): ?>
                <span class="badge"><?= count($incomingRequests) ?></span>
            <?php endif; ?>
        </a>
        <form method="post" style="display:inline;">
            <button type="submit" name="logout" class="btn logout-btn">Вийти</button>
        </form>
    </nav>
</header>

<main class="main-content">
    <div class="intro-section">
        <h2>👥 Друзі</h2>
    </div>

    <div class="friends-layout">
        <div class="friends-main">

            <div class="section-card">
                <h3>🔍 Знайти користувача</h3>
                <form class="search-form" method="get">
                    <input type="text" name="search"
                           placeholder="Ім'я або email..."
                           value="<?= htmlspecialchars($searchQuery) ?>">
                    <button type="submit">Знайти</button>
                </form>

                <?php if ($searchQuery !== ''): ?>
                    <?php if (empty($searchResults)): ?>
                        <p class="empty-state">Нікого не знайдено.</p>
                    <?php else: ?>
                        <?php foreach ($searchResults as $user):
                            $status = getFriendshipStatus($pdo, $CURRENT_USER_ID, (int)$user['id']);
                        ?>
                        <div class="user-row">
                            <div class="user-avatar"><?= mb_strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
                            <div class="user-info">
                                <strong><?= htmlspecialchars($user['name']) ?></strong>
                                <small><?= htmlspecialchars($user['email']) ?></small>
                            </div>
                            <div class="user-actions">
                                <?php if ($status === 'none'): ?>
                                    <form method="post" action="friend_request.php">
                                        <input type="hidden" name="action" value="send">
                                        <input type="hidden" name="target_id" value="<?= $user['id'] ?>">
                                        <button class="btn-sm btn-add" type="submit">+ Додати</button>
                                    </form>
                                <?php elseif ($status === 'sent'): ?>
                                    <form method="post" action="friend_request.php">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="target_id" value="<?= $user['id'] ?>">
                                        <button class="btn-sm btn-remove" type="submit">Скасувати</button>
                                    </form>
                                <?php elseif ($status === 'incoming'): ?>
                                    <form method="post" action="friend_request.php">
                                        <input type="hidden" name="action" value="accept">
                                        <input type="hidden" name="target_id" value="<?= $user['id'] ?>">
                                        <button class="btn-sm btn-accept" type="submit">✓ Прийняти</button>
                                    </form>
                                <?php elseif ($status === 'friends'): ?>
                                    <span class="btn-sm btn-pending">✓ Друзі</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if (!empty($incomingRequests)): ?>
            <div class="section-card">
                <h3>📬 Вхідні запити <span class="badge"><?= count($incomingRequests) ?></span></h3>
                <?php foreach ($incomingRequests as $user): ?>
                <div class="user-row">
                    <div class="user-avatar"><?= mb_strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
                    <div class="user-info">
                        <strong><?= htmlspecialchars($user['name']) ?></strong>
                        <small><?= htmlspecialchars($user['email']) ?></small>
                    </div>
                    <div class="user-actions">
                        <form method="post" action="friend_request.php">
                            <input type="hidden" name="action" value="accept">
                            <input type="hidden" name="target_id" value="<?= $user['id'] ?>">
                            <button class="btn-sm btn-accept" type="submit">✓ Прийняти</button>
                        </form>
                        <form method="post" action="friend_request.php">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="target_id" value="<?= $user['id'] ?>">
                            <button class="btn-sm btn-reject" type="submit">✕</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($sentRequests)): ?>
            <div class="section-card">
                <h3>📤 Надіслані запити</h3>
                <?php foreach ($sentRequests as $user): ?>
                <div class="user-row">
                    <div class="user-avatar"><?= mb_strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
                    <div class="user-info">
                        <strong><?= htmlspecialchars($user['name']) ?></strong>
                        <small><?= htmlspecialchars($user['email']) ?></small>
                    </div>
                    <div class="user-actions">
                        <form method="post" action="friend_request.php">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="target_id" value="<?= $user['id'] ?>">
                            <button class="btn-sm btn-remove" type="submit">Скасувати</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="section-card">
                <h3>💜 Мої друзі (<?= count($friends) ?>)</h3>
                <?php if (empty($friends)): ?>
                    <p class="empty-state">У тебе ще немає друзів. Знайди їх вище!</p>
                <?php else: ?>
                    <?php foreach ($friends as $friend): ?>
                    <div class="user-row">
                        <?php if (!empty($friend['avatar'])): ?>
                            <img src="<?= htmlspecialchars($friend['avatar']) ?>" alt="Avatar" class="user-avatar" style="object-fit: cover;">
                        <?php else: ?>
                            <div class="user-avatar"><?= mb_strtoupper(mb_substr($friend['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <strong><?= htmlspecialchars($friend['name']) ?></strong>
                            <small><?= htmlspecialchars($friend['email']) ?></small>
                        </div>
                        <div class="user-actions">
                            <a href="friend_profile.php?id=<?= $friend['id'] ?>" class="btn-sm btn-add" style="text-decoration:none; padding:5px 12px;">Профіль</a>
                            <form method="post" action="friend_request.php">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="target_id" value="<?= $friend['id'] ?>">
                                <button class="btn-sm btn-remove" type="submit">Видалити</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

        </div><div class="friends-feed">
            <div class="section-card">
                <h3>🎬 Активність друзів</h3>
                <?php if (empty($friendFeed)): ?>
                    <p class="empty-state">Тут з'явиться активність твоїх друзів.</p>
                <?php else: ?>
                    <?php foreach ($friendFeed as $item):
                        $icon = match($item['type']) {
                            'favorite'  => '💜',
                            'vote_up'   => '⬆️',
                            'vote_down' => '⬇️',
                            default     => '🎬',
                        };
                        $action = match($item['type']) {
                            'favorite'  => 'додав у закладки',
                            'vote_up'   => 'оцінив (+1)',
                            'vote_down' => 'знизив рейтинг',
                            default     => 'взаємодіяв з',
                        };
                        $utcDate = $item['created_at']; // Беремо UTC час з БД
                    ?>
                    <div class="feed-item">
                        <?php if (!empty($item['image_url'])): ?>
                            <img src="<?= htmlspecialchars($item['image_url']) ?>"
                                 alt="poster" class="feed-poster">
                        <?php else: ?>
                            <div class="feed-poster-placeholder"></div>
                        <?php endif; ?>
                        <div class="feed-text">
                            <?= $icon ?>
                            <strong><?= htmlspecialchars($item['user_name']) ?></strong>
                            <?= $action ?>
                            <a href="movie.php?id=<?= $item['tmdb_id'] ?>">
                                <?= htmlspecialchars($item['title']) ?>
                            </a>
                            <span class="feed-time" data-utc="<?= htmlspecialchars($utcDate) ?>"></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div></div></main>

<script>
function formatTimeAgo() {
    const timeElements = document.querySelectorAll('.feed-time');
    
    timeElements.forEach(el => {
        const utcStr = el.getAttribute('data-utc');
        if (!utcStr) return;

        // Перетворюємо рядок SQL в стандартний ISO формат і явно вказуємо, що це UTC (додаючи 'Z')
        const date = new Date(utcStr.replace(' ', 'T') + 'Z');
        const now = new Date();
        
        // Вираховуємо різницю в секундах
        const secondsAgo = Math.floor((now - date) / 1000);
        
        let timeStr = '';
        if (secondsAgo < 60) {
            timeStr = 'щойно';
        } else if (secondsAgo < 3600) {
            timeStr = Math.floor(secondsAgo / 60) + ' хв тому';
        } else if (secondsAgo < 86400) {
            timeStr = Math.floor(secondsAgo / 3600) + ' год тому';
        } else {
            timeStr = Math.floor(secondsAgo / 86400) + ' дн тому';
        }
        
        // Оновлюємо текст у span
        el.innerText = timeStr;
    });
}

// Запускаємо відразу при завантаженні сторінки
formatTimeAgo();
// Оновлюємо кожну хвилину (60000 мілісекунд), щоб час залишався актуальним без перезавантаження
setInterval(formatTimeAgo, 60000);
</script>

</body>
</html>