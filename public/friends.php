<?php
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';

require_auth();
session_start();

if (isset($_POST['logout'])) logout();

// --- Пошук користувачів ---
$searchResults = [];
$searchQuery   = trim($_GET['search'] ?? '');

if ($searchQuery !== '') {
    $stmt = $pdo->prepare(
        "SELECT id, name, email, avatar_url FROM users
         WHERE (name LIKE ? OR email LIKE ?) AND id <> ?
         LIMIT 20"
    );
    $stmt->execute(["%$searchQuery%", "%$searchQuery%", $CURRENT_USER_ID]);
    $searchResults = $stmt->fetchAll();
}

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

// --- Список друзів (з аватарками з БД) ---
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.avatar_url AS avatar
     FROM friendships f
     JOIN users u ON u.id = IF(f.requester_id = ?, f.receiver_id, f.requester_id)
     WHERE (f.requester_id = ? OR f.receiver_id = ?)
       AND f.status = 'accepted'
     ORDER BY u.name"
);
$stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID, $CURRENT_USER_ID]);
$friends = $stmt->fetchAll();

// --- Вхідні запити ---
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.avatar_url, f.created_at
     FROM friendships f
     JOIN users u ON u.id = f.requester_id
     WHERE f.receiver_id = ? AND f.status = 'pending'
     ORDER BY f.created_at DESC"
);
$stmt->execute([$CURRENT_USER_ID]);
$incomingRequests = $stmt->fetchAll();

// --- Надіслані запити ---
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.avatar_url
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
        "SELECT af.id, af.type, af.created_at,
                u.id AS user_id, u.name AS user_name, u.avatar_url AS user_avatar,
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
    <?php 
    $pageTitle = "VibeMatch — Друзі";
    include __DIR__ . '/../views/partials/head.php'; 
    ?>
</head>
<body>
    <?php include __DIR__ . '/../views/partials/header.php'; ?>

