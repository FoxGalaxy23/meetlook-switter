<?php
session_start();
require_once "elements/php/db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// ===== AJAX обработка =====
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"])) {
    $action = $_POST["action"] ?? "";

    if ($action === "addPost") {
        $text = trim($_POST["text"] ?? "");
        $parent_id = !empty($_POST["parent_id"]) ? intval($_POST["parent_id"]) : NULL;
        $original_post_id = !empty($_POST["original_post_id"]) ? intval($_POST["original_post_id"]) : NULL;

        // Разрешаем пустой текст только если есть медиа или репост
        $hasFiles = isset($_FILES['media']) && count(array_filter($_FILES['media']['name'])) > 0;
        if ($text !== "" || $hasFiles || $original_post_id !== NULL) {
            // Вставляем пост
            $stmt = $conn->prepare("INSERT INTO posts (user_id, text, parent_id, original_post_id) VALUES (?, ?, ?, ?)");
            $stmt->bind_param("isii", $_SESSION["user_id"], $text, $parent_id, $original_post_id);
            $stmt->execute();
            $post_id = $stmt->insert_id;
            $stmt->close();

            // Обработка медиа (до 5 файлов)
            if ($hasFiles) {
                $files = $_FILES['media'];
                $valid_count = 0;
                foreach ($files['name'] as $n) if (!empty($n)) $valid_count++;
                if ($valid_count > 0) {
                    $uploadDir = __DIR__ . '/uploads/posts/' . $post_id;
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    $count = min($valid_count, 5);

                    $insertStmt = $conn->prepare("INSERT INTO post_media (post_id, file_path, mime, position) VALUES (?, ?, ?, ?)");
                    $pos = 0;
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    for ($i=0; $i < count($files['name']) && $pos < $count; $i++) {
                        if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
                        $tmp = $files['tmp_name'][$i];

                        // detect mime
                        $mime = finfo_file($finfo, $tmp) ?: mime_content_type($tmp);
                        if (!$mime) continue;
                        if (!(strpos($mime, 'image/') === 0 || strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0)) continue;

                        // create safe name
                        $ext = pathinfo($files['name'][$i], PATHINFO_EXTENSION);
                        $safeName = time() . '_' . bin2hex(random_bytes(6)) . ($ext ? '.' . $ext : '');
                        $target = $uploadDir . '/' . $safeName;

                        if (move_uploaded_file($tmp, $target)) {
                            $relativePath = 'uploads/posts/' . $post_id . '/' . $safeName;
                            $insertStmt->bind_param("issi", $post_id, $relativePath, $mime, $pos);
                            $insertStmt->execute();
                            $pos++;
                        }
                    }
                    finfo_close($finfo);
                    $insertStmt->close();
                }
            }

            // Получаем созданный пост с данными пользователя и лайков
            $stmt = $conn->prepare("SELECT p.*, u.username, u.avatar, (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id AND l.is_dislike = 0) as like_count, (SELECT COUNT(*) FROM likes d WHERE d.post_id = p.id AND d.is_dislike=1) as dislike_count
            FROM posts p JOIN users u ON p.user_id=u.id WHERE p.id=?");
            $stmt->bind_param("i", $post_id);
            $stmt->execute();
            $post = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // Получаем медиа для поста
            $mediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
            $mediaStmt->bind_param("i", $post_id);
            $mediaStmt->execute();
            $media = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $mediaStmt->close();
            $post['media'] = $media;

            // Если это репост — подтянем оригинал (текст, юзер, медиа)
            if ($original_post_id !== NULL) {
                $oStmt = $conn->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id=u.id WHERE p.id = ?");
                $oStmt->bind_param("i", $original_post_id);
                $oStmt->execute();
                $orig = $oStmt->get_result()->fetch_assoc();
                $oStmt->close();
                if ($orig) {
                    $oMediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
                    $oMediaStmt->bind_param("i", $original_post_id);
                    $oMediaStmt->execute();
                    $orig_media = $oMediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $oMediaStmt->close();
                    $orig['media'] = $orig_media;
                    $post['original_post'] = $orig;
                }
            }

            echo json_encode(["success"=>true, "post"=>$post]);
            exit;
        }
    }

    if ($action === "loadPosts") {
        $offset = intval($_POST["offset"] ?? 0);
        $mode = $_POST["mode"] ?? 'general'; // НОВЫЙ ПАРАМЕТР
        $limit = 5;

        // Инициализация переменных для SQL запроса
        $sql_where = "WHERE p.parent_id IS NULL";
        $sql_bind_types = "";
        $sql_bind_params = [];

        // Логика сортировки по режиму
        if (strpos($mode, 'user:') === 0) {
            $target_user_id = intval($_POST["target_user_id"] ?? 0);
            if ($target_user_id > 0) {
                // Режим личной ленты: посты только от конкретного пользователя
                $sql_where .= " AND p.user_id = ?";
                $sql_bind_types .= "i";
                $sql_bind_params[] = $target_user_id;
            }
        } 
        // Для 'general' режима $sql_where остается: WHERE p.parent_id IS NULL

        // Добавляем LIMIT и OFFSET
        $sql_where .= " ORDER BY p.id DESC LIMIT ? OFFSET ?";
        $sql_bind_types .= "ii";
        $sql_bind_params[] = $limit;
        $sql_bind_params[] = $offset;

        // Формирование запроса
        $sql_query = "
            SELECT 
                p.*, 
                u.username, 
                u.avatar, 
                (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id AND l.is_dislike = 0) as like_count, 
                (SELECT COUNT(*) FROM likes d WHERE d.post_id = p.id AND d.is_dislike=1) as dislike_count
            FROM posts p JOIN users u ON p.user_id=u.id
            {$sql_where}
        ";
        
        $stmt = $conn->prepare($sql_query);
        
        // !!! ИСПРАВЛЕНИЕ ОШИБКИ BIND_PARAM: Передача по ссылке !!!
        if (!empty($sql_bind_params)) {
            // Создаем массив аргументов: ['типы', param1, param2, ...]
            $bind_args = array_merge([$sql_bind_types], $sql_bind_params);
            
            // Получаем ссылки на элементы массива, так как bind_param требует ссылок
            $refs = [];
            foreach($bind_args as $key => $value) {
                $refs[$key] = &$bind_args[$key];
            }
            
            // Вызываем bind_param с массивом ссылок
            call_user_func_array([$stmt, 'bind_param'], $refs);
        }

        $stmt->execute();
        // Проверка на ошибку выполнения запроса (полезно для отладки)
        if ($stmt->error) {
            // В продакшене лучше не выводить, но для отладки можно
            // echo json_encode(["error" => true, "message" => $stmt->error]);
            // exit;
        }


        $posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Подтянем медиа для каждого поста
        $mediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
        foreach ($posts as &$p) {
            $mediaStmt->bind_param("i", $p['id']);
            $mediaStmt->execute();
            $p['media'] = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);

            // Если репост, подтянем оригинал кратко (id, text, username, media)
            if (!empty($p['original_post_id'])) {
                $oStmt = $conn->prepare("SELECT p.*, u.username FROM posts p JOIN users u ON p.user_id=u.id WHERE p.id = ?");
                $oStmt->bind_param("i", $p['original_post_id']);
                $oStmt->execute();
                $orig = $oStmt->get_result()->fetch_assoc();
                $oStmt->close();
                if ($orig) {
                    $oMediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
                    $oMediaStmt->bind_param("i", $p['original_post_id']);
                    $oMediaStmt->execute();
                    $orig_media = $oMediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $oMediaStmt->close();
                    $orig['media'] = $orig_media;
                    $p['original_post'] = $orig;
                }
            }
        }
        $mediaStmt->close();

        echo json_encode($posts);
        exit;
    }

    if ($action === "loadReplies") {
        $post_id = intval($_POST['post_id'] ?? 0);
        $stmt = $conn->prepare("SELECT p.*, u.username, u.avatar, (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id AND l.is_dislike = 0) as like_count, (SELECT COUNT(*) FROM likes d WHERE d.post_id = p.id AND d.is_dislike=1) as dislike_count
FROM posts p JOIN users u ON p.user_id=u.id
WHERE p.parent_id = ?
ORDER BY p.id ASC");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $replies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $mediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
        foreach ($replies as &$r) {
            $mediaStmt->bind_param("i", $r['id']);
            $mediaStmt->execute();
            $r['media'] = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $mediaStmt->close();

        echo json_encode($replies);
        exit;
    }

// ===== Обработчик лайков и дизлайков =====
    if ($action === "toggleLike" || $action === "toggleDislike") {
        $post_id = intval($_POST["post_id"] ?? 0);
        $user_id = $_SESSION["user_id"];
        $is_dislike = ($action === "toggleDislike") ? 1 : 0; // Определяем, это дизлайк (1) или лайк (0)

        // Ищем, есть ли уже реакция от этого пользователя на этот пост
        $stmt = $conn->prepare("SELECT id, is_dislike FROM likes WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            // Реакция уже есть
            if ($res['is_dislike'] == $is_dislike) {
                // Если тип реакции совпадает, удаляем
                $stmt = $conn->prepare("DELETE FROM likes WHERE id = ?");
                $stmt->bind_param("i", $res['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                // Если тип реакции отличается, обновляем
                $stmt = $conn->prepare("UPDATE likes SET is_dislike = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_dislike, $res['id']);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            // Реакции ещё нет, добавляем новую
            $stmt = $conn->prepare("INSERT INTO likes (post_id, user_id, is_dislike) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $post_id, $user_id, $is_dislike);
            $stmt->execute();
            $stmt->close();
        }

        // Получаем обновлённое количество лайков и дизлайков
        $stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM likes WHERE post_id = ? AND is_dislike = 0) AS like_count, (SELECT COUNT(*) FROM likes WHERE post_id = ? AND is_dislike = 1) AS dislike_count");
        $stmt->bind_param("ii", $post_id, $post_id);
        $stmt->execute();
        $counts = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        // Отправляем ответ в формате JSON
        echo json_encode(["success" => true, "like_count" => $counts['like_count'], "dislike_count" => $counts['dislike_count']]);
        exit;
    }

    if ($action === "deletePost") {
        $post_id = intval($_POST["post_id"] ?? 0);
        $user_id = $_SESSION["user_id"];

        // Проверяем, что пост существует и принадлежит текущему пользователю
        $stmt = $conn->prepare("SELECT user_id FROM posts WHERE id = ?");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $post = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($post && $post['user_id'] == $user_id) {
            // Удаляем медиафайлы и директории
            $mediaStmt = $conn->prepare("SELECT file_path FROM post_media WHERE post_id = ?");
            $mediaStmt->bind_param("i", $post_id);
            $mediaStmt->execute();
            $mediaFiles = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $mediaStmt->close();

            foreach ($mediaFiles as $file) {
                $fullPath = __DIR__ . '/' . $file['file_path'];
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
            }

            $uploadDir = __DIR__ . '/uploads/posts/' . $post_id;
            if (is_dir($uploadDir)) {
                @rmdir($uploadDir);
            }

            // Удаляем записи из базы
            $conn->query("DELETE FROM likes WHERE post_id = $post_id");
            $conn->query("DELETE FROM post_media WHERE post_id = $post_id");
            $conn->query("DELETE FROM posts WHERE id = $post_id OR parent_id = $post_id");
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "message" => "У вас нет прав на удаление этого поста или он не существует."]);
        }
        exit;
    }
    
    if ($action === "reportPost") {
        $post_id = intval($_POST["post_id"] ?? 0);
        $user_id = $_SESSION["user_id"];
        
        $stmt = $conn->prepare("SELECT id FROM reports WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $existing_report = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($existing_report) {
            echo json_encode(["success" => false, "message" => "Вы уже отправили жалобу на этот пост."]);
        } else {
            $stmt = $conn->prepare("INSERT INTO reports (post_id, user_id, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param("ii", $post_id, $user_id);
            if ($stmt->execute()) {
                echo json_encode(["success" => true, "message" => "Жалоба отправлена."]);
            } else {
                echo json_encode(["success" => false, "message" => "Не удалось отправить жалобу."]);
            }
            $stmt->close();
        }
        exit;
    }
}
?>