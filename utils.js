// utils.js

/**
 * Экранирует специальные HTML-символы для безопасного отображения текста.
 * @param {string} unsafe Текст, который нужно экранировать.
 * @returns {string} Экранированный текст.
 */
export function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe
         .replace(/&/g, "&amp;")
         .replace(/</g, "&lt;")
         .replace(/>/g, "&gt;")
         .replace(/"/g, "&quot;")
         .replace(/'/g, "&#039;");
}

/**
 * Извлекает ID видео из URL YouTube.
 * @param {string} url URL YouTube.
 * @returns {string|null} ID видео или null.
 */
export function extractYouTubeId(url) {
    try {
        let u = new URL(url);
        // youtu.be/ID
        if(u.hostname.includes('youtu.be')) {
            return u.pathname.slice(1);
        }
        // youtube.com/watch?v=ID
        if(u.hostname.includes('youtube.com')) {
            return u.searchParams.get('v');
        }
    } catch(e) { return null; }
    return null;
}

/**
 * Превращает URL-адреса в тексте в кликабельные HTML-ссылки.
 * @param {string} text Исходный текст.
 * @returns {string} Текст с HTML-ссылками.
 */
export function linkify(text) {
    if(!text) return '';
    // Сначала экранируем текст, чтобы избежать XSS
    text = escapeHtml(text);
    const urlRegex = /https?:\/\/[^\s<]+/g;
    return text.replace(urlRegex, function(url){
        // Добавляем стили для ссылок (из Tailwind CSS)
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" class="text-blue-400 underline">${url}</a>`;
    });
}

/**
 * Вспомогательная функция: выставить input.files через DataTransfer на основании оставшихся файлов.
 * Используется при удалении файла из превью.
 * @param {HTMLInputElement} inputEl Элемент input[type=file].
 * @param {File[]} filesArray Массив файлов, которые должны остаться.
 */
export function setFilesOnInput(inputEl, filesArray) {
    const dt = new DataTransfer();
    filesArray.forEach(f => dt.items.add(f));
    inputEl.files = dt.files;
}