<main class="main-content">
    <div class="intro-section">
        <h2>👥 Друзі</h2>
    </div>

    <div class="friends-layout">
        <div class="friends-main">

            <div class="section-card">
                <h3>🕵 Знайти користувача</h3>
                <form class="search-form" method="get">
                    <input type="text" name="search" placeholder="Ім'я або email..." value="<?= htmlspecialchars($searchQuery) ?>">
                    <button type="submit">🔍</button>
                </form>

                <?php if ($searchQuery !== ''): ?>
                    <?php if (empty($searchResults)): ?>
                        <p class="empty-state">Нікого не знайдено.</p>
                    <?php else: ?>
                        <?php foreach ($searchResults as $user): $status = getFriendshipStatus($pdo, $CURRENT_USER_ID, (int)$user['id']); ?>
                        <div class="user-row">
                            <?php if (!empty($user['avatar_url'])): ?>
                                <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="Avatar" class="user-avatar">
                            <?php else: ?>
                                <div class="user-avatar"><?= mb_strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
                            <?php endif; ?>
                            <div class="user-info">
                                <strong><?= htmlspecialchars($user['name']) ?></strong>
                                <small><?= htmlspecialchars($user['email']) ?></small>
                            </div>
                            <div class="user-actions">
                                <?php if ($status === 'none'): ?>
                                    <button class="btn-sm btn-add" onclick="handleFriendAction('send', <?= $user['id'] ?>, this)">✚</button>
                                <?php elseif ($status === 'sent'): ?>
                                    <button class="btn-sm btn-remove" onclick="handleFriendAction('remove', <?= $user['id'] ?>, this)">Скасувати</button>
                                <?php elseif ($status === 'incoming'): ?>
                                    <button class="btn-sm btn-accept" onclick="handleFriendAction('accept', <?= $user['id'] ?>, this)">✅</button>
                                <?php elseif ($status === 'friends'): ?>
                                    <span class="btn-sm btn-pending">✓ Друзі</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="section-card" id="incomingSection" style="<?= empty($incomingRequests) ? 'display:none;' : '' ?>">
                <h3>📬 Вхідні запити <span class="badge" id="incomingCount"><?= count($incomingRequests) ?></span></h3>
                <div id="incomingList">
                    <?php foreach ($incomingRequests as $user): ?>
                    <div class="user-row" data-user-id="<?= $user['id'] ?>">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="Avatar" class="user-avatar">
                        <?php else: ?>
                            <div class="user-avatar"><?= mb_strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <strong><?= htmlspecialchars($user['name']) ?></strong>
                            <small><?= htmlspecialchars($user['email']) ?></small>
                        </div>
                        <div class="user-actions">
                            <button class="btn-sm btn-accept" onclick="handleFriendAction('accept', <?= $user['id'] ?>, this)">✅</button>
                            <button class="btn-sm btn-reject" onclick="handleFriendAction('reject', <?= $user['id'] ?>, this)">❌</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="section-card" id="sentSection" style="<?= empty($sentRequests) ? 'display:none;' : '' ?>">
                <h3>📤 Надіслані запити <span class="badge" style="background: #4b5563;" id="sentCount"><?= count($sentRequests) ?></span></h3>
                <div id="sentList">
                    <?php foreach ($sentRequests as $user): ?>
                    <div class="user-row" data-user-id="<?= $user['id'] ?>">
                        <?php if (!empty($user['avatar_url'])): ?>
                            <img src="<?= htmlspecialchars($user['avatar_url']) ?>" alt="Avatar" class="user-avatar">
                        <?php else: ?>
                            <div class="user-avatar"><?= mb_strtoupper(mb_substr($user['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <strong><?= htmlspecialchars($user['name']) ?></strong>
                            <small><?= htmlspecialchars($user['email']) ?></small>
                        </div>
                        <div class="user-actions">
                            <button class="btn-sm btn-remove" onclick="handleFriendAction('remove', <?= $user['id'] ?>, this)">Скасувати</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="section-card" id="friendsSection">
            <h3>💜 Мої друзі (<span id="friendsCount"><?= count($friends) ?></span>)</h3>
            <div id="friendsList">
                <?php if (empty($friends)): ?>
                    <p class="empty-state">У тебе ще немає друзів. Знайди їх вище!</p>
                <?php else: ?>
                    <?php foreach ($friends as $friend): ?>
                    <div class="user-row" data-user-id="<?= $friend['id'] ?>">
                        <?php if (!empty($friend['avatar'])): ?>
                            <img src="<?= htmlspecialchars($friend['avatar']) ?>" alt="Avatar" class="user-avatar">
                        <?php else: ?>
                            <div class="user-avatar"><?= mb_strtoupper(mb_substr($friend['name'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="user-info">
                            <strong><?= htmlspecialchars($friend['name']) ?></strong>
                            <small><?= htmlspecialchars($friend['email']) ?></small>
                        </div>
                        <div class="user-actions">
                            <button class="btn-sm btn-chat" onclick="openChat(<?= $friend['id'] ?>, '<?= htmlspecialchars(addslashes($friend['name'])) ?>')">💬</button>
                            <a class="btn-sm btn-chat" href="profile.php?id=<?= $friend['id'] ?>" style="text-decoration:none;">👤</a>
                            <button class="btn-sm btn-remove" onclick="handleFriendAction('remove', <?= $friend['id'] ?>, this)">🗑️</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        </div>
        <div class="friends-feed">
            <div class="section-card">
                <h3>🎬 Активність друзів</h3>
                
                <div class="feed-scroll-area">
                    <?php if (empty($friendFeed)): ?>
                        <p class="empty-state">Тут з'явиться активність твоїх друзів.</p>
                    <?php else: ?>
                        <?php foreach ($friendFeed as $item):
                            $icon = match($item['type']) {
                                'favorite'       => '💜', 'vote_up'        => '⬆️',
                                'vote_down'      => '⬇️', 'react_fire'     => '🔥',
                                'react_cry'      => '😭', 'react_alien'    => '👽',
                                'accuracy_match' => '🎯', default          => '🎬',
                            };
                            $action = match($item['type']) {
                                'favorite'       => 'додав у закладки',
                                'vote_up'        => 'оцінив (+1)',
                                'vote_down'      => 'знизив рейтинг',
                                'react_fire'     => 'вважає вогненним',
                                'react_cry'      => 'плаче від',
                                'react_alien'    => 'зловив дивний вайб від',
                                'accuracy_match' => 'підтвердив точність вайбу для',
                                default          => 'взаємодіяв з',
                            };
                            $utcDate = $item['created_at']; 
                        ?>
                        <div class="feed-item" data-user-id="<?= $item['user_id'] ?>" data-activity-id="<?= $item['id'] ?>">
                            <?php if (!empty($item['image_url'])): ?>
                                <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="poster" class="feed-poster">
                            <?php else: ?>
                                <div class="feed-poster-placeholder"></div>
                            <?php endif; ?>
                            <div class="feed-text">
                                <span style="font-size: 1.2rem; margin-right: 5px;"><?= $icon ?></span>
                                <strong><?= htmlspecialchars($item['user_name']) ?></strong> <?= $action ?>
                                <a href="movie.php?id=<?= $item['tmdb_id'] ?>"><em><?= htmlspecialchars($item['title']) ?></em></a>
                                <span class="feed-time" data-utc="<?= htmlspecialchars($utcDate) ?>"></span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../views/partials/chat_drawer.php'; ?>

<script src="assets/app.js"></script>

</body>
</html>