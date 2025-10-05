<?php
session_start();
header('Content-Type: application/json');

require_once "includes/db.php";
require_once "includes/post_functions.php";

// Ответ по умолчанию
$response = ["success" => false, "message" => "Invalid request."];

// Проверяем, авторизован ли пользователь
if (!isset($_SESSION["user_id"])) {
    echo json_encode(["success" => false, "message" => "Authentication required."]);
    exit;
}

// Проверяем, что это POST-запрос с параметром action
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["action"])) {
    $action = $_POST["action"];
    $user_id = $_SESSION["user_id"];

    switch ($action) {
        case "addPost":
            $response = handleAddPost($conn, $user_id, $_POST, $_FILES);
            break;

        case "loadPosts":
            $offset = intval($_POST["offset"] ?? 0);
            $response = handleLoadPosts($conn, $offset);
            break;

        case "loadReplies":
            $post_id = intval($_POST['post_id'] ?? 0);
            $response = handleLoadReplies($conn, $post_id);
            break;

        case "toggleLike":
        case "toggleDislike":
            $post_id = intval($_POST["post_id"] ?? 0);
            $is_dislike = ($action === "toggleDislike") ? 1 : 0;
            $response = handleToggleLike($conn, $user_id, $post_id, $is_dislike);
            break;

        case "deletePost":
            $post_id = intval($_POST["post_id"] ?? 0);
            $response = handleDeletePost($conn, $user_id, $post_id);
            break;
            
        case "reportPost":
            $post_id = intval($_POST["post_id"] ?? 0);
            $response = handleReportPost($conn, $user_id, $post_id);
            break;
    }
}

echo json_encode($response);
