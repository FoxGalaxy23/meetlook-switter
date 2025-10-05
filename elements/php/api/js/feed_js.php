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