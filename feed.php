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


// [НОВОЕ] ФУНКЦИЯ ДЛЯ УПРАВЛЕНИЯ ИНТЕРЕСАМИ
/**
 * Изменяет интерес пользователя к теме.
 * @param int $userId ID пользователя.
 * @param string|null $topic Тема поста.
 * @param int $change 1 для увеличения, -1 для уменьшения.
 * @param mysqli $conn Соединение с БД.
 */
function update_user_interest($userId, $topic, $change, $conn) {
    if (empty($topic) || $userId <= 0) {
        return; // Не делаем ничего, если нет темы или пользователя
    }

    if ($change > 0) {
        // УВЕЛИЧЕНИЕ ИНТЕРЕСА (вставка, если нет)
        // Игнорируем ошибку, если запись уже существует
        $stmt = $conn->prepare("INSERT IGNORE INTO user_interests (user_id, topic) VALUES (?, ?)");
        $stmt->bind_param("is", $userId, $topic);
        $stmt->execute();
        $stmt->close();
    } elseif ($change < 0) {
        // УМЕНЬШЕНИЕ ИНТЕРЕСА (удаление)
        // Это очень упрощенный способ "уменьшить" интерес
        $stmt = $conn->prepare("DELETE FROM user_interests WHERE user_id = ? AND topic = ?");
        $stmt->bind_param("is", $userId, $topic);
        $stmt->execute();
        $stmt->close();
    }
}


// ===== AJAX обработка =====
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"])) {
    $action = $_POST["action"] ?? "";

    if ($action === "addPost") {
        $text = trim($_POST["text"] ?? "");
        $parent_id = !empty($_POST["parent_id"]) ? intval($_POST["parent_id"]) : NULL;
        $original_post_id = !empty($_POST["original_post_id"]) ? intval($_POST["original_post_id"]) : NULL;

        // Разрешаем пустой текст только если есть медиа или репост
        $hasFiles = isset($_FILES['media']) && count(array_filter($_FILES['media']['name'] ?? [])) > 0;
        if ($text !== "" || $hasFiles || $original_post_id !== NULL) {
            
            // [ИЗМЕНЕНИЕ] Классификация текста ИИ
            $topic = null;
            if ($text !== "" && $original_post_id === NULL) {
                // Классифицируем только если есть текст И это НЕ репост.
                // Репост не должен менять тему оригинала.
                $topic = EmbeddedTopicClassifier::classify($text);
            } elseif ($original_post_id !== NULL) {
                // Если это репост (без своего текста), подтянуть тему из оригинала
                $oTopicStmt = $conn->prepare("SELECT topic FROM posts WHERE id = ?");
                $oTopicStmt->bind_param("i", $original_post_id);
                $oTopicStmt->execute();
                $oTopicResult = $oTopicStmt->get_result()->fetch_assoc();
                $oTopicStmt->close();
                if ($oTopicResult) {
                    $topic = $oTopicResult['topic'];
                }
            }

            // [ИЗМЕНЕНИЕ] Вставляем пост, включая новое поле `topic`
            $stmt = $conn->prepare("INSERT INTO posts (user_id, text, parent_id, original_post_id, topic) VALUES (?, ?, ?, ?, ?)");
            // [ИЗМЕНЕНИЕ] Типы "isii" (int, string, int, int) меняем на "isiis" (int, string, int, int, string)
            $stmt->bind_param("isiis", $currentUserId, $text, $parent_id, $original_post_id, $topic); 
            $stmt->execute();
            $post_id = $stmt->insert_id;
            $stmt->close();

            // [НОВОЕ] Увеличение интереса пользователя к теме созданного поста/репоста
            if (!empty($topic)) {
                update_user_interest($currentUserId, $topic, 1, $conn);
            }


            // Обработка медиа (до 5 файлов), принимаем image/, video/, audio/
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
            // [ИЗМЕНЕНИЕ] Добавляем `p.topic` в SELECT
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
                // [ИЗМЕНЕНИЕ] Добавляем `p.topic` в SELECT для оригинала
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
            
            // НОВОЕ: Проверка верификации
            $post['is_verified'] = is_user_verified(intval($post['user_id']), $conn);

            echo json_encode(["success"=>true, "post"=>$post]);
            exit;
        }
    }

    if ($action === "loadPosts") {
        $offset = intval($_POST["offset"] ?? 0);
        $limit = 5;

        $stmt = $conn->prepare("SELECT p.*, u.username, u.avatar, (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id AND l.is_dislike = 0) as like_count, (SELECT COUNT(*) FROM likes d WHERE d.post_id = p.id AND d.is_dislike=1) as dislike_count
FROM posts p JOIN users u ON p.user_id=u.id
WHERE p.parent_id IS NULL
ORDER BY p.id DESC
LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
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
            
            // НОВОЕ: Проверка верификации
            $p['is_verified'] = is_user_verified(intval($p['user_id']), $conn);
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
            
            // НОВОЕ: Проверка верификации
            $r['is_verified'] = is_user_verified(intval($r['user_id']), $conn);
        }
        $mediaStmt->close();

        echo json_encode($replies);
        exit;
    }

