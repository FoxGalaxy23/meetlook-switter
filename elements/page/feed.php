<?php
// elements/pages/feed.php

// безопасно стартуем сессию, если ещё не стартована
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// корректный путь к db.php: feed.php находится в elements/pages,
// поэтому поднимаемся на 2 уровня к корню проекта
require_once __DIR__ . '/../../db.php';

// --- AJAX обработка ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["ajax"])) {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST["action"] ?? "";

    if ($action === "addPost") {
        $text = trim($_POST["text"] ?? "");
        $parent_id = !empty($_POST["parent_id"]) ? intval($_POST["parent_id"]) : null;
        $original_post_id = !empty($_POST["original_post_id"]) ? intval($_POST["original_post_id"]) : null;

        if ($text !== "") {
            $stmt = $conn->prepare("INSERT INTO posts (user_id, text, parent_id, original_post_id) VALUES (?, ?, ?, ?)");
            // Для bind_param нужно всегда передавать переменные; null также передаём как NULL.
            // Приведём к типу: i s i i — но если parent/original null, можно передать NULL через bind_param,
            // mysqli корректно установит NULL, если переменная === null при execute.
            $stmt->bind_param("isii", $_SESSION["user_id"], $text, $parent_id, $original_post_id);
            $stmt->execute();
            $post_id = $stmt->insert_id;
            $stmt->close();

            $stmt = $conn->prepare("SELECT p.*, u.username, (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id) as like_count 
                FROM posts p JOIN users u ON p.user_id=u.id WHERE p.id=?");
            $stmt->bind_param("i", $post_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            echo json_encode(["success" => true, "post" => $result]);
            exit;
        } else {
            echo json_encode(["success" => false, "error" => "empty_text"]);
            exit;
        }
    }

    if ($action === "like") {
        $post_id = intval($_POST["post_id"] ?? 0);
        $user_id = $_SESSION["user_id"];
        $stmt = $conn->prepare("INSERT IGNORE INTO likes (post_id, user_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $post_id, $user_id);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) as like_count FROM likes WHERE post_id=?");
        $stmt->bind_param("i", $post_id);
        $stmt->execute();
        $like_count = $stmt->get_result()->fetch_assoc()["like_count"];
        echo json_encode(["success" => true, "like_count" => $like_count]);
        exit;
    }

    if ($action === "repost") {
        $original_post_id = intval($_POST["post_id"] ?? 0);
        $stmt = $conn->prepare("INSERT INTO posts (user_id, text, original_post_id) SELECT ?, text, ? FROM posts WHERE id=?");
        $stmt->bind_param("iii", $_SESSION["user_id"], $original_post_id, $original_post_id);
        $stmt->execute();
        $stmt->close();
        echo json_encode(["success" => true]);
        exit;
    }

    if ($action === "loadPosts") {
        $offset = intval($_POST["offset"] ?? 0);
        $limit = 5;

        $stmt = $conn->prepare("SELECT p.*, u.username, (SELECT COUNT(*) FROM likes l WHERE l.post_id=p.id) as like_count
            FROM posts p JOIN users u ON p.user_id=u.id
            WHERE p.parent_id IS NULL
            ORDER BY p.id DESC
            LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        $posts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        echo json_encode($posts);
        exit;
    }

    // действие не распознано
    echo json_encode(["success" => false, "error" => "unknown_action"]);
    exit;
}
?>

<!-- НИЧЕГО HTML-шаблонного до этого места при AJAX -->
<!-- Ниже — фрагмент HTML ленты (встраивается в index.php внутри flex-контейнера) -->
<main class="w-full md:w-2/3 lg:w-1/2 border-x border-gray-800 min-h-screen">
    <header class="sticky top-0 bg-gray-900 bg-opacity-80 backdrop-blur-sm p-4 border-b border-gray-800 flex justify-between">
        <h1 class="text-xl font-bold">Главная</h1>
        <div>Привет, <?= htmlspecialchars($_SESSION["username"] ?? 'Гость') ?> | <a href="logout.php" class="text-blue-500">Выйти</a></div>
    </header>

    <!-- Форма нового поста -->
    <div class="p-4 border-b border-gray-800">
        <form id="post-form" class="space-y-2">
            <textarea name="text" placeholder="Что происходит?" required class="w-full bg-gray-800 text-lg resize-none outline-none py-2 px-3 rounded"></textarea>
            <div class="flex justify-end">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-6 rounded-full">Опубликовать</button>
            </div>
        </form>
    </div>

    <!-- Лента постов -->
    <div id="posts-container"></div>
    <div id="loading" class="text-center text-gray-400 p-4">Загрузка...</div>
</main>

<script>
let offset = 0;
const limit = 5;
let loading = false;

function renderPost(post) {
    let html = `<div class="p-4 border-b border-gray-800">
        <div class="flex justify-between">
            <span class="font-bold">${post.username}</span>
            <span class="text-gray-500 text-sm">${post.created_at || ''}</span>
        </div>
        ${post.original_post_id ? '<div class="text-gray-400 text-sm">Репост</div>' : ''}
        <p class="mt-1">${post.text}</p>
        <div class="flex space-x-4 text-gray-400 text-sm mt-2">
            <button class="like-btn" data-id="${post.id}">❤️ ${post.like_count}</button>
            <button class="reply-btn" data-id="${post.id}">💬 Ответить</button>
            <button class="repost-btn" data-id="${post.id}">🔁 Репост</button>
        </div>
        <div id="reply-${post.id}" class="hidden mt-2">
            <form class="reply-form flex space-x-2 mb-2" data-parent="${post.id}">
                <input type="text" name="text" placeholder="Комментарий..." required class="flex-grow px-2 py-1 bg-gray-800 rounded text-white">
                <button type="submit" class="px-3 py-1 bg-blue-500 rounded text-white">Отправить</button>
            </form>
            <div class="child-posts" id="child-${post.id}"></div>
        </div>
    </div>`;
    return html;
}

// Заменили целевой URL — теперь обращаемся напрямую к feed.php (это важно, чтобы не попадал header.php)
const ajaxUrl = 'elements/pages/feed.php';

function loadPosts(reset = false){
    if(loading) return;
    loading = true;
    if(reset) offset = 0;

    $('#loading').show().text('Загрузка...');

    $.post(ajaxUrl, {ajax:1, action:'loadPosts', offset:offset}, function(posts){
        // если сервер вернул строку — попытка парсинга; если уже JSON — jQuery распарсит
        try {
            posts = (typeof posts === 'string') ? JSON.parse(posts) : posts;
        } catch(e) {
            console.error('Invalid JSON', posts);
            $('#loading').text('Ошибка загрузки');
            loading = false;
            return;
        }

        if(reset) $('#posts-container').empty();

        if(!posts || posts.length === 0){
            $('#loading').text('Больше постов нет');
            loading = false;
            return;
        }

        posts.forEach(p => $('#posts-container').append(renderPost(p)));
        offset += posts.length;
        loading = false;
        $('#loading').hide();
    });
}

// Отправка нового поста
$('#post-form').submit(function(e){
    e.preventDefault();
    let text = $(this).find('textarea[name=text]').val();
    $.post(ajaxUrl, {ajax:1, action:'addPost', text:text}, function(res){
        try { res = (typeof res === 'string') ? JSON.parse(res) : res; } catch(e){ console.error(res); return; }
        if(res.success){
            $('#posts-container').prepend(renderPost(res.post));
            $('#post-form textarea').val('');
            offset++;
        }
    });
});

// Лайки
$(document).on('click', '.like-btn', function(){
    let id = $(this).data('id');
    let btn = $(this);
    $.post(ajaxUrl, {ajax:1, action:'like', post_id:id}, function(res){
        try { res = (typeof res === 'string') ? JSON.parse(res) : res; } catch(e){ console.error(res); return; }
        if(res.success) btn.text('❤️ ' + res.like_count);
    });
});

// Репосты
$(document).on('click', '.repost-btn', function(){
    let id = $(this).data('id');
    $.post(ajaxUrl, {ajax:1, action:'repost', post_id:id}, function(res){
        try { res = (typeof res === 'string') ? JSON.parse(res) : res; } catch(e){ console.error(res); return; }
        if(res.success) loadPosts(true);
    });
});

// Ответы/Комментарии
$(document).on('click', '.reply-btn', function(){
    let id = $(this).data('id');
    $('#reply-'+id).toggleClass('hidden');
});

$(document).on('submit', '.reply-form', function(e){
    e.preventDefault();
    let parent = $(this).data('parent');
    let text = $(this).find('input[name=text]').val();
    $.post(ajaxUrl, {ajax:1, action:'addPost', text:text, parent_id:parent}, function(res){
        try { res = (typeof res === 'string') ? JSON.parse(res) : res; } catch(e){ console.error(res); return; }
        if(res.success){
            $('#child-'+parent).append(renderPost(res.post));
            $('form[data-parent="'+parent+'"] input[name=text]').val('');
        }
    });
});

// Бесконечная прокрутка
$(window).scroll(function(){
    if($(window).scrollTop() + $(window).height() >= $(document).height() - 100){
        loadPosts();
    }
});

// Инициализация
loadPosts();
</script>
