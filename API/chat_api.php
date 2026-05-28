<?php
// API/chat_api.php
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';

if (!$IS_LOGGED_IN) {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

$action = $_GET['action'] ?? '';

if ($action === 'get_history') {
    $friendId = (int)($_GET['friend_id'] ?? 0);
    
    // Дістаємо повідомлення + контекст
    $stmt = $pdo->prepare("
        SELECT id, sender_id, message, reply_context, created_at 
        FROM messages 
        WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
        ORDER BY created_at ASC LIMIT 50
    ");
    $stmt->execute([$CURRENT_USER_ID, $friendId, $friendId, $CURRENT_USER_ID]);
    $messages = $stmt->fetchAll();

    $pdo->prepare("UPDATE messages SET is_read = TRUE WHERE sender_id = ? AND receiver_id = ?")->execute([$friendId, $CURRENT_USER_ID]);

    exit(json_encode(['success' => true, 'messages' => $messages, 'me' => $CURRENT_USER_ID]));
}

if ($action === 'send') {
    $data = json_decode(file_get_contents('php://input'), true);
    $friendId = (int)($data['friend_id'] ?? 0);
    $message = trim($data['message'] ?? '');
    $context = $data['context'] ?? null; // Отримуємо контекст (якщо він є)

    // Змініть на це:
    if (!$friendId || (empty($message) && empty($context))) {
        exit(json_encode(['error' => 'Empty message and no context']));
    }

    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, reply_context) VALUES (?, ?, ?, ?)");
    $stmt->execute([$CURRENT_USER_ID, $friendId, $message, $context]);

    exit(json_encode(['success' => true]));
}

exit(json_encode(['error' => 'Invalid action']));