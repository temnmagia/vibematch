<?php
// friend_request.php — обробляє всі дії з дружбою (POST only)
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';

require_auth();

$action    = $_POST['action']    ?? '';
$targetId  = (int)($_POST['target_id'] ?? 0);
$referer   = $_SERVER['HTTP_REFERER'] ?? 'friends.php';

if (!$targetId || $targetId === $CURRENT_USER_ID) {
    header("Location: $referer");
    exit();
}

switch ($action) {

    // Надіслати запит дружби
    case 'send':
        // Перевіряємо, чи вже є зв'язок
        $stmt = $pdo->prepare(
            "SELECT id, status FROM friendships
             WHERE (requester_id = ? AND receiver_id = ?)
                OR (requester_id = ? AND receiver_id = ?)"
        );
        $stmt->execute([$CURRENT_USER_ID, $targetId, $targetId, $CURRENT_USER_ID]);
        $existing = $stmt->fetch();

        if (!$existing) {
            $pdo->prepare(
                "INSERT INTO friendships (requester_id, receiver_id, status) VALUES (?, ?, 'pending')"
            )->execute([$CURRENT_USER_ID, $targetId]);
        }
        break;

    // Прийняти запит дружби
    case 'accept':
        $pdo->prepare(
            "UPDATE friendships SET status = 'accepted'
             WHERE requester_id = ? AND receiver_id = ? AND status = 'pending'"
        )->execute([$targetId, $CURRENT_USER_ID]);
        break;

    // Відхилити запит дружби
    case 'reject':
        $pdo->prepare(
            "UPDATE friendships SET status = 'rejected'
             WHERE requester_id = ? AND receiver_id = ? AND status = 'pending'"
        )->execute([$targetId, $CURRENT_USER_ID]);
        break;

    // Видалити друга або скасувати запит
    case 'remove':
        $pdo->prepare(
            "DELETE FROM friendships
             WHERE (requester_id = ? AND receiver_id = ?)
                OR (requester_id = ? AND receiver_id = ?)"
        )->execute([$CURRENT_USER_ID, $targetId, $targetId, $CURRENT_USER_ID]);
        break;
}

// --- Перевіряємо, чи це фоновий AJAX-запит ---
$isAjax = !empty($_POST['is_ajax']);

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'action' => $action]);
    exit();
}

// Якщо це звичайний перехід (наприклад, зі сторінки профілю) - робимо редірект
header("Location: $referer");
exit();
