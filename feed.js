// feed.js
import { renderPost } from './render.js';

// Переменные для бесконечной прокрутки
let offset = 0;
const limit = 5; 
let loading = false;
let allLoaded = false;
let repeatMode = false;

// НОВАЯ ПЕРЕМЕННАЯ: хранит текущий режим сортировки. По умолчанию - общая лента.
export let currentMode = 'general'; // Сделаем export для внешнего доступа, если нужно

// ВНИМАНИЕ: window.CURRENT_USER_ID должен быть установлен в js.php

/**
 * Загружает посты из ленты.
 * @param {boolean} [reset=false] Сбросить ли счетчик и очистить контейнер.
 * @param {string} [mode=currentMode] Режим сортировки: 'general' (общая), 'user:ID' (личная).
 */
export function loadPosts(reset = false, mode = currentMode){ // Изменено: добавлен параметр mode
    const current_user_id = window.CURRENT_USER_ID; 

    // Обновляем текущий режим, если был передан новый
    if (mode !== currentMode) {
        currentMode = mode;
        reset = true; // Сброс обязателен при смене режима
    }
    
    if(loading) return;
    loading = true;
    if(reset){
        offset = 0;
        allLoaded = false;
        repeatMode = false;
    }

    $('#loading').show().text('Загрузка...');
    
    // Формирование данных для отправки на сервер
    const postData = {
        ajax: 1, 
        action: 'loadPosts', 
        offset: offset,
        mode: currentMode, // Передаем режим
    };

    // Если режим 'user:ID', извлекаем ID пользователя и добавляем его в данные
    if (currentMode.startsWith('user:')) {
        const parts = currentMode.split(':');
        const userId = parseInt(parts[1]);
        if (!isNaN(userId) && userId > 0) {
            postData.target_user_id = userId; // ID пользователя, чьи посты хотим видеть
        } else {
            console.warn(`Неверный ID пользователя в режиме: ${currentMode}. Сброс на 'general'.`);
            currentMode = 'general';
            postData.mode = currentMode;
        }
    }

    // ВНИМАНИЕ: Здесь отправляем запрос на feed.php, который включает api.php
    $.post('feed.php', postData, function(posts){ 
        try {
            posts = JSON.parse(posts);
        } catch(e) {
            $('#loading').text('Ошибка обработки данных');
            loading = false;
            return;
        }

        if(reset) $('#posts-container').empty();

        if(!posts || posts.length === 0){
            if(!allLoaded){
                allLoaded = true;
                $('#loading').text('Посты закончились — лента может повторяться при скролле вниз');
            } else {
                // ... (логика повтора ленты)
                repeatMode = true;
                offset = 0;
                $('#loading').text('Повтор ленты...');
                loading = false;
                loadPosts(false, currentMode);
                return;
            }
            loading = false;
            return;
        }

        posts.forEach(p=>{
            $('#posts-container').append(renderPost(p, false, repeatMode, current_user_id));
        });

        offset += posts.length;
        $('#loading').hide();
        loading = false;
    }).fail(function(){
        $('#loading').text('Ошибка загрузки постов');
        loading = false;
    });
}

/**
 * Загружает ответы (комментарии) для указанного поста.
 * @param {number} parentId ID родительского поста.
 */
export function loadReplies(parentId){
    const current_user_id = window.CURRENT_USER_ID; 
    
    $.post('feed.php', {ajax:1, action:'loadReplies', post_id: parentId}, function(res){
        let replies;
        try {
            replies = JSON.parse(res);
        } catch(e) {
            console.error("Ошибка парсинга ответов", e);
            return;
        }
        
        let container = $('#child-'+parentId);
        container.empty();
        if(replies && replies.length){
            replies.forEach(r=>{
                container.append(renderPost(r, true, false, current_user_id)); 
            });
        }
    }).fail(function(){
         console.error("Ошибка загрузки ответов");
    });
}

/**
 * Обрабатывает успешное создание/отправку поста (инициация сброса ленты).
 * @param {Object} post Объект нового поста.
 */
export function handleNewPostSuccess(post){
    const current_user_id = window.CURRENT_USER_ID; 

    // Добавляем новый пост в начало, только если мы в общей ленте
    // ИЛИ если мы в личной ленте и этот пост от владельца ленты
    const targetUserId = currentMode.startsWith('user:') ? parseInt(currentMode.split(':')[1]) : null;
    
    if (currentMode === 'general' || (targetUserId && post.user_id == targetUserId)) {
        $('#posts-container').prepend(renderPost(post, false, false, current_user_id));
    }
    
    allLoaded = false;
    repeatMode = false;
    offset = 0;
}

/**
 * Сбрасывает режимы прокрутки при успешном репосте.
 */
export function handleRepostSuccess(post){
    handleNewPostSuccess(post);
    window.scrollTo(0,0);
}