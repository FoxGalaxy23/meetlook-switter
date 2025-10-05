// main.js

// Импортируем нужные функции из других модулей
import { makePreviewElement } from './render.js';
import { setFilesOnInput } from './utils.js';
import { loadPosts, loadReplies, handleNewPostSuccess, handleRepostSuccess } from './feed.js';

// --- 1. Инициализация и бесконечный скролл ---

$(document).ready(function(){
    // Проверяем, на какой странице мы находимся:
    
    // Если есть блок постов профиля (profile.php) И установлен ID профиля, загружаем его посты.
    if ($('#user-posts').length && window.PROFILE_USER_ID) {
        // Режим 'user:ID' для загрузки постов конкретного пользователя
        loadPosts(true, `user:${window.PROFILE_USER_ID}`); 
    } 
    // Если есть контейнер общей ленты (feed.php или index.php), загружаем общую ленту.
    else if ($('#posts-container').length) {
        loadPosts(); 
    }
});

$(window).scroll(function() {
    // Условие для бесконечной прокрутки (загружаем, когда до конца страницы остается 120px)
    if ($(window).scrollTop() + $(window).height() >= $(document).height() - 120) {
        
        // Если мы на странице профиля
        if ($('#user-posts').length && window.PROFILE_USER_ID) {
            loadPosts(false, `user:${window.PROFILE_USER_ID}`);
        } 
        // Иначе — на общей ленте
        else if ($('#posts-container').length) {
            loadPosts();
        }
    }
});


// --- 2. Обработчики для превью прикрепления файлов (основная форма) ---

// Обработчик изменения файлов для основной формы поста
$('#media-input').on('change', function(){
    const input = this;
    const container = $('#post-preview');
    container.empty();

    let files = Array.from(input.files);
    if(files.length > 5) {
        alert('Можно прикрепить не более 5 файлов');
        files = files.slice(0,5);
        setFilesOnInput(input, files);
    }

    files.forEach((f, idx) => {
        // makePreviewElement должен быть импортирован из render.js или определен
        container.append(makePreviewElement(f, idx));
    });
});

// Делегированный обработчик для удаления превью
$(document).on('click', '.preview-remove', function(){
    const indexToRemove = $(this).data('index');
    const input = $('#media-input').get(0);
    
    let files = Array.from(input.files);
    files.splice(indexToRemove, 1);

    setFilesOnInput(input, files);

    // Перерисовываем превью
    const container = $('#post-preview');
    container.empty();
    files.forEach((f, idx) => {
        container.append(makePreviewElement(f, idx));
    });
});


// --- 3. Обработчик отправки формы поста ---
$('#post-form').on('submit', function(e) {
    e.preventDefault();

    const form = $(this);
    const formData = new FormData(this);
    formData.append('ajax', 1);
    formData.append('action', 'addPost');

    $.ajax({
        url: 'api.php', // Все AJAX-запросы направляем на api.php
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        success: function(res) {
            let parsedRes;
            try {
                parsedRes = JSON.parse(res);
            } catch (e) {
                alert('Ошибка: Неверный ответ от сервера');
                return;
            }

            if (parsedRes.success) {
                form.find('textarea').val(''); 
                form.find('input[type=file]').val(''); 
                $('#post-preview').empty(); 
                
                // Добавляем новый пост в ленту. Логика в feed.js сама поймет, куда добавить.
                handleNewPostSuccess(parsedRes.post);
            } else {
                alert('Ошибка: ' + (parsedRes.message || 'Неизвестная ошибка'));
            }
        },
        error: function() {
            alert('Ошибка при отправке запроса.');
        }
    });
});


// --- 4. Обработчики для действий с постами (лайки/дизлайки, репосты, ответы) ---

// Обработчик для лайков и дизлайков
$(document).on('click', '.like-btn, .dislike-btn', function() {
    const btn = $(this);
    const postId = btn.data('id');
    const isLike = btn.hasClass('like-btn');
    const action = isLike ? 'likePost' : 'dislikePost';

    $.post('api.php', { ajax: 1, action: action, post_id: postId }, function(res) {
        let parsedRes = JSON.parse(res);
        if (parsedRes.success) {
            const postContainer = btn.closest('.post');
            postContainer.find('.like-btn').text(`👍 ${parsedRes.like_count}`);
            postContainer.find('.dislike-btn').text(`👎 ${parsedRes.dislike_count}`);
        } else {
            console.error('Ошибка действия:', parsedRes.message);
        }
    }).fail(function() {
         console.error('Ошибка при выполнении AJAX-запроса');
    });
});

