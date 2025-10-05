<?php
session_start();
require 'db.php'; // подключение к базе

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(["error" => "Вы не авторизованы"]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user_id = $_SESSION['user_id'];
    $content = trim($_POST['content']);

    if ($content === '') {
        echo json_encode(["error" => "Пост пустой"]);
        exit;
    }

    $stmt = $conn->prepare("INSERT INTO posts (user_id, content) VALUES (?, ?)");
    $stmt->bind_param("is", $user_id, $content);
    $stmt->execute();

    echo json_encode([
        "success" => true,
        "id" => $stmt->insert_id,
        "content" => $content,
        "created_at" => date("Y-m-d H:i:s")
    ]);
}
