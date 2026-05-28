<?php
// api/sync_friends.php
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';

if (!$IS_LOGGED_IN) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

// 1. Мої друзі
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.avatar_url AS avatar
    FROM friendships f
    JOIN users u ON u.id = IF(f.requester_id = ?, f.receiver_id, f.requester_id)
    WHERE (f.requester_id = ? OR f.receiver_id = ?) AND f.status = 'accepted'
");
$stmt->execute([$CURRENT_USER_ID, $CURRENT_USER_ID, $CURRENT_USER_ID]);
$friends = $stmt->fetchAll();

// 2. Вхідні запити
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.avatar_url AS avatar
    FROM friendships f
    JOIN users u ON u.id = f.requester_id
    WHERE f.receiver_id = ? AND f.status = 'pending'
");
$stmt->execute([$CURRENT_USER_ID]);
$incoming = $stmt->fetchAll();

// 3. Надіслані запити
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.email, u.avatar_url AS avatar
    FROM friendships f
    JOIN users u ON u.id = f.receiver_id
    WHERE f.requester_id = ? AND f.status = 'pending'
");
$stmt->execute([$CURRENT_USER_ID]);
$sent = $stmt->fetchAll();

// 4. Стрічка активності друзів
$friendIds = array_column($friends, 'id');
$feed = [];
if (!empty($friendIds)) {
    $placeholders = implode(',', array_fill(0, count($friendIds), '?'));
    $stmt = $pdo->prepare("
        SELECT af.id, af.type, af.created_at,
               u.id AS user_id, u.name AS user_name, u.avatar_url AS user_avatar,
               m.tmdb_id, m.title, m.image_url
        FROM activity_feed af
        JOIN users u ON u.id = af.user_id
        JOIN movies m ON m.tmdb_id = af.movie_id
        WHERE af.user_id IN ($placeholders)
        ORDER BY af.created_at DESC
        LIMIT 40
    ");
    $stmt->execute($friendIds);
    $feed = $stmt->fetchAll();
}

header('Content-Type: application/json');
echo json_encode([
    'success'  => true,
    'friends'  => $friends,
    'incoming' => $incoming,
    'sent'     => $sent,
    'feed'     => $feed
]);