// Обработчик для кнопки 'Ответить'
$(document).on('click', '.reply-btn', function() {
    const postId = $(this).data('id');
    const replySection = $(`#reply-${postId}`);
    replySection.toggleClass('hidden');
    
    // Если открыли, загружаем ответы, только если они еще не загружены
    if (!replySection.hasClass('hidden') && replySection.is(':empty')) {
        loadReplies(postId); // loadReplies должен быть импортирован
    }
});

// Обработчик отправки формы ответа
$(document).on('submit', '.reply-form', function(e) {
    e.preventDefault();

    const form = $(this);
    const parentId = form.data('parent');
    const formData = new FormData(this);
    formData.append('ajax', 1);
    formData.append('action', 'addPost');
    formData.append('parent_id', parentId);

    $.ajax({
        url: 'api.php',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        success: function(res) {
            let parsedRes;
            try {
                parsedRes = JSON.parse(res);
            } catch (e) {
                alert('Ошибка: Неверный ответ от сервера');
                return;
            }

            if (parsedRes.success) {
                form.find('input[name=text]').val(''); 
                form.find('input[type=file]').val(''); 
                form.find('.reply-preview').empty(); 

                // Перезагружаем ответы
                loadReplies(parentId);

                // Закрываем форму ответа
                $(`#reply-${parentId}`).addClass('hidden');
            } else {
                alert('Ошибка: ' + (parsedRes.message || 'Неизвестная ошибка'));
            }
        },
        error: function() {
            alert('Ошибка при отправке запроса.');
        }
    });
});

// Делегированный обработчик для превью в ответах
$(document).on('change', '.reply-media-input', function(){
    const input = this;
    const container = $(this).closest('form').find('.reply-preview');
    container.empty();

    let files = Array.from(input.files);
    if(files.length > 5) {
        alert('Можно прикрепить не более 5 файлов');
        files = files.slice(0,5);
        setFilesOnInput(input, files);
    }

    files.forEach((f, idx) => {
        container.append(makePreviewElement(f, idx, 'reply'));
    });
});

// Обработчик для кнопки "Репост"
$(document).on('click', '.repost-btn', function() {
    const postId = $(this).data('id');
    const originalPostElement = $(this).closest('.post');
    
    // Извлекаем данные для модального окна
    const postData = {
        id: postId,
        username: originalPostElement.find('.post-username').text(),
        nickname: originalPostElement.find('.post-nickname').text(),
        text: originalPostElement.find('.post-text').html(), 
        avatar: originalPostElement.find('.post-avatar').attr('src')
    };

    const modalContent = `
        <h2 class="text-xl font-bold mb-4">Репост записи</h2>
        <form id="repost-form" class="space-y-4">
            <input type="hidden" name="original_post_id" value="${postId}">
            <textarea name="text" placeholder="Добавьте свой комментарий..." rows="3" class="w-full bg-gray-800 text-white text-lg resize-none outline-none py-2 px-3 rounded"></textarea>
            
            <div class="p-3 border border-gray-700 rounded-lg bg-gray-800">
                <div class="flex items-start space-x-3">
                    <img src="${postData.avatar}" alt="Аватар" class="w-8 h-8 rounded-full">
                    <div>
                        <span class="font-bold">${postData.username}</span>
                        <span class="text-gray-400 text-sm">${postData.nickname}</span>
                    </div>
                </div>
                <p class="mt-2 text-gray-300">${postData.text}</p>
            </div>

            <div class="flex justify-end">
                <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-6 rounded-full transition-colors">Репостнуть</button>
            </div>
        </form>
    `;
    
    $('#modal-content').html(modalContent);
    $('#modal').removeClass('hidden');
});

// Обработчик отправки формы репоста
$(document).on('submit', '#repost-form', function(e) {
    e.preventDefault();

    const form = $(this);
    const formData = new FormData(this);
    formData.append('ajax', 1);
    formData.append('action', 'addPost'); // Репост это тоже создание поста

    $.ajax({
        url: 'api.php',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        success: function(res) {
            let parsedRes;
            try {
                parsedRes = JSON.parse(res);
            } catch (e) {
                alert('Ошибка: Неверный ответ от сервера');
                return;
            }

            if (parsedRes.success) {
                $('#modal').addClass('hidden'); 
                
                // Добавляем новый пост (репост) в ленту
                handleRepostSuccess(parsedRes.post);

            } else {
                alert('Ошибка: ' + (parsedRes.message || 'Неизвестная ошибка'));
            }
        },
        error: function() {
            alert('Ошибка при отправке запроса.');
        }
    });
});

// Обработчик для закрытия модального окна
$('#modal-close-btn, #modal').on('click', function(e) {
    if (e.target === this || e.target.id === 'modal-close-btn') {
        $('#modal').addClass('hidden');
        $('#modal-content').empty();
    }
});


// --- 5. Обработчики для меню поста (Удалить/Пожаловаться) ---

