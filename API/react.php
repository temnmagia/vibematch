<?php
// API/react.php
require_once __DIR__ . '/../core/auth_check.php';
require_once __DIR__ . '/../config/db_connect.php';

// Тільки POST-запити від авторизованих юзерів
if (!$IS_LOGGED_IN || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit(json_encode(['error' => 'Unauthorized']));
}

// Отримуємо JSON від JavaScript
$data = json_decode(file_get_contents('php://input'), true);
$movieId = (int)($data['movie_id'] ?? 0);
$reaction = $data['reaction'] ?? '';

// Мапимо емодзі на типи в базі даних
$validReactions = [
    '🔥' => 'react_fire',
    '😭' => 'react_cry',
    '👽' => 'react_alien',
    'accuracy' => 'accuracy_match'
];

if (!$movieId || !isset($validReactions[$reaction])) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid data']));
}

$type = $validReactions[$reaction];

// Перевіряємо, чи не ставив юзер вже точно таку саму реакцію на цей фільм (захист від спаму кліками)
$stmt = $pdo->prepare("SELECT id FROM activity_feed WHERE user_id = ? AND movie_id = ? AND type = ?");
$stmt->execute([$CURRENT_USER_ID, $movieId, $type]);

if (!$stmt->fetch()) {
    // Зберігаємо реакцію в стрічку активності
    $pdo->prepare("INSERT INTO activity_feed (user_id, type, movie_id) VALUES (?, ?, ?)")
        ->execute([$CURRENT_USER_ID, $type, $movieId]);
}

// Відповідаємо фронтенду, що все супер
echo json_encode(['success' => true]);