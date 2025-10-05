<?php
// profile.php — интегрированный вариант (feed функционал + профиль)
// Требует: elements/php/db.php, elements/php/header.php, elements/php/sidebar-left.php, elements/php/sidebar-right.php, elements/php/modal.php, elements/php/footer.php

session_start();
require_once __DIR__ . "/elements/php/db.php"; // $conn

if (!$conn) {
    http_response_code(500);
    echo "DB connection error";
    exit;
}

// Текущий пользователь (может быть null)
$currentUserId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

/* ---------------------------
   ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ
   --------------------------- */

// Кэш для проверки верификации (чтобы избежать лишних запросов к базе)
$GLOBALS['verified_users_cache'] = []; 
function is_user_verified($userId, $conn) {
    if (isset($GLOBALS['verified_users_cache'][$userId])) {
        return $GLOBALS['verified_users_cache'][$userId];
    }
    // Проверяем в таблице `verified_users`
    $stmt = $conn->prepare("SELECT 1 FROM verified_users WHERE user_id = ? LIMIT 1");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $is_verified = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    // Кэшируем результат
    $GLOBALS['verified_users_cache'][$userId] = $is_verified;
    return $is_verified;
}

// Рендер одного поста в HTML (используем для addPost, loadReplies, loadPosts)
function render_post_html(array $post, $isChild = false, $repeated = false, $currentUserId = null, $is_verified = false) {
    // $post: ассоц. массив с полями: id, user_id, username, avatar, text, created_at, like_count, dislike_count, media (array), original_post (assoc) возможно
    $id = intval($post['id']);
    $username = htmlspecialchars($post['username'] ?? 'user');
    $avatar = htmlspecialchars($post['avatar'] ?? 'elements/img/default-avatar.png');
    $text = nl2br(htmlspecialchars($post['text'] ?? ''));
    $created = htmlspecialchars($post['created_at'] ?? '');
    $likes = intval($post['like_count'] ?? 0);
    $dislikes = intval($post['dislike_count'] ?? 0);

    $isOwner = ($currentUserId !== null && intval($post['user_id']) === $currentUserId);
    $repeatedClass = $repeated ? 'repeated' : '';
    $repeatBadge = $repeated ? '<div class="repeat-badge">повтор</div>' : '';
    
    // Значок верификации (НОВОЕ)
        $verifiedBadge = $is_verified 
        ? '<span title="Верифицированный аккаунт" class="inline-flex items-center justify-center h-4 w-4 bg-blue-500 text-white text-xs font-bold rounded-full ml-1 flex-shrink-0">✓</span>' 
        : '';


    // avatar HTML
    $avatarHtml = "<img src=\"{$avatar}\" class=\"w-10 h-10 rounded-full mr-3 object-cover\" alt=\"avatar\">";

    // Dropdown menu
    if ($isOwner) {
        $dropdown = "<div class=\"relative inline-block text-right\">
            <button class=\"menu-btn text-gray-400 hover:text-white\">...</button>
            <div class=\"menu-content absolute right-0 mt-2 w-48 bg-gray-700 rounded-md shadow-lg hidden\">
                <button data-id=\"{$id}\" class=\"delete-post-btn block w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-gray-600\">Удалить пост</button>
            </div>
        </div>";
    } else {
        $dropdown = "<div class=\"relative inline-block text-right\">
            <button class=\"menu-btn text-gray-400 hover:text-white\">...</button>
            <div class=\"menu-content absolute right-0 mt-2 w-48 bg-gray-700 rounded-md shadow-lg hidden\">
                <button data-id=\"{$id}\" class=\"report-post-btn block w-full text-left px-4 py-2 text-sm text-yellow-400 hover:bg-gray-600\">Пожаловаться</button>
            </div>
        </div>";
    }

    // media
    $mediaHtml = '';
    if (!empty($post['media']) && is_array($post['media'])) {
        $mediaHtml .= '<div class="grid gap-2 mt-2 grid-cols-2 lg:grid-cols-3">';
        foreach ($post['media'] as $m) {
            $m_path = htmlspecialchars($m['file_path'] ?? '');
            $m_mime = $m['mime'] ?? '';
            if ($m_path === '') continue;
            $mediaHtml .= '<div class="media-wrapper overflow-hidden rounded border border-gray-700">';
            if (strpos($m_mime, 'image/') === 0) {
                $mediaHtml .= "<img src=\"/{$m_path}\" alt=\"media\" class=\"w-full h-full object-cover\">";
            } elseif (strpos($m_mime, 'video/') === 0) {
                $mediaHtml .= "<video controls class=\"w-full h-full\"><source src=\"/{$m_path}\" type=\"{$m_mime}\">Ваш браузер не поддерживает видео.</video>";
            } elseif (strpos($m_mime, 'audio/') === 0) {
                $mediaHtml .= "<audio controls class=\"w-full\"><source src=\"/{$m_path}\" type=\"{$m_mime}\">Ваш браузер не поддерживает аудио.</audio>";
            } else {
                $mediaHtml .= "<a href=\"/{$m_path}\" target=\"_blank\" class=\"file-link\">Скачать</a>";
            }
            $mediaHtml .= '</div>';
        }
        $mediaHtml .= '</div>';
    }

    // original post (репост)
    $origHtml = '';
    if (!empty($post['original_post']) && is_array($post['original_post'])) {
        $o = $post['original_post'];
        $o_avatar = htmlspecialchars($o['avatar'] ?? 'elements/img/default-avatar.png');
        $o_username = htmlspecialchars($o['username'] ?? 'user');
        $o_text = nl2br(htmlspecialchars($o['text'] ?? ''));
        $orig_media = '';
        if (!empty($o['media']) && is_array($o['media'])) {
            $orig_media .= '<div class="grid gap-2 mt-2 grid-cols-2 lg:grid-cols-3">';
            foreach ($o['media'] as $m) {
                $m_path = htmlspecialchars($m['file_path'] ?? '');
                $m_mime = $m['mime'] ?? '';
                if ($m_path === '') continue;
                $orig_media .= '<div class="media-wrapper overflow-hidden rounded border border-gray-700">';
                if (strpos($m_mime, 'image/') === 0) {
                    $orig_media .= "<img src=\"/{$m_path}\" alt=\"media\" class=\"w-full h-full object-cover\">";
                } elseif (strpos($m_mime, 'video/') === 0) {
                    $orig_media .= "<video controls class=\"w-full h-full\"><source src=\"/{$m_path}\" type=\"{$m_mime}\">Ваш браузер не поддерживает видео.</video>";
                } elseif (strpos($m_mime, 'audio/') === 0) {
                    $orig_media .= "<audio controls class=\"w-full\"><source src=\"/{$m_path}\" type=\"{$m_mime}\">Ваш браузер не поддерживает аудио.</audio>";
                } else {
                    $orig_media .= "<a href=\"/{$m_path}\" target=\"_blank\" class=\"file-link\">Скачать</a>";
                }
                $orig_media .= '</div>';
            }
            $orig_media .= '</div>';
        }
        $origHtml = "<div class=\"mt-2 p-3 bg-gray-800 rounded border border-gray-700\">
            <div class=\"flex items-center\">
                <img src=\"{$o_avatar}\" class=\"w-8 h-8 rounded-full mr-2 object-cover\">
                <div class=\"font-semibold\">{$o_username}</div>
            </div>
            <div class=\"text-sm text-gray-300 mt-1\">{$o_text}</div>
            {$orig_media}
        </div>";
    } elseif (!empty($post['original_post_id']) && empty($post['original_post'])) {
        $origHtml = '<div class="mt-2 p-3 bg-gray-800 rounded border border-gray-700 text-gray-400">Оригинал недоступен</div>';
    }

    // итоговая разметка
    $html = "<div class=\"p-4 border-b border-gray-800 " . ($isChild ? 'bg-gray-850' : 'bg-transparent') . " {$repeatedClass} post-item\" data-post-id=\"{$id}\">";
    $html .= $repeatBadge;
    $userId = intval($post['user_id'] ?? 0); // Извлекаем ID для ссылки
    
    // ...
    $html .= "<div class=\"flex justify-between items-center\">";
    $html .= "<a href=\"profile.php?id={$userId}\" class=\"flex items-center hover:underline\">{$avatarHtml}<span class=\"font-bold\">{$username}</span>{$verifiedBadge}</a>"; // <-- ОБЕРНУТО В ССЫЛКУ
    $html .= "<div class=\"flex items-center space-x-2\"><span class=\"text-gray-500 text-sm\">{$created}</span>{$dropdown}</div>";
    $html .= "</div>";
    $html .= "<p class=\"mt-1 text-break post-text\">{$text}</p>";
    $html .= $mediaHtml;
    $html .= $origHtml;
    $html .= "<div class=\"flex space-x-4 text-gray-400 text-sm mt-2 post-actions\">";
    $html .= "<button class=\"like-btn\" data-id=\"{$id}\">❤️ <span class=\"likes-count\">{$likes}</span></button>";
    $html .= "<button class=\"dislike-btn\" data-id=\"{$id}\">👎 <span class=\"dislikes-count\">{$dislikes}</span></button>";
    $html .= "<button class=\"reply-btn\" data-id=\"{$id}\">💬 Ответить</button>";
    $html .= "<button class=\"repost-btn\" data-id=\"{$id}\">🔁 Репост</button>";
    $html .= "</div>";

    // reply form + child posts container
    $html .= "<div id=\"reply-{$id}\" class=\"hidden mt-2\">";
    $html .= "<form class=\"reply-form flex flex-col space-y-2 mb-2\" data-parent=\"{$id}\" enctype=\"multipart/form-data\">";
    $html .= "<div class=\"flex space-x-2\">";
    $html .= "<input type=\"text\" name=\"text\" placeholder=\"Ответить...\" class=\"flex-1 bg-gray-800 p-2 rounded\" />";
    $html .= "<button type=\"submit\" class=\"bg-blue-500 px-3 py-1 rounded hover:bg-blue-600 transition-colors\">Отправить</button>";
    $html .= "</div>";
    $html .= "<div class=\"flex items-center justify-between\">";
    $html .= "<label class=\"cursor-pointer text-gray-400 hover:text-blue-400 transition-colors duration-200\">";
    $html .= "<span class=\"text-2xl\">📎</span>";
    $html .= "<input type=\"file\" name=\"media[]\" accept=\"image/*,video/*,audio/*\" multiple class=\"reply-media-input hidden\">";
    $html .= "</label>";
    $html .= "<div class=\"text-sm text-gray-400\"></div>";
    $html .= "</div>";
    $html .= "<div class=\"reply-preview mt-2 grid gap-2 grid-cols-2\"></div>";
    $html .= "</form>";
    $html .= "<div class=\"child-posts\" id=\"child-{$id}\"></div>";
    $html .= "</div>";

    $html .= '</div>';
    return $html;
}