// ===== Обработчик лайков и дизлайков =====
    if ($action === "toggleLike" || $action === "toggleDislike") {
        $post_id = intval($_POST["post_id"] ?? 0);
        $user_id = $currentUserId;
        $is_dislike = ($action === "toggleDislike") ? 1 : 0; // Определяем, это дизлайк (1) или лайк (0)

        // Ищем тему поста
        $topic = null;
        $tStmt = $conn->prepare("SELECT topic FROM posts WHERE id = ?");
        $tStmt->bind_param("i", $post_id);
        $tStmt->execute();
        $tResult = $tStmt->get_result()->fetch_assoc();
        $tStmt->close();
        if ($tResult) {
            $topic = $tResult['topic'];
        }
        
        // Ищем, есть ли уже реакция от этого пользователя на этот пост
        $stmt = $conn->prepare("SELECT id, is_dislike FROM likes WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            // Реакция уже есть
            if ($res['is_dislike'] == $is_dislike) {
                // Если тип реакции совпадает (пользователь лайкает то, что уже лайкнул), удаляем запись
                $stmt = $conn->prepare("DELETE FROM likes WHERE id = ?");
                $stmt->bind_param("i", $res['id']);
                $stmt->execute();
                $stmt->close();

                // [НОВОЕ] Если пользователь отменил лайк/дизлайк - уменьшаем интерес
                if ($res['is_dislike'] == 0 && $topic) { // Отменен лайк
                    update_user_interest($user_id, $topic, -1, $conn);
                } elseif ($res['is_dislike'] == 1 && $topic) { // Отменен дизлайк
                    update_user_interest($user_id, $topic, 1, $conn); // Дизлайк понижает, отмена дизлайка повышает
                }

            } else {
                // Если тип реакции отличается (лайк на дизлайк или наоборот), обновляем запись
                $stmt = $conn->prepare("UPDATE likes SET is_dislike = ? WHERE id = ?");
                $stmt->bind_param("ii", $is_dislike, $res['id']);
                $stmt->execute();
                $stmt->close();

                // [НОВОЕ] Смена реакции
                if ($is_dislike == 0 && $topic) { // С дизлайка на лайк: повышаем интерес
                    update_user_interest($user_id, $topic, 1, $conn);
                } elseif ($is_dislike == 1 && $topic) { // С лайка на дизлайк: понижаем интерес
                    update_user_interest($user_id, $topic, -1, $conn);
                }
            }
        } else {
            // Реакции ещё нет, добавляем новую
            $stmt = $conn->prepare("INSERT INTO likes (post_id, user_id, is_dislike) VALUES (?, ?, ?)");
            $stmt->bind_param("iii", $post_id, $user_id, $is_dislike);
            $stmt->execute();
            $stmt->close();

            // [НОВОЕ] Добавление реакции
            if ($is_dislike == 0 && $topic) { // Новый лайк: повышаем интерес
                update_user_interest($user_id, $topic, 1, $conn);
            } elseif ($is_dislike == 1 && $topic) { // Новый дизлайк: понижаем интерес
                update_user_interest($user_id, $topic, -1, $conn);
            }
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
        $user_id = $currentUserId;

        // Проверяем, что пост существует и принадлежит текущему пользователю
        $stmt = $conn->prepare("SELECT user_id FROM posts WHERE id = ?");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $post = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($post && $post['user_id'] == $user_id) {
            // Удаляем медиафайлы
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

            // Удаляем директорию
            $uploadDir = __DIR__ . '/uploads/posts/' . $post_id;
            if (is_dir($uploadDir)) {
                @rmdir($uploadDir); // Используем @ на случай, если папка не пуста (хотя не должна быть)
            }

            // Удаляем записи из базы
            $conn->query("DELETE FROM likes WHERE post_id = $post_id");
            $conn->query("DELETE FROM post_media WHERE post_id = $post_id");
            $conn->query("DELETE FROM posts WHERE id = $post_id OR parent_id = $post_id"); // Удаляем сам пост и все ответы
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "message" => "У вас нет прав на удаление этого поста или он не существует."]);
        }
        exit;
    }
    
    // Жалобы не будут храниться в базе в этом примере. Просто вернем успех.
    if ($action === "reportPost") {
        $post_id = intval($_POST["post_id"] ?? 0);
        $user_id = $currentUserId;
        
        // Проверяем, существует ли уже жалоба от этого пользователя на этот пост
        $stmt = $conn->prepare("SELECT id FROM reports WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $existing_report = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($existing_report) {
            // Если жалоба уже есть, сообщаем об этом
            echo json_encode(["success" => false, "message" => "Вы уже отправили жалобу на этот пост."]);
        } else {
            // Если жалобы нет, добавляем новую запись в таблицу reports
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
?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Лента</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <style>
.repeated { opacity: 0.7; position: relative; }
.repeated .repeat-badge { position: absolute; right: 10px; top: 10px; background: rgba(0,0,0,0.6); color: #fff; padding: 2px 6px; border-radius: 6px; font-size: 12px; }
.preview-thumb { position: relative; }
.preview-remove { position: absolute; right: 4px; top: 4px; background: rgba(0,0,0,0.6); border: none; color: white; padding: 2px 6px; border-radius: 4px; cursor: pointer; }
/* Новый стиль для контейнеров 1:1 */
.media-wrapper {
    position: relative;
    width: 100%;
    padding-top: 100%; /* Соотношение сторон 1:1 */
    overflow: hidden;
    background-color: #1a202c; /* bg-gray-900 */
    border-radius: 0.5rem;
}
.media-wrapper img, .media-wrapper video, .media-wrapper audio, .media-wrapper .file-link {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: contain; /* Чтобы контент был виден целиком */
}
.media-wrapper audio {
    top: 50%;
    transform: translateY(-50%);
    height: auto;
}
.media-wrapper .file-link {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 1rem;
    color: #9ca3af; /* text-gray-400 */
    text-decoration: underline;
}
    .user-avatar {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        margin-right: 12px;
        object-fit: cover; /* чтобы аватарки не искажались */
    }
    </style>
</head>
<body class="bg-gray-900 text-white flex justify-center">
<main class="w-full md:w-2/3 lg:w-1/2 border-x border-gray-800 min-h-screen">
    <header class="sticky top-0 bg-gray-900 bg-opacity-80 backdrop-blur-sm p-4 border-b border-gray-800 flex justify-between">
        <h1 class="text-xl font-bold">Главная</h1>
        <div>Привет, <?=htmlspecialchars($_SESSION["username"])?> | <a href="logout.php" class="text-blue-500">Выйти</a></div>
    </header>

    <div class="p-4 border-b border-gray-800">
        <form id="post-form" class="space-y-2" enctype="multipart/form-data">
            <textarea name="text" placeholder="Что происходит?" rows="3" class="w-full bg-gray-800 text-white text-lg resize-none outline-none py-2 px-3 rounded"></textarea>
            <div class="flex items-center justify-between">
                <div>
                    <label for="media-input" class="cursor-pointer text-gray-400 hover:text-blue-400 transition-colors duration-200">
                        <span class="text-2xl">📎</span>
                    </label>
                    <input type="file" id="media-input" name="media[]" accept="image/*,video/*,audio/*" multiple class="hidden">
                    <div class="text-sm text-gray-400"></div>
                </div>
                <div class="flex items-center space-x-2">
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-6 rounded-full">Опубликовать</button>
                </div>
            </div>
            <div id="post-preview" class="mt-2 grid gap-2 grid-cols-2"></div>
        </form>
    </div>
    <?php
    //Пост мейкер
    
    ?>

    <div id="posts-container"></div>
    <div id="loading" class="text-center text-gray-400 p-4">Загрузка...</div>
</main>

<script>
/* ---------- Утилиты: экранирование и линкование текста (linkify) ---------- */
function escapeHtml(unsafe) {
    return unsafe
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/\"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

function extractYouTubeId(url) {
    // Поддерживаем youtube.com/watch?v=ID и youtu.be/ID
    try {
        let u = new URL(url);
        if(u.hostname.includes('youtu.be')) {
            return u.pathname.slice(1);
        }
        if(u.hostname.includes('youtube.com') || u.hostname.includes('www.youtube.com')) {
            return u.searchParams.get('v');
        }
    } catch(e) { return null; }
    return null;
}

function linkify(text) {
    if(!text) return '';
    text = escapeHtml(text);
    const urlRegex = /https?:\/\/[^\s<]+/g;
    return text.replace(urlRegex, function(url){
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-blue-400 underline">${url}</a>`;
    });
}

/* ---------- Рендер медиа (из post.media) ---------- */
function renderMedia(media){
    if(!media || !media.length) return '';
    // Новый контейнер с сеткой для 1:1
    let html = '<div class="grid gap-2 mt-2 grid-cols-2 lg:grid-cols-3">';
    media.forEach(m=>{
        // Общий контейнер с классом media-wrapper для 1:1
        html += `<div class="media-wrapper">`;
        if(m.mime && m.mime.startsWith('image/')){
            html += `<img src="${m.file_path}" alt="media">`;
        } else if(m.mime && m.mime.startsWith('video/')){
            html += `<video controls><source src="${m.file_path}" type="${m.mime}">Ваш браузер не поддерживает видео.</video>`;
        } else if(m.mime && m.mime.startsWith('audio/')){
            html += `<audio controls><source src="${m.file_path}" type="${m.mime}">Ваш браузер не поддерживает аудио.</audio>`;
        } else {
            html += `<a href="${m.file_path}" target="_blank" class="file-link">Скачать файл</a>`;
        }
        html += '</div>';
    });
    html += '</div>';
    return html;
}

/* ---------- Рендер поста (используем linkify) ---------- */
/* ---------- Рендер поста (используем linkify) ---------- */
function renderPost(post, isChild = false, repeated = false, current_user_id) {
    const textHtml = linkify(post.text || '');
    let ytFrameHtml = '';

    // НОВОЕ: Значок верификации (голубая иконка)
    const verifiedBadge = post.is_verified 
        ? '<span title="Верифицированный аккаунт" class="inline-flex items-center justify-center h-4 w-4 bg-blue-500 text-white text-xs font-bold rounded-full ml-1 flex-shrink-0">✓</span>' 
        : '';

    // Находим все YouTube-ссылки в тексте и создаем для них iframe
    if (post.text) {
        const urlRegex = /https?:\/\/[^\s<]+/g;
        const urls = post.text.match(urlRegex) || [];
        urls.forEach(url => {
            const yt = extractYouTubeId(url);
            if (yt) {
                // Используем ссылку с параметром `embed` для встраивания плеера
                ytFrameHtml += `<div class="mt-2"><iframe width="100%" height="315" src="https://www.youtube.com/embed/${yt}" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
            }
        });
    }

    // Если есть оригинал (репост), рендерим оригинал внутри (текст, юзер, медиа)
    let origHtml = '';
    if (post.original_post) {
        let o = post.original_post;
        let oText = linkify(o.text || '');
        const oAvatarHtml = o.avatar ? `<img src="${o.avatar}" alt="Аватарка" class="w-8 h-8 rounded-full mr-2">` : '';
        // ССЫЛКА НА ПРОФИЛЬ В РЕПОСТЕ
        origHtml = `<div class="mt-2 p-3 bg-gray-800 rounded border border-gray-700">
            <a href="profile.php?id=${o.user_id}" class="flex items-center hover:underline">
                ${oAvatarHtml}
                <div class="font-semibold">${escapeHtml(o.username)}</div>
            </a>
            <div class="text-sm text-gray-300 mt-1">${oText}</div>
            ${renderMedia(o.media)}
        </div>`;
    } else if (post.original_post_id && !post.original_post) {
        origHtml = `<div class="mt-2 p-3 bg-gray-800 rounded border border-gray-700 text-gray-400">Оригинал недоступен</div>`;
    }

    // Добавляем выпадающее меню с кнопками
    let dropdownMenu = '';
    if (post.user_id == current_user_id) {
        // Это пост текущего пользователя
        dropdownMenu = `<div class="relative inline-block text-right">
            <button class="menu-btn text-gray-400 hover:text-white">...</button>
            <div class="menu-content absolute right-0 mt-2 w-48 bg-gray-700 rounded-md shadow-lg hidden">
                <button data-id="${post.id}" class="delete-post-btn block w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-gray-600">
                    Удалить пост
                </button>
            </div>
        </div>`;
    } else {
        // Это чужой пост
        dropdownMenu = `<div class="relative inline-block text-right">
            <button class="menu-btn text-gray-400 hover:text-white">...</button>
            <div class="menu-content absolute right-0 mt-2 w-48 bg-gray-700 rounded-md shadow-lg hidden">
                <button data-id="${post.id}" class="report-post-btn block w-full text-left px-4 py-2 text-sm text-yellow-400 hover:bg-gray-600">
                    Пожаловаться
                </button>
            </div>
        </div>`;
    }

    const avatarHtml = post.avatar ? `<img src="${post.avatar}" alt="Аватарка" class="w-10 h-10 rounded-full mr-3">` : '';
    let repeatedClass = repeated ? 'repeated' : '';
    let repeatBadge = repeated ? '<div class="repeat-badge">повтор</div>' : '';

    return `<div class="p-4 border-b border-gray-800 ${isChild ? 'bg-gray-850' : 'bg-transparent'} ${repeatedClass}" data-post-id="${post.id}">
        ${repeatBadge}
        <div class="flex justify-between items-center">
            <a href="profile.php?id=${post.user_id}" class="flex items-center hover:underline">
                ${avatarHtml}
                <span class="font-bold">${escapeHtml(post.username)}</span>${verifiedBadge}
            </a>
            <div class="flex items-center space-x-2">
                <span class="text-gray-500 text-sm">${post.created_at || ''}</span>
                ${dropdownMenu}
            </div>
        </div>
        <p class="mt-1 text-break">${textHtml}</p>
        ${ytFrameHtml} ${renderMedia(post.media)}
        ${origHtml}
        <div class="flex space-x-4 text-gray-400 text-sm mt-2">
            <button class="like-btn" data-id="${post.id}">❤️ ${post.like_count ?? 0}</button>
            <button class="dislike-btn" data-id="${post.id}">👎 ${post.dislike_count ?? 0}</button>
            <button class="reply-btn" data-id="${post.id}">💬 Ответить</button>
            <button class="repost-btn" data-id="${post.id}">🔁 Репост</button>
        </div>
        <div id="reply-${post.id}" class="hidden mt-2">
            <form class="reply-form flex flex-col space-y-2 mb-2" data-parent="${post.id}" enctype="multipart/form-data">
                <div class="flex space-x-2">
                    <input type="text" name="text" placeholder="Ответить..." class="flex-1 bg-gray-800 p-2 rounded" />
                    <button type="submit" class="bg-blue-500 px-3 rounded">Отправить</button>
                </div>
                <div class="flex items-center justify-between">
                    <label class="cursor-pointer text-gray-400 hover:text-blue-400 transition-colors duration-200">
                        <span class="text-2xl">📎</span>
                        <input type="file" name="media[]" accept="image/*,video/*,audio/*" multiple class="reply-media-input hidden">
                    </label>
                    <div class="text-sm text-gray-400"></div>
                </div>
                <div class="reply-preview mt-2 grid gap-2 grid-cols-2"></div>
            </form>
            <div class="child-posts" id="child-${post.id}"></div>
        </div>
    </div>`;
}

/* ---------- Бесконечная прокрутка с режимом повтора ---------- */
let offset = 0;
const limit = 5;
let loading = false;
let allLoaded = false;     // true, когда сервер вернул пустой набор (прошли все посты)
let repeatMode = false;    // если true — мы подгружаем посты с начала и помечаем их как повтор

function loadPosts(reset = false){
    // Этот ID мы получаем из PHP в начале файла.
    const current_user_id = <?= $currentUserId ?>;

    if(loading) return;
    loading = true;
    if(reset){
        offset = 0;
        allLoaded = false;
        repeatMode = false;
    }

    $('#loading').show().text('Загрузка...');

    $.post('feed.php', {ajax:1, action:'loadPosts', offset:offset}, function(posts){
        posts = JSON.parse(posts);
        if(reset) $('#posts-container').empty();
        if(!posts || posts.length === 0){
            if(!allLoaded){
                // первый раз дошли до конца
                allLoaded = true;
                $('#loading').text('Посты закончились — лента может повторяться при скролле вниз');
            } else {
                // уже были в состоянии allLoaded — включаем повтор
                repeatMode = true;
                offset = 0; // начнём снова с нуля
                $('#loading').text('Повтор ленты...');
                // подгружаем снова
                loading = false;
                loadPosts();
                return;
            }
            loading = false;
            return;
        }

        // Если мы в repeatMode — помечаем их как повтор
        posts.forEach(p=>{
            $('#posts-container').append(renderPost(p, false, repeatMode, current_user_id));
        });

        offset += posts.length;
        $('#loading').hide();
        loading = false;
    }).fail(function(){
        $('#loading').text('Ошибка загрузки');
        loading = false;
    });
}

/* ---------- Загрузка комментариев (replies) ---------- */
function loadReplies(parentId){
    // Этот ID мы получаем из PHP в начале файла.
    const current_user_id = <?= $currentUserId ?>;

    $.post('feed.php', {ajax:1, action:'loadReplies', post_id: parentId}, function(res){
        let replies = JSON.parse(res);
        let container = $('#child-'+parentId);
        container.empty();
        if(replies && replies.length){
            replies.forEach(r=>{
                container.append(renderPost(r, true, false, current_user_id));
            });
        }
    });
}

/* ---------- Обработка формы создания поста + предпросмотр (post preview) ---------- */
function makePreviewElement(file, idx) {
    const wrapper = document.createElement('div');
    wrapper.className = 'preview-thumb rounded overflow-hidden relative';
    wrapper.style.minHeight = '48px';
    wrapper.dataset.index = idx;

    const removeBtn = document.createElement('button');
    removeBtn.className = 'preview-remove';
    removeBtn.type = 'button';
    removeBtn.textContent = '×';
    removeBtn.setAttribute('data-remove-index', idx);

    wrapper.appendChild(removeBtn);

    if(file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'w-full h-auto object-cover';
        img.style.maxHeight = '160px';
        const reader = new FileReader();
        reader.onload = e => img.src = e.target.result;
        reader.readAsDataURL(file);
        wrapper.appendChild(img);
    } else if(file.type.startsWith('video/')) {
        const vid = document.createElement('video');
        vid.controls = true;
        vid.className = 'w-full h-auto';
        vid.style.maxHeight = '160px';
        const reader = new FileReader();
        reader.onload = e => vid.src = e.target.result;
        reader.readAsDataURL(file);
        wrapper.appendChild(vid);
    } else if(file.type.startsWith('audio/')) {
        const audWrap = document.createElement('div');
        audWrap.className = 'p-2 bg-gray-800';
        const aud = document.createElement('audio');
        aud.controls = true;
        const reader = new FileReader();
        reader.onload = e => {
            aud.src = e.target.result;
        };
        reader.readAsDataURL(file);
        audWrap.appendChild(aud);
        wrapper.appendChild(audWrap);
    } else {
        const txt = document.createElement('div');
        txt.className = 'p-2';
        txt.textContent = file.name;
        wrapper.appendChild(txt);
    }
    return wrapper;
}

// вспомогательная функция: выставить input.files через DataTransfer на основании оставшихся файлов
function setFilesOnInput(inputEl, filesArray) {
    const dt = new DataTransfer();
    filesArray.forEach(f => dt.items.add(f));
    inputEl.files = dt.files;
}

// общий обработчик изменения файлов для формы поста
$('#media-input').on('change', function(e){
    const input = this;
    const container = $('#post-preview');
    container.empty();

    let files = Array.from(input.files);
    if(files.length > 5) {
        alert('Можно прикрепить не более 5 файлов');
        // оставить первые 5
        files = files.slice(0,5);
        setFilesOnInput(input, files);
    }

    files.forEach((f, idx) => {
        const el = makePreviewElement(f, idx);
        container.append(el);
    });
});

// делегированный обработчик для удаления превью в форме поста
$(document).on('click', '#post-preview .preview-remove', function(){
    const idx = parseInt($(this).attr('data-remove-index'));
    const input = document.getElementById('media-input');
    let files = Array.from(input.files);
    files.splice(idx, 1);
    setFilesOnInput(input, files);
    // обновим превью (перерендер)
    const container = $('#post-preview');
    container.empty();
    files.forEach((f, i) => container.append(makePreviewElement(f, i)));
});

/* ---------- Обработка динамических reply forms: превью/удаление ---------- */
$(document).on('change', '.reply-media-input', function(){
    const input = this;
    const form = $(this).closest('form');
    const preview = form.find('.reply-preview');
    preview.empty();
    let files = Array.from(input.files);
    if(files.length > 5){
        alert('Можно прикрепить не более 5 файлов');
        files = files.slice(0,5);
        setFilesOnInput(input, files);
    }
    files.forEach((f,idx)=>{
        preview.append(makePreviewElement(f, idx));
    });
});

$(document).on('click', '.reply-preview .preview-remove', function(){
    // найдём родительский input
    const btn = $(this);
    const parentPreview = btn.closest('.reply-preview');
    const form = btn.closest('form');
    const input = form.find('input[type=file]')[0];
    const idx = parseInt(btn.attr('data-remove-index'));
    let files = Array.from(input.files);
    files.splice(idx,1);
    setFilesOnInput(input, files);
    parentPreview.empty();
    files.forEach((f,i)=> parentPreview.append(makePreviewElement(f, i)));
});

/* ---------- Отправка формы создания поста (используем input.files, которые мы поддерживаем) ---------- */
$('#post-form').on('submit', function(e){
    e.preventDefault();

    const current_user_id = <?= $currentUserId ?>;
    const files = document.getElementById('media-input').files;
    if(files.length > 5){
        alert('Можно прикрепить не более 5 файлов');
        return;
    }

    const formData = new FormData();
    formData.append('ajax', 1);
    formData.append('action', 'addPost');
    formData.append('text', $(this).find('textarea[name=text]').val());

    for(let i=0;i<files.length;i++){
        formData.append('media[]', files[i]);
    }

    $.ajax({
        url: 'feed.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res){
            res = JSON.parse(res);
            if(res.success){
                // Передаем current_user_id в renderPost
                $('#posts-container').prepend(renderPost(res.post, false, false, current_user_id));
                $('#post-form')[0].reset();
                $('#post-preview').empty();
                // если мы в режиме повтора — выйти из него при создании нового поста
                allLoaded = false;
                repeatMode = false;
                offset = 0;
            } else {
                alert('Ошибка при создании поста');
            }
        }
    });
});

/* ---------- Делегированная логика: лайки, ответы, репосты ---------- */
// --- Обработка лайка ---
$(document).on('click', '.like-btn', function(){
    let id = $(this).data('id');
    let btn = $(this);
    let dislike_btn = btn.siblings('.dislike-btn');
    $.post('feed.php', {ajax:1, action:'toggleLike', post_id:id}, function(res){
        res = JSON.parse(res);
        if(res.success){
            btn.text('❤️ ' + res.like_count);
            dislike_btn.text('👎 ' + (res.dislike_count ?? 0));
        }
    });
});

// --- Обработка дизлайка ---
$(document).on('click', '.dislike-btn', function(){
    let id = $(this).data('id');
    let btn = $(this);
    let like_btn = btn.siblings('.like-btn');
    $.post('feed.php', {ajax:1, action:'toggleDislike', post_id:id}, function(res){
        res = JSON.parse(res);
        if(res.success){
            btn.text('👎 ' + (res.dislike_count ?? 0));
            like_btn.text('❤️ ' + res.like_count);
        }
    });
});

$(document).on('click', '.menu-btn', function() {
    // Скрываем все другие открытые меню
    $('.menu-content').addClass('hidden');
    // Показываем меню для этого поста
    $(this).siblings('.menu-content').toggleClass('hidden');
});

// Скрываем меню при клике в любом месте, кроме самого меню
$(document).on('click', function(e) {
    if (!$(e.target).closest('.relative').length) {
        $('.menu-content').addClass('hidden');
    }
});

// Обработчик для кнопки "Удалить"
$(document).on('click', '.delete-post-btn', function(e) {
    e.preventDefault();
    const postId = $(this).data('id');
    if (confirm("Вы уверены, что хотите удалить этот пост?")) {
        $.post('feed.php', { ajax: 1, action: 'deletePost', post_id: postId }, function(res) {
            res = JSON.parse(res);
            if (res.success) {
                $(`div[data-post-id="${postId}"]`).fadeOut(300, function() {
                    $(this).remove();
                });
            } else {
                alert('Ошибка: ' + res.message);
            }
        });
    }
});

// Обработчик для кнопки "Пожаловаться"
$(document).on('click', '.report-post-btn', function(e) {
    e.preventDefault();
    const postId = $(this).data('id');
    $.post('feed.php', { ajax: 1, action: 'reportPost', post_id: postId }, function(res) {
        res = JSON.parse(res);
        if (res.success) {
            alert('Спасибо, ваша жалоба отправлена.');
        } else {
            alert('Ошибка при отправке жалобы.');
        }
    });
});

$(document).on('click', '.reply-btn', function(){
    let id = $(this).data('id');
    let box = $('#reply-'+id);
    box.toggleClass('hidden');
    if(!box.hasClass('hidden')){
        if($('#child-'+id).children().length === 0){
            loadReplies(id);
        }
    }
});

$(document).on('submit', '.reply-form', function(e){
    e.preventDefault();
    let parent = $(this).data('parent');
    let text = $(this).find('input[name=text]').val();
    const inputFiles = $(this).find('input[type=file]')[0].files;
    if(inputFiles.length > 5){
        alert('Можно прикрепить не более 5 файлов');
        return;
    }
    const formData = new FormData();
    formData.append('ajax', 1);
    formData.append('action', 'addPost');
    formData.append('text', text);
    formData.append('parent_id', parent);

    for(let i=0;i<inputFiles.length;i++){
        formData.append('media[]', inputFiles[i]);
    }

    const current_user_id = <?= $currentUserId ?>;

    $.ajax({
        url: 'feed.php',
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(res){
            res = JSON.parse(res);
            if(res.success){
                // Передаем current_user_id в renderPost
                $('#child-'+parent).append(renderPost(res.post, true, false, current_user_id));
                $('form[data-parent="'+parent+'"] input[name=text]').val('');
                $('form[data-parent="'+parent+'"] input[type=file]').val('');
                $('form[data-parent="'+parent+'"] .reply-preview').empty();
            } else {
                alert('Ошибка при отправке ответа');
            }
        }
    });
});

// Обработка репоста
$(document).on('click', '.repost-btn', function(){
    let originalId = $(this).data('id');
    if(!confirm('Сделать репост?')) return;

    const current_user_id = <?= $currentUserId ?>;

    $.post('feed.php', {ajax:1, action:'addPost', original_post_id: originalId}, function(res){
        res = JSON.parse(res);
        if(res.success){
            // Передаем current_user_id в renderPost
            $('#posts-container').prepend(renderPost(res.post, false, false, current_user_id));
            window.scrollTo(0,0);
            // обновим offset/режимы
            allLoaded = false;
            repeatMode = false;
            offset = 0;
        } else {
            alert('Ошибка при репосте');
        }
    });
});

/* ---------- Бесконечный скролл ---------- */
$(window).scroll(function(){
    if($(window).scrollTop() + $(window).height() >= $(document).height() - 120){
        loadPosts();
    }
});

// Инициализация
loadPosts();
</script>
</body>
</html>