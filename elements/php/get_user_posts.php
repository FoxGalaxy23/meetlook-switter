<?php
// elements/php/get_user_posts.php
session_start();
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

$profile_user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
$limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 20;
$offset = isset($_POST['offset']) ? (int)$_POST['offset'] : 0;

if ($profile_user_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'no_user_id']);
    exit;
}

// Подгружаем посты пользователя; предполагаем таблицы posts (p) и users (u)
$sql = "SELECT p.id, p.user_id, p.content, p.media, p.created_at, u.username, u.avatar
        FROM posts p
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.user_id = ?
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'db_prepare_failed', 'db_error'=>$conn->error]);
    exit;
}
$stmt->bind_param('iii', $profile_user_id, $limit, $offset);
$stmt->execute();
$res = $stmt->get_result();

$posts = [];
while ($row = $res->fetch_assoc()) {
    // Безопасный вывод — не включаем HTML, вернём сырые данные (JS будет экранировать)
    $posts[] = [
        'id' => (int)$row['id'],
        'user_id' => (int)$row['user_id'],
        'username' => $row['username'],
        'avatar' => $row['avatar'],
        'content' => $row['content'],
        'media' => $row['media'], // предполагается строка с URL(ами) или NULL
        'created_at' => $row['created_at']
    ];
}
echo json_encode(['success' => true, 'posts' => $posts]);