/* ---------------------------
    AJAX обработчики (addPost, loadPosts, loadReplies, toggleLike/Dislike, deletePost, report)
    --------------------------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajax']) || isset($_POST['action']))) {
    // Совмещаем: может быть ajax value 'loadPosts' (старый профильный код) или ajax=1 and action=...
    // Нормализуем
    $ajaxFlag = $_POST['ajax'] ?? null;
    $action = $_POST['action'] ?? null;

    // Для совместимости: если ajax === 'loadPosts' (строка) — это профильный запрос от старого скрипта
    if ($ajaxFlag === 'loadPosts' || ($ajaxFlag && $action === 'loadPosts')) {
        // Профильная подгрузка постов (возвращаем HTML и флаг hasMore)
        header('Content-Type: application/json; charset=utf-8');
        $profileUserId = intval($_POST['profile_user_id'] ?? 0);
        $offset = max(0, intval($_POST['offset'] ?? 0));
        $limit = max(1, min(50, intval($_POST['limit'] ?? 10)));

        // Берём посты пользователя
        $sql = "SELECT p.id, p.user_id, p.text, p.created_at, u.username, u.avatar,
                        (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id AND l.is_dislike = 0) AS like_count,
                        (SELECT COUNT(*) FROM likes l WHERE l.post_id = p.id AND l.is_dislike = 1) AS dislike_count
                FROM posts p
                JOIN users u ON p.user_id = u.id
                WHERE p.user_id = ?
                ORDER BY p.id DESC
                LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('iii', $profileUserId, $limit, $offset);
        $stmt->execute();
        $res = $stmt->get_result();
        $posts = [];
        while ($row = $res->fetch_assoc()) {
            // подтянем медиа
            $mediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
            $mediaStmt->bind_param("i", $row['id']);
            $mediaStmt->execute();
            $row['media'] = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $mediaStmt->close();

            // Если это репост — подтянем кратко оригинал
            if (!empty($row['original_post_id'] ?? null)) {
                $oStmt = $conn->prepare("SELECT p.id,p.text,u.username,u.avatar FROM posts p JOIN users u ON p.user_id=u.id WHERE p.id = ?");
                $oStmt->bind_param("i", $row['original_post_id']);
                $oStmt->execute();
                $orig = $oStmt->get_result()->fetch_assoc();
                if ($orig) {
                    $oMediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
                    $oMediaStmt->bind_param("i", $row['original_post_id']);
                    $oMediaStmt->execute();
                    $orig['media'] = $oMediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
                    $oMediaStmt->close();
                    $row['original_post'] = $orig;
                }
                $oStmt->close();
            }
            
            // НОВОЕ: Проверка верификации
            $row['is_verified'] = is_user_verified(intval($row['user_id']), $conn);
            $posts[] = $row;
        }
        $stmt->close();

        ob_start();
        foreach ($posts as $p) {
            // НОВОЕ: Передача статуса верификации в render_post_html
            echo render_post_html($p, false, false, $currentUserId, $p['is_verified']);
        }
        $html = ob_get_clean();
        $hasMore = count($posts) === $limit;
        echo json_encode(['postsHtml' => $html, 'hasMore' => $hasMore]);
        exit;
    }

    // Если ajax numeric flag like 1 and action set, используем action-based branch:
    if ($action === 'addPost') {
        // Только авторизованные пользователи могут добавлять посты
        if (!$currentUserId) { echo json_encode(['success'=>false,'error'=>'login_required']); exit; }

        $text = trim($_POST['text'] ?? "");
        $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : NULL;
        $original_post_id = !empty($_POST['original_post_id']) ? intval($_POST['original_post_id']) : NULL;

        // Разрешаем пустой текст только если есть медиа или репост
        $hasFiles = isset($_FILES['media']) && count(array_filter($_FILES['media']['name'] ?? [])) > 0;
        if ($text !== "" || $hasFiles || $original_post_id !== NULL) {
            // Вставляем пост
            $stmt = $conn->prepare("INSERT INTO posts (user_id, text, parent_id, original_post_id, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->bind_param("isii", $currentUserId, $text, $parent_id, $original_post_id);
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
                $oStmt = $conn->prepare("SELECT p.*, u.username, u.avatar FROM posts p JOIN users u ON p.user_id=u.id WHERE p.id = ?");
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

            // Возвращаем HTML поста (чтобы клиент мог вставить сразу готовую разметку)
            // НОВОЕ: Передача статуса верификации
            $postHtml = render_post_html($post, $parent_id ? true : false, false, $currentUserId, $post['is_verified']);
            echo json_encode(["success"=>true, "postHtml"=>$postHtml, "post"=>$post]);
            exit;
        }

        echo json_encode(["success"=>false, "error"=>"empty_post"]);
        exit;
    }

    if ($action === 'loadReplies') {
        $post_id = intval($_POST['post_id'] ?? 0);
        $stmt = $conn->prepare("SELECT p.*, u.username, u.avatar, (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id AND l.is_dislike = 0) as like_count, (SELECT COUNT(*) FROM likes d WHERE d.post_id = p.id AND d.is_dislike=1) as dislike_count
            FROM posts p JOIN users u ON p.user_id=u.id
            WHERE p.parent_id = ?
            ORDER BY p.id ASC");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $replies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        // Подтянем медиа для каждого
        $mediaStmt = $conn->prepare("SELECT id, file_path, mime, position FROM post_media WHERE post_id = ? ORDER BY position ASC");
        foreach ($replies as &$r) {
            $mediaStmt->bind_param("i", $r['id']);
            $mediaStmt->execute();
            $r['media'] = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $mediaStmt->close();

        // Вернём HTML
        ob_start();
        foreach ($replies as $r) {
            // НОВОЕ: Проверка и передача статуса верификации
            $is_verified = is_user_verified(intval($r['user_id']), $conn);
            echo render_post_html($r, true, false, $currentUserId, $is_verified);
        }
        $html = ob_get_clean();
        echo json_encode(['success'=>true, 'postsHtml'=>$html]);
        exit;
    }

    if ($action === 'toggleLike' || $action === 'toggleDislike') {
        if (!$currentUserId) { echo json_encode(['success'=>false,'error'=>'login_required']); exit; }
        $post_id = intval($_POST['post_id'] ?? 0);
        $user_id = $currentUserId;
        $is_dislike = ($action === "toggleDislike") ? 1 : 0;

        // ищем реакцию
        $stmt = $conn->prepare("SELECT id, is_dislike FROM likes WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($res) {
            if (intval($res['is_dislike']) === $is_dislike) {
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

        echo json_encode(["success"=>true, "like_count" => intval($counts['like_count']), "dislike_count" => intval($counts['dislike_count'])]);
        exit;
    }

    if ($action === 'deletePost') {
        if (!$currentUserId) { echo json_encode(['success'=>false,'error'=>'login_required']); exit; }
        $post_id = intval($_POST['post_id'] ?? 0);
        $user_id = $currentUserId;

        $stmt = $conn->prepare("SELECT user_id FROM posts WHERE id = ?");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $post = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($post && intval($post['user_id']) === $user_id) {
            // удалить медиа файлы
            $mediaStmt = $conn->prepare("SELECT file_path FROM post_media WHERE post_id = ?");
            $mediaStmt->bind_param("i", $post_id);
            $mediaStmt->execute();
            $mediaFiles = $mediaStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $mediaStmt->close();

            foreach ($mediaFiles as $file) {
                $fullPath = __DIR__ . '/' . $file['file_path'];
                if (file_exists($fullPath)) unlink($fullPath);
            }

            // удалить директорию (попробуем)
            $uploadDir = __DIR__ . '/uploads/posts/' . $post_id;
            if (is_dir($uploadDir)) {
                @rmdir($uploadDir);
            }

            // удалить записи
            $conn->query("DELETE FROM likes WHERE post_id = $post_id");
            $conn->query("DELETE FROM post_media WHERE post_id = $post_id");
            $conn->query("DELETE FROM posts WHERE id = $post_id OR parent_id = $post_id");

            echo json_encode(["success"=>true]);
        } else {
            echo json_encode(["success"=>false, "message" => "У вас нет прав на удаление этого поста или он не существует."]);
        }
        exit;
    }

    if ($action === 'reportPost') {
        if (!$currentUserId) { echo json_encode(['success'=>false,'error'=>'login_required']); exit; }
        $post_id = intval($_POST['post_id'] ?? 0);
        $user_id = $currentUserId;

        // проверим дубли
        $stmt = $conn->prepare("SELECT id FROM reports WHERE post_id = ? AND user_id = ?");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            echo json_encode(["success"=>false, "message"=>"Вы уже отправили жалобу на этот пост."]);
        } else {
            $stmt = $conn->prepare("INSERT INTO reports (post_id, user_id, created_at) VALUES (?, ?, NOW())");
            $stmt->bind_param("ii", $post_id, $user_id);
            if ($stmt->execute()) {
                echo json_encode(["success"=>true, "message"=>"Жалоба отправлена."]);
            } else {
                echo json_encode(["success"=>false, "message"=>"Не удалось отправить жалобу."]);
            }
            $stmt->close();
        }
        exit;
    }
    if ($action === 'toggleFollow') {
    if (!$currentUserId) { echo json_encode(['success'=>false,'error'=>'login_required']); exit; }
    $targetUserId = intval($_POST['target_user_id'] ?? 0);
    
    // 1. Нельзя подписаться на себя
    if ($targetUserId === $currentUserId) { echo json_encode(['success'=>false,'error'=>'cannot_follow_self']); exit; }

    // 2. Проверяем, существует ли подписка
    $stmt = $conn->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1");
    $stmt->bind_param("ii", $currentUserId, $targetUserId);
    $stmt->execute();
    $res = $stmt->get_result();
    $existing = ($res && $res->num_rows > 0) ? true : false;
    $stmt->close();

    $isFollowing = !!$existing;
    $newStatus = !$isFollowing; // Инвертируем статус

    $dbSuccess = false;

    if ($isFollowing) {
        // Отписаться
        $stmt = $conn->prepare("DELETE FROM follows WHERE follower_id = ? AND following_id = ?");
        $stmt->bind_param("ii", $currentUserId, $targetUserId);
        $dbSuccess = $stmt->execute();
        $stmt->close();
    } else {
        // Подписаться
        $stmt = $conn->prepare("INSERT INTO follows (follower_id, following_id, created_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("ii", $currentUserId, $targetUserId);
        $dbSuccess = $stmt->execute();
        $stmt->close();
    }

    if (!$dbSuccess) {
        echo json_encode(["success"=>false, "error"=>"DB_FAILURE: Could not complete follow action."]);
        exit; 
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS followers FROM follows WHERE following_id = ?"); 
    $stmt->bind_param('i', $targetUserId); 
    $stmt->execute(); 
    $followers = $stmt->get_result()->fetch_assoc()['followers'] ?? 0;
    $stmt->close();

    echo json_encode(["success"=>true, "newStatus" => $newStatus, "followersCount" => intval($followers)]);
    exit;
}


    // Если не распознали действие, просто возвращаем ошибку
    echo json_encode(['success'=>false,'error'=>'unknown_action']);
    exit;
}

/* ----------------------------------------------------
       AJAX-обработчики для мессенджера (ВСТАВИТЬ В profile.php)
    ---------------------------------------------------- */
    
    // --- Обработчик отправки сообщения (sendMessage) ---
    if ($action === 'sendMessage') {
        header('Content-Type: application/json');
        if (!$currentUserId) { echo json_encode(['success'=>false,'error'=>'login_required']); exit; }

        $text = trim($_POST['text'] ?? "");
        if ($text === "") { echo json_encode(["success"=>false, "error"=>"empty_message"]); exit; }

        $chatId = intval($_POST['chat_id'] ?? 0) ?: null;

        if (!$chatId) { 
            // Этого не должно случиться, так как chat_id должен быть создан в messages.php
            echo json_encode(["success"=>false, "error"=>"chat_id is missing"]); 
            exit; 
        }
        
        $conn->begin_transaction(); 

        try {
            // 1. Проверяем, что текущий пользователь — участник чата 
            $stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param("ii", $chatId, $currentUserId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) {
                throw new Exception("User not a participant");
            }
            $stmt->close();

            // 2. Отправляем сообщение
            $stmt = $conn->prepare("INSERT INTO messages (chat_id, sender_id, text, created_at) VALUES (?, ?, ?, NOW())");
            $stmt->bind_param("iis", $chatId, $currentUserId, $text);
            $dbSuccess = $stmt->execute();
            $stmt->close();

            if ($dbSuccess) {
                // 3. Обновляем время последнего сообщения в чате
                $stmt = $conn->prepare("UPDATE chats SET last_message_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $chatId);
                $stmt->execute();
                $stmt->close();
                
                $conn->commit();
                echo json_encode(["success"=>true, "chatId"=>$chatId, "messageText"=>htmlspecialchars($text)]);
                exit;
            } else {
                throw new Exception("Failed to insert message");
            }

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["success"=>false, "error"=>"Transaction failed: " . $e->getMessage()]); 
            exit;
        }

        echo json_encode(["success"=>false, "error"=>"Unknown error."]);
        exit;
    }
    
    // --- Обработчик загрузки сообщений (loadMessages) ---
    // (Этот код идентичен предыдущему универсальному варианту)
    if ($action === 'loadMessages') {
        header('Content-Type: application/json');
        if (!$currentUserId) { echo json_encode(['success'=>false,'error'=>'login_required']); exit; }
        
        $chatId = intval($_POST['chat_id'] ?? 0);
        
        $stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $chatId, $currentUserId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) {
            echo json_encode(["success"=>false, "error"=>"no_access"]);
            exit;
        }
        $stmt->close();
        
        // Загружаем сообщения
        $stmt = $conn->prepare("SELECT m.text, m.sender_id, m.created_at, u.username, u.avatar 
                                FROM messages m 
                                JOIN users u ON m.sender_id = u.id
                                WHERE m.chat_id = ? 
                                ORDER BY m.created_at DESC 
                                LIMIT 50");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $html = '';
        foreach (array_reverse($messages) as $msg) {
            $isSender = intval($msg['sender_id']) === $currentUserId;
            $bubbleClass = $isSender ? 'bg-blue-600 self-end' : 'bg-gray-700 self-start';
            $alignmentClass = $isSender ? 'justify-end' : 'justify-start';
            $senderName = $isSender ? 'Я' : htmlspecialchars($msg['username']);
            $time = (new DateTime($msg['created_at']))->format('H:i');
            $text = nl2br(htmlspecialchars($msg['text']));
            
            $html .= "<div class='flex w-full mb-2 {$alignmentClass}'>
                        <div class='max-w-xs lg:max-w-md p-3 rounded-xl {$bubbleClass} text-white'>
                            <p class='text-xs font-semibold text-gray-300 mb-1'>{$senderName} ({$time})</p>
                            <p>{$text}</p>
                        </div>
                      </div>";
        }

        echo json_encode(["success"=>true, "messagesHtml"=>$html]);
        exit;
    }

