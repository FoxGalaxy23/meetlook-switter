<?php
session_start();
require_once "elements/php/db.php";
require_once "ai.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Текущий пользователь
$currentUserId = intval($_SESSION['user_id']);

/* ---------------------------
   ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
   --------------------------- */

// Кэш для проверки верификации (чтобы избежать лишних запросов к базе)
$GLOBALS['verified_users_cache'] = []; 
function is_user_verified($userId, $conn) {
    if (isset($GLOBALS['verified_users_cache'][$userId])) {
        return $GLOBALS['verified_users_cache'][$userId];
    }
    // ПРЕДУПРЕЖДЕНИЕ: Вы должны создать таблицу `verified_users` в MySQL
    $stmt = $conn->prepare("SELECT 1 FROM verified_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $is_verified = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    // Кэшируем результат
    $GLOBALS['verified_users_cache'][$userId] = $is_verified;
    return $is_verified;
}


// ===== AJAX обработка =====
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"])) {
    $action = $_POST["action"] ?? "";

    if ($action === "addPost") {
        // Эта часть остается без изменений, так как добавление поста - это универсальное действие
        $text = trim($_POST["text"] ?? "");
        $parent_id = !empty($_POST["parent_id"]) ? intval($_POST["parent_id"]) : NULL;
        $original_post_id = !empty($_POST["original_post_id"]) ? intval($_POST["original_post_id"]) : NULL;

        $hasFiles = isset($_FILES['media']) && count(array_filter($_FILES['media']['name'] ?? [])) > 0;
        if ($text !== "" || $hasFiles || $original_post_id !== NULL) {
            
            $topic = null;
            if ($text !== "" && $original_post_id === NULL) {
                $topic = EmbeddedTopicClassifier::classify($text);
            } elseif ($original_post_id !== NULL) {
                $oTopicStmt = $conn->prepare("SELECT topic FROM posts WHERE id = ?");
                $oTopicStmt->bind_param("i", $original_post_id);
                $oTopicStmt->execute();
                $oTopicResult = $oTopicStmt->get_result()->fetch_assoc();
                $oTopicStmt->close();
                if ($oTopicResult) {
                    $topic = $oTopicResult['topic'];
                }
            }

            $stmt = $conn->prepare("INSERT INTO posts (user_id, text, parent_id, original_post_id, topic) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isiis", $currentUserId, $text, $parent_id, $original_post_id, $topic); 
            $stmt->execute();
            $post_id = $stmt->insert_id;
            $stmt->close();

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
                        $mime = finfo_file($finfo, $tmp) ?: mime_content_type($tmp);
                        if (!$mime) continue;
                        if (!(strpos($mime, 'image/') === 0 || strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0)) continue;

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
            
            $stmt = $conn->prepare("SELECT p.*, u.username, u.avatar, (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id AND l.is_dislike = 0) as like_count, (SELECT COUNT(*) FROM likes d WHERE d.post_id = p.id AND d.is_dislike=1) as dislike_count
            FROM posts p JOIN users u ON p.user_id=u.id WHERE p.id=?");
            $stmt->bind_param("i", $post_id);
            $stmt->execute();
            $post = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $mediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
            $mediaStmt->bind_param("i", $post_id);
            $mediaStmt->execute();
            $media = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $mediaStmt->close();
            $post['media'] = $media;

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
            
            $post['is_verified'] = is_user_verified(intval($post['user_id']), $conn);

            echo json_encode(["success"=>true, "post"=>$post]);
            exit;
        }
    }

    if ($action === "loadPosts") {
        $offset = intval($_POST["offset"] ?? 0);
        $limit = 5;

        // ----- ОСНОВНОЕ ИЗМЕНЕНИЕ ДЛЯ ЛЕНТЫ ЛАЙКОВ -----

        // НОВЫЙ SQL-ЗАПРОС
        // Выбираем посты, которым текущий пользователь поставил лайк (is_dislike = 0),
        // соединяя таблицы posts и likes.
        $sql = "SELECT p.*, u.username, u.avatar,
                       (SELECT COUNT(*) FROM likes l_count WHERE l_count.post_id=p.id AND l_count.is_dislike = 0) as like_count,
                       (SELECT COUNT(*) FROM likes d_count WHERE d_count.post_id = p.id AND d_count.is_dislike=1) as dislike_count
                FROM posts p
                JOIN users u ON p.user_id = u.id
                JOIN likes l ON p.id = l.post_id
                WHERE l.user_id = ? AND l.is_dislike = 0 AND p.parent_id IS NULL
                ORDER BY p.id DESC
                LIMIT ? OFFSET ?";
        
        $stmt = $conn->prepare($sql);
        
        // НОВАЯ ПРИВЯЗКА ПАРАМЕТРОВ: ID пользователя, лимит и смещение
        $stmt->bind_param("iii", $currentUserId, $limit, $offset);

        // ----- КОНЕЦ ИЗМЕНЕНИЙ -----

        $stmt->execute();
        $posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Остальная логика подгрузки медиа и т.д. остается той же
        $mediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
        foreach ($posts as &$p) {
            $mediaStmt->bind_param("i", $p['id']);
            $mediaStmt->execute();
            $p['media'] = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);

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
            
            $p['is_verified'] = is_user_verified(intval($p['user_id']), $conn);
        }
        $mediaStmt->close();

        echo json_encode($posts);
        exit;
    }

    // Все остальные обработчики (ответы, лайки, удаление и т.д.) остаются без изменений
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
            $r['is_verified'] = is_user_verified(intval($r['user_id']), $conn);
        }
        $mediaStmt->close();

        echo json_encode($replies);
        exit;
    }

    if ($action === "toggleLike" || $action === "toggleDislike") {
        $post_id = intval($_POST["post_id"] ?? 0);
        $user_id = $currentUserId;
        $is_dislike = ($action === "toggleDislike") ? 1 : 0;

        $stmt = $conn->prepare("SELECT id, is_dislike FROM likes WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            if ($res['is_dislike'] == $is_dislike) {
                $stmt = $conn->prepare("DELETE FROM likes WHERE id = ?");
                $stmt->bind_param("i", $res['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                $stmt = $conn->prepare("UPDATE likes SET is_dislike = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_dislike, $res['id']);
                $stmt->execute();
                $stmt->close();
            }
        } else {
            $stmt = $conn->prepare("INSERT INTO likes (post_id, user_id, is_dislike) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $post_id, $user_id, $is_dislike);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("SELECT (SELECT COUNT(*) FROM likes WHERE post_id = ? AND is_dislike = 0) AS like_count, (SELECT COUNT(*) FROM likes WHERE post_id = ? AND is_dislike = 1) AS dislike_count");
        $stmt->bind_param("ii", $post_id, $post_id);
        $stmt->execute();
        $counts = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        echo json_encode(["success" => true, "like_count" => $counts['like_count'], "dislike_count" => $counts['dislike_count']]);
        exit;
    }

    if ($action === "deletePost") {
        $post_id = intval($_POST["post_id"] ?? 0);
        $user_id = $currentUserId;

        $stmt = $conn->prepare("SELECT user_id FROM posts WHERE id = ?");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $post = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($post && $post['user_id'] == $user_id) {
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
        $user_id = $currentUserId;
        
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