// Обработчик для кнопки меню
$(document).on('click', '.menu-toggle-btn', function(e) {
    e.preventDefault();
    $('.menu-content').addClass('hidden');
    $(this).closest('.post').find('.menu-content').toggleClass('hidden');
});

// Скрытие меню при клике вне его
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
        // !!! ИЗМЕНЕНО: Запрос идет на api.php, а не feed.php
        $.post('api.php', { ajax: 1, action: 'deletePost', post_id: postId }, function(res) { 
            let parsedRes;
            try {
                parsedRes = JSON.parse(res);
            } catch (e) {
                alert('Ошибка: Неверный ответ от сервера');
                return;
            }

            if (parsedRes.success) {
                // Удаляем пост из DOM
                $(`div[data-post-id="${postId}"]`).fadeOut(300, function() {
                    $(this).remove();
                });
            } else {
                alert('Ошибка: ' + (parsedRes.message || 'Неизвестная ошибка'));
            }
        });
    }
});

// Обработчик для кнопки "Пожаловаться"
$(document).on('click', '.report-post-btn', function(e) {
    e.preventDefault();
    const postId = $(this).data('id');
    // !!! ИЗМЕНЕНО: Запрос идет на api.php, а не feed.php
    $.post('api.php', { ajax: 1, action: 'reportPost', post_id: postId }, function(res) { 
        let parsedRes;
        try {
            parsedRes = JSON.parse(res);
        } catch (e) {
            alert('Ошибка: Неверный ответ от сервера');
            return;
        }

        if (parsedRes.success) {
            alert('Жалоба отправлена. Спасибо!');
        } else {
            alert('Ошибка: ' + (parsedRes.message || 'Неизвестная ошибка'));
        }
    });
});

// В main.js добавь в конец:

// Обработчик кнопки "Редактировать"
$('#edit-profile-btn').on('click', function() {
    $('#edit-profile-form').toggleClass('hidden');
});

// Обработчик формы редактирования
$('#profile-edit-form').on('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);
    formData.append('ajax', 1);
    formData.append('action', 'editProfile');

    $.ajax({
        url: 'api.php',
        type: 'POST',
        data: formData,
        contentType: false,
        processData: false,
        success: function(res) {
            let parsedRes = JSON.parse(res);
            if (parsedRes.success) {
                alert('Профиль обновлён!');
                location.reload();  // Перезагрузка страницы
            } else {
                alert('Ошибка: ' + parsedRes.message);
            }
        }
    });
});

// Обработчик follow/unfollow
$('#follow-btn').on('click', function() {
    const btn = $(this);
    const following_id = btn.data('following');
    
    $.post('api.php', { ajax: 1, action: 'toggleFollow', following_id: following_id }, function(res) {
        let parsedRes = JSON.parse(res);
        if (parsedRes.success) {
            btn.text(parsedRes.is_following ? 'Отписаться' : 'Подписаться');
            btn.toggleClass('bg-gray-600', parsedRes.is_following);
            $('#followers-btn .font-bold').text(parsedRes.follower_count);
        } else {
            alert('Ошибка: ' + parsedRes.message);
        }
    });
});

// Обработчики для списков followers/following (открывают модал)
$('#followers-btn, #following-btn').on('click', function() {
    const type = $(this).attr('id').includes('followers') ? 'loadFollowers' : 'loadFollowing';
    const modalContent = `
        <h2 class="text-xl font-bold mb-4">${type === 'loadFollowers' ? 'Подписчики' : 'Подписки'}</h2>
        <div id="user-list" class="space-y-2" data-type="${type}" data-offset="0"></div>
        <button id="load-more-users" class="mt-4 bg-blue-500 px-4 py-2 rounded">Загрузить больше</button>
    `;
    $('#modal-content').html(modalContent);
    $('#modal').removeClass('hidden');
    loadUserList(type, 0);  // Начальная загрузка
});

// Функция загрузки списка пользователей
function loadUserList(type, offset) {
    $.post('api.php', {
        ajax: 1,
        action: type,
        target_id: window.PROFILE_USER_ID,
        offset: offset
    }, function(res) {
        let users = JSON.parse(res);
        users.forEach(u => {
            $('#user-list').append(`
                <div class="flex items-center space-x-2">
                    <img src="${u.avatar || 'https://placehold.co/40x40'}" class="w-10 h-10 rounded-full">
                    <a href="profile.php?id=${u.id}" class="font-bold">${u.username}</a>
                </div>
            `);
        });
    });
}

// Обработчик "Загрузить больше" в модале
$(document).on('click', '#load-more-users', function() {
    const container = $('#user-list');
    const type = container.data('type');
    let offset = parseInt(container.data('offset')) + 20;
    container.data('offset', offset);
    loadUserList(type, offset);
});