/* ---------------------------
    Конец AJAX блоков
    --------------------------- */

/* ---- Получаем данные профиля для отображения страницы ---- */
$profileUser = null;
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $id = intval($_GET['id']);
    $stmt = $conn->prepare("SELECT id, username, avatar, created_at, bio, banner FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $res = $stmt->get_result();
    $profileUser = $res->fetch_assoc();
    $stmt->close();
} elseif (isset($_GET['username'])) {
    $username = trim($_GET['username']);
    $stmt = $conn->prepare("SELECT id, username, avatar, created_at, bio, banner FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $res = $stmt->get_result();
    $profileUser = $res->fetch_assoc();
    $stmt->close();
}

// Если пользователь попал на profile.php без id/username — редиректим его на свой профиль (если он авторизован)
if (!isset($_GET['id']) && !isset($_GET['username'])) {
    if ($currentUserId) {
        // используем относительный путь к текущему скрипту и добавляем ?id=...
        $self = basename($_SERVER['PHP_SELF']); // обычно "profile.php"
        header("Location: {$self}?id=" . intval($currentUserId));
        exit;
    } else {
        // если не авторизован — просим залогиниться
        header("Location: login.php");
        exit;
    }
}


if (!$profileUser) {
    http_response_code(404);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Профиль не найден</title></head><body style="background:#0b0b0b;color:#ddd;font-family:Arial,Helvetica,sans-serif;padding:40px;">';
    echo '<h1>Профиль не найден</h1>';
    echo '</body></html>';
    exit;
}

$isFollowing = false;
$followersCount = 0;

if ($profileUser) {
    // 1. Считаем подписчиков
    $stmt = $conn->prepare("SELECT COUNT(*) AS followers FROM follows WHERE following_id = ?");
    $stmt->bind_param('i', $profileUser['id']);
    $stmt->execute();
    $followersCount = $stmt->get_result()->fetch_assoc()['followers'] ?? 0;
    $stmt->close();

    // 2. Проверяем, подписан ли текущий пользователь на этот профиль
    if ($currentUserId && $currentUserId !== intval($profileUser['id'])) {
        $stmt = $conn->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1");
        $stmt->bind_param("ii", $currentUserId, $profileUser['id']);
        $stmt->execute();
        $isFollowing = !!$stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

// Глобальные переменные пригодятся для рендера отдельных постов (аватар/ник)
$GLOBALS['profile_username'] = $profileUser['username'];
$GLOBALS['profile_avatar'] = $profileUser['avatar'] ?: 'elements/img/default-avatar.png';

// Подсчёт подписчиков
$stmt = $conn->prepare("SELECT COUNT(*) AS followers FROM follows WHERE following_id = ?"); 
$stmt->bind_param('i', $profileUser['id']); 
$stmt->execute(); 
$followers = $stmt->get_result()->fetch_assoc()['followers'] ?? 0;
$stmt->close(); // Закрываем стейтмент

// Подсчёт подписок (на кого подписан сам профиль)
$stmt = $conn->prepare("SELECT COUNT(*) AS following FROM follows WHERE follower_id = ?");
$stmt->bind_param('i', $profileUser['id']);
$stmt->execute();
$following = $stmt->get_result()->fetch_assoc()['following'] ?? 0;
$stmt->close();

// Проверка статуса подписки текущего пользователя ($currentUserId) на просматриваемый профиль
$isFollowing = false;
if ($currentUserId && $currentUserId !== intval($profileUser['id'])) {
    $stmt = $conn->prepare("SELECT 1 FROM follows WHERE follower_id = ? AND following_id = ? LIMIT 1");
    $stmt->bind_param("ii", $currentUserId, $profileUser['id']);
    $stmt->execute();
    $isFollowing = $stmt->get_result()->num_rows > 0;
    $stmt->close();
}

$stmt = $conn->prepare("SELECT COUNT(*) AS following FROM follows WHERE follower_id = ?");
$stmt->bind_param('i', $profileUser['id']);
$stmt->execute();
$following = $stmt->get_result()->fetch_assoc()['following'] ?? 0;
$stmt->close();

// Кол-во постов
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM posts WHERE user_id = ?");
$stmt->bind_param('i', $profileUser['id']);
$stmt->execute();
$postCount = $stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
$stmt->close();

// НОВОЕ: Проверка верификации профиля
$isProfileVerified = is_user_verified($profileUser['id'], $conn);

/* ---------------------------
    ВЫВОД СТРАНИЦЫ (HTML часть)
    --------------------------- */

include __DIR__ . '/elements/php/header.php';
?>

    <div class="flex w-full max-w-7xl mx-auto min-h-screen">
        <?php require_once __DIR__ . '/elements/php/sidebar-left.php'; ?>

        <main class="w-full md:w-2/3 lg:w-1/2 border-x border-gray-800 min-h-screen bg-gray-900">
            
            <header class="sticky top-0 bg-gray-900 bg-opacity-80 backdrop-blur-sm p-4 border-b border-gray-800 z-20">
                <h1 class="text-xl font-bold">Профиль</h1>
            </header>

            <div class="border-b border-gray-800">
                <?php
                // Определяем URL баннера. Если поле 'banner' не пусто, используем его, иначе — заглушку.
                $bannerPath = !empty($profileUser['banner']) ? htmlspecialchars($profileUser['banner']) : null;
                if ($bannerPath && $bannerPath[0] !== '/') {
                        $bannerPath = '/' . $bannerPath; // Добавляем слэш, если это путь относительно корня
                }
                $bannerUrl = $bannerPath ?: 'https://placehold.co/1200x400/1d9bf0/ffffff?text=Profile+Banner';
                ?>
                <div class="relative w-full h-48 bg-cover bg-center" 
                        style="background-image: url('<?= $bannerUrl ?>');">
                </div>

                <div class="p-6 -mt-16 z-10 relative">
                    <div class="flex items-end space-x-4">
                        <a href="profile.php?id=${post.user_id}">
                        <img src="<?= htmlspecialchars($GLOBALS['profile_avatar']) ?>" 
                              alt="Аватар пользователя" 
                              class="w-24 h-24 rounded-full border-4 border-gray-900 object-cover">
                                <div class="flex-grow">
                                    <h2 class="text-2xl font-bold">
                                        <?= htmlspecialchars($profileUser['username']) ?>
                                        <?php if ($isProfileVerified): ?>
                                            <span title="Верифицированный аккаунт" class="inline-flex items-center justify-center h-5 w-5 bg-blue-500 text-white text-sm font-bold rounded-full ml-2 flex-shrink-0">✓</span>
                                        <?php endif; ?>
                                    </h2>
                                    <p class="text-gray-400"><strong>ID:</strong> <span><?= intval($profileUser['id']) ?></span></p>
                                            <?php 
        // Кнопка показывается только если пользователь авторизован и это не его собственный профиль
        if ($currentUserId !== null && $currentUserId !== intval($profileUser['id'])): 
        ?>
            <div class="flex space-x-3">
                <a href="messages.php?user=<?= intval($profileUser['id']) ?>"
                   class="px-4 py-2 rounded-full font-bold transition-colors duration-200 bg-green-500 hover:bg-green-600 text-white inline-block">
                    ✉️ Написать
                </a>
                
                </div>
        <?php 
        // ...
        endif; 
        ?>
                                </div>
                            </a>
                    </div>
                </div>
                
                <div class="p-6 pt-0">
                    <p class="mt-4 text-gray-200">
                        <?= nl2br(htmlspecialchars($profileUser['bio'] ?? '')) ?>
                    </p>

                    <div class="flex items-center justify-between mt-4">
                
                        <?php 
                        // Кнопка показывается только если пользователь авторизован и это не его собственный профиль
                        if ($currentUserId !== null && $currentUserId !== intval($profileUser['id'])): 
                        ?>
                            <button id="follow-btn"
                                    data-user-id="<?= intval($profileUser['id']) ?>"
                                    class="
                                        px-4 py-2 rounded-full font-bold transition-colors duration-200
                                        <?= $isFollowing ? 'bg-gray-700 hover:bg-gray-600 text-white' : 'bg-blue-500 hover:bg-blue-600 text-white' ?>
                                    ">
                                <?= $isFollowing ? 'Отписаться' : 'Подписаться' ?>
                            </button>
                        <?php 
                        // Если это собственный профиль, можно показать кнопку редактирования
                        elseif ($currentUserId !== null && $currentUserId === intval($profileUser['id'])): 
                        ?>
                            <a href="settings.php" 
                               class="px-4 py-2 rounded-full font-bold transition-colors duration-200 bg-gray-700 hover:bg-gray-600 text-white inline-block">
                                Настройки
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="flex space-x-4 mt-2 text-gray-400 text-sm">
                        <div>
                            <span class="font-bold text-white" id="followers-count"><?= $followers ?></span>
                            <span class="ml-1">Подписчиков</span>
                        </div>
                        <div>
                            <span class="font-bold text-white"><?= $following ?></span>
                            <span class="ml-1">Подписок</span>
                        </div>
                    </div>

                    <div class="mt-4 text-gray-400 text-sm">
                        <p><strong>Ник:</strong> <span><?= htmlspecialchars($profileUser['username']) ?></span></p>
                        <p><strong>Дата регистрации:</strong> <span><?= htmlspecialchars($profileUser['created_at']) ?></span></p>
                    </div>
                </div>
            </div>

            <!-- Если авторизован — показать форму создания поста -->
            <?php if ($currentUserId): ?>
            <div class="p-4 border-b border-gray-800">
                <form id="create-post-form" class="space-y-2" enctype="multipart/form-data" action="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <textarea name="text" placeholder="Что происходит?" rows="3" class="w-full bg-gray-800 text-white text-lg resize-none outline-none py-2 px-3 rounded"></textarea>
                    <div class="flex items-center justify-between">
                        <div>
                            <label for="create-media-input" class="cursor-pointer text-gray-400 hover:text-blue-400 transition-colors duration-200">
                                <span class="text-2xl">📎</span>
                            </label>
                            <input type="file" id="create-media-input" name="media[]" accept="image/*,video/*,audio/*" multiple class="hidden">
                            <div class="text-sm text-gray-400"></div>
                        </div>
                        <div class="flex items-center space-x-2">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-6 rounded-full">Опубликовать</button>
                        </div>
                    </div>
                    <div id="create-post-preview" class="mt-2 grid gap-2 grid-cols-2"></div>
                </form>
            </div>
            <?php endif; ?>

            <div id="user-posts" class="p-4">
                <div class="text-center text-gray-400" id="feed-status">Загрузка постов...</div>
                <div id="posts-container" class="mt-4"></div>
                <div id="load-more-wrap" class="mt-4 text-center">
                    <button id="load-more-btn" class="px-4 py-2 bg-gray-800 rounded border border-gray-700 hidden">Загрузить ещё</button>
                    <p id="no-more-posts" class="text-gray-500 hidden">Больше постов нет.</p>
                </div>
            </div>

        </main>

        <?php require_once __DIR__ . '/elements/php/sidebar-right.php'; ?>
    </div>
    <?php include __DIR__ . '/elements/php/modal.php'; ?>
</div>

<?php include __DIR__ . '/elements/php/footer.php'; ?>

<!-- Стили для медиа/превью (минимум) -->
<style>
.media-wrapper {
    position: relative;
    width: 100%;
    padding-top: 100%;
    overflow: hidden;
    background-color: #111827;
    border-radius: 0.5rem;
}
.media-wrapper img, .media-wrapper video, .media-wrapper audio, .media-wrapper .file-link {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.preview-thumb { position: relative; border-radius: 6px; overflow: hidden; background:#0f1720; padding:6px; }
.preview-remove { position: absolute; right:4px; top:4px; background: rgba(0,0,0,0.6); border:none; color:white; padding:2px 6px; border-radius:4px; cursor:pointer;}
</style>

<script>
/* ====== КОНФИГУРАЦИЯ / СТЕЙТ ====== */
const profileUserId = <?= intval($profileUser['id']) ?>;
let offset = 0;
const limit = 10;
let loading = false;
let hasMore = true;

const postsContainer = document.getElementById('posts-container');
const feedStatus = document.getElementById('feed-status');
const loadMoreBtn = document.getElementById('load-more-btn');
const createForm = document.getElementById('create-post-form');
const currentUserId = <?= $currentUserId ? intval($currentUserId) : 'null' ?>;

/* ====== УТИЛИТЫ ====== */
function escapeHtml(unsafe) {
    return unsafe
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}
function linkify(text) {
    if(!text) return '';
    text = escapeHtml(text);
    const urlRegex = /https?:\/\/[^\s<]+/g;
    return text.replace(urlRegex, function(url){
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-blue-400 underline">${url}</a>`;
    });
}

/* ====== ЗАГРУЗКА ПОСТОВ (вызов серверного блока loadPosts внутри этого же файла) ====== */
async function loadPosts() {
    if (loading || !hasMore) return;
    loading = true;
    feedStatus.textContent = 'Загрузка...';
    loadMoreBtn.disabled = true;

    const form = new FormData();
    form.append('ajax', 'loadPosts');
    form.append('profile_user_id', profileUserId);
    form.append('offset', offset);
    form.append('limit', limit);

    try {
        const resp = await fetch(window.location.pathname, {method:'POST', body: form, credentials: 'same-origin'});
        const data = await resp.json();
        if (data.error) { feedStatus.textContent = 'Ошибка загрузки'; console.error(data.error); loading=false; loadMoreBtn.disabled=false; return; }

        postsContainer.insertAdjacentHTML('beforeend', data.postsHtml);
        hasMore = !!data.hasMore;
        offset += limit;
        feedStatus.textContent = hasMore ? '' : 'Больше постов нет';
        loadMoreBtn.style.display = hasMore ? 'inline-block' : 'none';

        attachPostHandlers();
    } catch (e) {
        console.error(e);
        feedStatus.textContent = 'Ошибка сети';
    }
    loading = false;
    loadMoreBtn.disabled = false;
}
loadMoreBtn.addEventListener('click', loadPosts);
window.addEventListener('scroll', () => {
    if (!hasMore || loading) return;
    const nearBottom = (window.innerHeight + window.scrollY) >= (document.body.offsetHeight - 300);
    if (nearBottom) loadPosts();
});

/* ====== ПРЕДПРОСМОТР ДЛЯ CREATE FORM (простой список имен файлов) ====== */
document.getElementById('create-media-input')?.addEventListener('change', function(e){
    const container = document.getElementById('create-post-preview');
    container.innerHTML = '';
    const files = Array.from(this.files).slice(0,5);
    files.forEach((f, idx) => {
        const div = document.createElement('div');
        div.className = 'preview-thumb';
        div.style.minHeight = '56px';
        const btn = document.createElement('button');
        btn.className = 'preview-remove';
        btn.type = 'button';
        btn.textContent = '×';
        btn.addEventListener('click', () => {
            // удалить файл из input
            const input = document.getElementById('create-media-input');
            const dt = new DataTransfer();
            Array.from(input.files).forEach((file, i) => { if(i !== idx) dt.items.add(file); });
            input.files = dt.files;
            div.remove();
        });
        div.appendChild(btn);

        if (f.type.startsWith('image/')) {
            const reader = new FileReader();
            const img = document.createElement('img');
            img.style.maxHeight = '120px';
            img.style.width = '100%';
            reader.onload = (ev) => img.src = ev.target.result;
            reader.readAsDataURL(f);
            div.appendChild(img);
        } else {
            const p = document.createElement('div');
            p.style.padding = '8px';
            p.textContent = f.name;
            div.appendChild(p);
        }
        container.appendChild(div);
    });
});

/* ====== CREATE POST SUBMIT (AJAX) ====== */
createForm?.addEventListener('submit', async function(e){
    e.preventDefault();
    if (!currentUserId) { alert('Войдите, чтобы публиковать'); return; }

    const input = document.getElementById('create-media-input');
    const files = input ? input.files : [];
    if (files.length > 5) { alert('Максимум 5 файлов'); return; }

    const fd = new FormData();
    fd.append('action', 'addPost');
    fd.append('text', this.querySelector('textarea[name=text]').value || '');
    // post в профиль — parent_id null, original_post_id null
    for (let i=0;i<files.length;i++) fd.append('media[]', files[i]);

    try {
        const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials: 'same-origin'});
        const data = await res.json();
        if (data.success) {
            if (data.postHtml) {
                postsContainer.insertAdjacentHTML('afterbegin', data.postHtml);
                attachPostHandlers();
            } else {
                // если нет HTML, просто перезагрузим ленту
                offset = 0; postsContainer.innerHTML = ''; hasMore = true; loadPosts();
            }
            this.reset();
            document.getElementById('create-post-preview').innerHTML = '';
        } else {
            alert(data.error || 'Ошибка при создании поста');
        }
    } catch (err) {
        console.error(err);
        alert('Ошибка сети');
    }
});

/* ====== Привязка обработчиков к динамическим кнопкам ====== */
function attachPostHandlers() {
    // лайк
    document.querySelectorAll('.like-btn').forEach(btn => {
        if (btn.dataset._bound) return; btn.dataset._bound = '1';
        btn.addEventListener('click', async () => {
            if (!currentUserId) { alert('Войдите, чтобы лайкать'); return; }
            const postId = btn.dataset.id || btn.getAttribute('data-id');
            const fd = new FormData();
            fd.append('action','toggleLike');
            fd.append('post_id', postId);
            try {
                const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                if (d && d.success) {
                    const likesEl = btn.querySelector('.likes-count');
                    if (likesEl) likesEl.textContent = d.like_count;
                    const sibling = btn.parentElement.querySelector('.dislike-btn .dislikes-count');
                    if (sibling && typeof d.dislike_count !== 'undefined') sibling.textContent = d.dislike_count;
                }
            } catch (e) { console.error(e); }
        });
    });

    // дизлайк
    document.querySelectorAll('.dislike-btn').forEach(btn => {
        if (btn.dataset._bound) return; btn.dataset._bound = '1';
        btn.addEventListener('click', async () => {
            if (!currentUserId) { alert('Войдите, чтобы дизлайкать'); return; }
            const postId = btn.dataset.id || btn.getAttribute('data-id');
            const fd = new FormData();
            fd.append('action','toggleDislike');
            fd.append('post_id', postId);
            try {
                const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                if (d && d.success) {
                    const dislikesEl = btn.querySelector('.dislikes-count');
                    if (dislikesEl) dislikesEl.textContent = d.dislike_count;
                    const sibling = btn.parentElement.querySelector('.like-btn .likes-count');
                    if (sibling && typeof d.like_count !== 'undefined') sibling.textContent = d.like_count;
                }
            } catch (e) { console.error(e); }
        });
    });

    // меню (троеточие)
    document.querySelectorAll('.menu-btn').forEach(btn => {
        if (btn.dataset._boundMenu) return; btn.dataset._boundMenu = '1';
        btn.addEventListener('click', (ev) => {
            // закрываем другие
            document.querySelectorAll('.menu-content').forEach(m => m.classList.add('hidden'));
            const menu = btn.parentElement.querySelector('.menu-content');
            if (menu) menu.classList.toggle('hidden');
            ev.stopPropagation();
        });
    });
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.menu-content') && !e.target.closest('.menu-btn')) {
            document.querySelectorAll('.menu-content').forEach(m => m.classList.add('hidden'));
        }
    });

    // удаление поста
    document.querySelectorAll('.delete-post-btn').forEach(btn => {
        if (btn.dataset._boundDel) return; btn.dataset._boundDel = '1';
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            if (!confirm('Удалить пост?')) return;
            const postId = btn.dataset.id;
            const fd = new FormData();
            fd.append('action','deletePost');
            fd.append('post_id', postId);
            try {
                const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                if (d && d.success) {
                    const el = document.querySelector(`.post-item[data-post-id="${postId}"]`);
                    el && el.remove();
                } else {
                    alert(d.message || 'Не удалось удалить');
                }
            } catch (err) { console.error(err); }
        });
    });

    // жалобы
    document.querySelectorAll('.report-post-btn').forEach(btn => {
        if (btn.dataset._boundRep) return; btn.dataset._boundRep = '1';
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            const postId = btn.dataset.id;
            const fd = new FormData();
            fd.append('action','reportPost');
            fd.append('post_id', postId);
            try {
                const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                alert(d.message || (d.success ? 'Жалоба отправлена' : 'Ошибка при отправке жалобы'));
            } catch (err) { console.error(err); alert('Ошибка сети'); }
        });
    });

    // reply toggle + load replies when opened
    document.querySelectorAll('.reply-btn').forEach(btn => {
        if (btn.dataset._boundReply) return; btn.dataset._boundReply = '1';
        btn.addEventListener('click', async () => {
            const id = btn.dataset.id;
            const box = document.getElementById('reply-'+id);
            if (!box) return;
            box.classList.toggle('hidden');
            if (!box.classList.contains('hidden')) {
                const childContainer = document.getElementById('child-'+id);
                if (childContainer && childContainer.children.length === 0) {
                    // load replies from server
                    const fd = new FormData();
                    fd.append('action','loadReplies');
                    fd.append('post_id', id);
                    try {
                        const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials: 'same-origin'});
                        const d = await res.json();
                        if (d && d.success) {
                            childContainer.innerHTML = d.postsHtml || '';
                            attachPostHandlers(); // bind handlers for newly added replies
                        }
                    } catch (err) { console.error(err); }
                }
            }
        });
    });

    // reply form submit (delegated)
    document.querySelectorAll('.reply-form').forEach(form => {
        if (form.dataset._boundForm) return; form.dataset._boundForm = '1';
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            if (!currentUserId) { alert('Войдите, чтобы отвечать'); return; }
            const parent = form.dataset.parent;
            const text = form.querySelector('input[name=text]')?.value || '';
            const fileInput = form.querySelector('input[type=file]');
            const files = fileInput ? fileInput.files : [];

            if (files.length > 5) { alert('Максимум 5 файлов'); return; }

            const fd = new FormData();
            fd.append('action','addPost');
            fd.append('text', text);
            fd.append('parent_id', parent);
            for (let i=0;i<files.length;i++) fd.append('media[]', files[i]);

            try {
                const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                if (d && d.success) {
                    const container = document.getElementById('child-'+parent);
                    if (container) {
                        container.insertAdjacentHTML('beforeend', d.postHtml || '');
                        attachPostHandlers();
                    }
                    // очистить форму
                    form.reset();
                    form.querySelector('.reply-preview') && (form.querySelector('.reply-preview').innerHTML = '');
                } else {
                    alert(d.error || 'Ошибка при отправке ответа');
                }
            } catch (err) { console.error(err); alert('Ошибка сети'); }
        });
    });

    // simple repost handler: отправляем addPost с original_post_id
    document.querySelectorAll('.repost-btn').forEach(btn => {
        if (btn.dataset._boundRepost) return; btn.dataset._boundRepost = '1';
        btn.addEventListener('click', async () => {
            if (!currentUserId) { alert('Войдите, чтобы репостить'); return; }
            const originalId = btn.dataset.id;
            if (!confirm('Сделать репост?')) return;
            const fd = new FormData();
            fd.append('action','addPost');
            fd.append('original_post_id', originalId);
            try {
                const res = await fetch(window.location.pathname, {method:'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                if (d && d.success) {
                    postsContainer.insertAdjacentHTML('afterbegin', d.postHtml || '');
                    attachPostHandlers();
                    window.scrollTo(0,0);
                    // сбросим offset/режимы
                    offset = 0; hasMore = true;
                } else alert(d.error || 'Ошибка при репосте');
            } catch (err) { console.error(err); }
        });
    });
}

    const followBtn = document.getElementById('follow-btn');
    const followersCountEl = document.getElementById('followers-count');

    if (followBtn) {
        followBtn.addEventListener('click', async function(e) {
            e.preventDefault();
            if (!currentUserId) { alert('Войдите, чтобы подписаться'); return; }

            const targetUserId = this.dataset.userId;
            
            const fd = new FormData();
            fd.append('action', 'toggleFollow');
            fd.append('target_user_id', targetUserId);

            // Сохраняем текущее состояние для восстановления в случае ошибки
            const originalText = this.textContent.trim();
            const originalClasses = this.className;
            
            this.disabled = true; 
            this.textContent = '...'; 

            try {
                const res = await fetch(window.location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                
                if (d && d.success) {
                    // Обновляем текст и стили
                    this.textContent = d.newStatus ? 'Отписаться' : 'Подписаться';
                    if (d.newStatus) {
                        // Новый статус: Подписан (кнопка становится серой "Отписаться")
                        this.classList.remove('bg-blue-500', 'hover:bg-blue-600');
                        this.classList.add('bg-gray-700', 'hover:bg-gray-600');
                    } else {
                        // Новый статус: Не подписан (кнопка становится синей "Подписаться")
                        this.classList.remove('bg-gray-700', 'hover:bg-gray-600');
                        this.classList.add('bg-blue-500', 'hover:bg-blue-600');
                    }
                    
                    // Обновляем счётчик подписчиков
                    if (followersCountEl && typeof d.followersCount !== 'undefined') {
                        // Используем toLocaleString() для форматирования числа
                        followersCountEl.textContent = d.followersCount.toLocaleString('ru-RU');
                    }
                } else {
                    // В случае ошибки возвращаем исходный текст и классы
                    this.textContent = originalText;
                    this.className = originalClasses;
                    alert(d.error === 'cannot_follow_self' ? 'Нельзя подписаться на самого себя' : (d.error || 'Ошибка при подписке/отписке'));
                }

            } catch (err) {
                console.error(err);
                this.textContent = originalText; // В случае ошибки сети
                this.className = originalClasses;
                alert('Ошибка сети');
            } finally {
                this.disabled = false;
            }
        });
    }

/* Инициализация начальной подгрузки */
loadPosts();
</script>
