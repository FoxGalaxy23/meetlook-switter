// render.js
import { escapeHtml, linkify, extractYouTubeId } from './utils.js';

/**
 * Рендерит HTML для медиафайлов (изображения, видео, аудио).
 * @param {Array<Object>} media Массив объектов медиа.
 * @returns {string} HTML-код медиа-блока.
 */
export function renderMedia(media){
    if(!media || !media.length) return '';
    // Контейнер с сеткой для 1:1
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

/**
 * Рендерит HTML для превью прикрепляемых файлов.
 * @param {File} file Файл для превью.
 * @param {number} idx Индекс файла.
 * @returns {HTMLElement} Элемент div с превью.
 */
export function makePreviewElement(file, idx) {
    const wrapper = document.createElement('div');
    wrapper.className = 'preview-thumb rounded overflow-hidden relative';
    wrapper.style.minHeight = '48px';
    wrapper.dataset.index = idx;

    const removeBtn = document.createElement('button');
    removeBtn.className = 'preview-remove absolute top-0 right-0 p-1 bg-red-600 text-white rounded-bl opacity-80 hover:opacity-100';
    removeBtn.type = 'button';
    removeBtn.textContent = '×';
    removeBtn.setAttribute('data-remove-index', idx);

    wrapper.appendChild(removeBtn);

    // Добавляем основное содержимое превью
    if(file.type.startsWith('image/')) {
        const img = document.createElement('img');
        img.className = 'w-full h-auto object-cover';
        img.style.maxHeight = '160px';
        const reader = new FileReader();
        reader.onload = e => img.src = e.target.result;
        reader.readAsDataURL(file);
        wrapper.appendChild(img);
    } else if(file.type.startsWith('video/') || file.type.startsWith('audio/')) {
        // Для видео/аудио используем теги <video>/<audio>
        const mediaEl = document.createElement(file.type.startsWith('video/') ? 'video' : 'audio');
        mediaEl.controls = true;
        mediaEl.className = 'w-full h-auto';
        mediaEl.style.maxHeight = '160px';
        const reader = new FileReader();
        reader.onload = e => mediaEl.src = e.target.result;
        reader.readAsDataURL(file);
        
        if (file.type.startsWith('audio/')) {
             const audWrap = document.createElement('div');
             audWrap.className = 'p-2 bg-gray-800';
             audWrap.appendChild(mediaEl);
             wrapper.appendChild(audWrap);
        } else {
            wrapper.appendChild(mediaEl);
        }
    } else {
        const txt = document.createElement('div');
        txt.className = 'p-2 bg-gray-800 text-sm text-gray-300';
        txt.textContent = 'Файл: ' + file.name;
        wrapper.appendChild(txt);
    }
    return wrapper;
}

/**
 * Рендерит HTML-код для отдельного поста.
 * @param {Object} post Данные поста.
 * @param {boolean} [isChild=false] Флаг, указывающий, является ли пост комментарием.
 * @param {boolean} [repeated=false] Флаг, указывающий, что пост загружен в режиме повтора.
 * @param {number} current_user_id ID текущего пользователя (для кнопок управления).
 * @returns {string} HTML-код поста.
 */
export function renderPost(post, isChild = false, repeated = false, current_user_id) {
    const textHtml = linkify(post.text || '');
    let ytFrameHtml = '';

    // Находим YouTube-ссылки и создаем iframe
    if (post.text) {
        const urlRegex = /https?:\/\/[^\s<]+/g;
        const urls = post.text.match(urlRegex) || [];
        urls.forEach(url => {
            const yt = extractYouTubeId(url);
            if (yt) {
                // Используем ссылку с параметром `embed`
                ytFrameHtml += `<div class="mt-2"><iframe width="100%" height="315" src="https://www.youtube.com/embed/${yt}" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
            }
        });
    }

    // Рендер оригинального поста (для репоста)
    let origHtml = '';
    if (post.original_post) {
        let o = post.original_post;
        let oText = linkify(o.text || '');
        const oAvatarHtml = o.avatar ? `<img src="${o.avatar}" alt="Аватарка" class="w-8 h-8 rounded-full mr-2">` : '';
        origHtml = `<div class="mt-2 p-3 bg-gray-800 rounded border border-gray-700">
            <div class="flex items-center">
                ${oAvatarHtml}
                <div class="font-semibold">${escapeHtml(o.username)}</div>
            </div>
            <div class="text-sm text-gray-300 mt-1">${oText}</div>
            ${renderMedia(o.media)}
        </div>`;
    } else if (post.original_post_id && !post.original_post) {
        origHtml = `<div class="mt-2 p-3 bg-gray-800 rounded border border-gray-700 text-gray-400">Оригинал недоступен</div>`;
    }

    // Меню поста (удалить/пожаловаться)
    let dropdownMenu = '';
    if (post.user_id == current_user_id) {
        dropdownMenu = `<div class="relative inline-block text-right">
            <button class="menu-btn text-gray-400 hover:text-white">...</button>
            <div class="menu-content absolute right-0 mt-2 w-48 bg-gray-700 rounded-md shadow-lg hidden z-10">
                <button data-id="${post.id}" class="delete-post-btn block w-full text-left px-4 py-2 text-sm text-red-400 hover:bg-gray-600">
                    Удалить пост
                </button>
            </div>
        </div>`;
    } else {
        dropdownMenu = `<div class="relative inline-block text-right">
            <button class="menu-btn text-gray-400 hover:text-white">...</button>
            <div class="menu-content absolute right-0 mt-2 w-48 bg-gray-700 rounded-md shadow-lg hidden z-10">
                <button data-id="${post.id}" class="report-post-btn block w-full text-left px-4 py-2 text-sm text-yellow-400 hover:bg-gray-600">
                    Пожаловаться
                </button>
            </div>
        </div>`;
    }

    const avatarHtml = post.avatar ? `<img src="${post.avatar}" alt="Аватарка" class="w-10 h-10 rounded-full mr-3">` : '';
    let repeatedClass = repeated ? 'repeated' : '';
    let repeatBadge = repeated ? '<div class="repeat-badge text-xs bg-red-600 text-white px-2 py-0.5 rounded-full inline-block mb-1">повтор ленты</div>' : '';

    // Структура поста
    return `<div class="p-4 border-b border-gray-800 ${isChild ? 'bg-gray-850' : 'bg-transparent'} ${repeatedClass}" data-post-id="${post.id}">
        ${repeatBadge}
        <div class="flex justify-between items-center">
            <div class="flex items-center">
                ${avatarHtml}
                <span class="font-bold">${escapeHtml(post.username)}</span>
            </div>
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
                    <button type="submit" class="bg-blue-500 px-3 py-1 rounded hover:bg-blue-600 transition-colors">Отправить</button>
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