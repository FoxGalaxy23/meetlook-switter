<?php
// messages.php - Монолитный файл для чата с логикой Long Polling и AJAX-обработкой.

// Требует: elements/php/db.php, elements/php/header.php, elements/php/sidebar-left.php, elements/php/sidebar-right.php, elements/php/footer.php

session_start();

// --- СЕКЦИЯ БЕЗОПАСНОСТИ: CSRF ---
// Генерируем CSRF токен, если его нет
if (empty($_SESSION['csrf_token'])) {
    // Используем cryptographically secure random string
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
}
$csrf_token = $_SESSION['csrf_token']; 
// ---------------------------------

// Предполагается, что это путь к вашему подключению к БД ($conn)
require_once __DIR__ . "/elements/php/db.php"; 

if (!$conn) {
    // В случае ошибки подключения к базе данных, выводим ошибку и завершаем
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(["success"=>false, "error"=>"db_connection_error"]);
        exit;
    }
    http_response_code(500);
    echo "DB connection error";
    exit;
}

// Убедимся, что $currentUserId - это int, если он есть
$currentUserId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

if (!$currentUserId) {
    // Если пользователь не авторизован, перенаправляем на логин
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success'=>false,'error'=>'login_required']); 
        exit;
    }
    header("Location: login.php");
    exit;
}

/* ----------------------------------------------------
   --- СЕКЦИЯ 1: ОБРАБОТКА AJAX И LONG POLLING ---
   (Если в POST-запросе есть 'action', обрабатываем его и завершаем скрипт)
---------------------------------------------------- */
$action = $_POST['action'] ?? null;

if ($action) {
    // Устанавливаем заголовок для AJAX-ответа
    header('Content-Type: application/json');
    
    // --- ПРОВЕРКА CSRF ---
    $post_csrf_token = $_POST['csrf_token'] ?? '';
    if (hash_equals($csrf_token, $post_csrf_token) === false) {
        // Защита от CSRF: токен не совпадает
        error_log("CSRF attack detected for user ID: " . $currentUserId);
        echo json_encode(['success' => false, 'error' => 'csrf_token_invalid']);
        exit;
    }
    // ----------------------

    // Установим лимит выполнения скрипта для Long Polling (например, 35 секунд)
    set_time_limit(35); 

    if (!$action) {
        echo json_encode(['success' => false, 'error' => 'no_action_specified']);
        exit;
    }

    /* --- Вспомогательная функция для генерации HTML сообщения --- */
    function generateMessageHtml($msg, $currentUserId) {
        // Убедимся, что id сообщения корректно преобразовано в int
        $msgId = intval($msg['id']);
        $isSender = intval($msg['sender_id']) === $currentUserId;
        $bubbleClass = $isSender ? 'bg-blue-600 ml-auto' : 'bg-gray-700 mr-auto'; 
        $alignmentClass = $isSender ? 'justify-end' : 'justify-start';
        // HTML-экранирование имени отправителя
        $senderName = $isSender ? 'Я' : htmlspecialchars($msg['username']);
        // HTML-экранирование текста и nl2br для корректного переноса строк
        $text = nl2br(htmlspecialchars($msg['text']));
        
        // Получение времени в нужном формате
        $time = (new DateTime($msg['created_at']))->format('H:i');
        
        // Использование data-id для более удобного JS-доступа
        return "<div id='msg-{$msgId}' data-id='{$msgId}' class='flex w-full mb-2 {$alignmentClass}'>
                    <div class='max-w-xs lg:max-w-md p-3 rounded-xl {$bubbleClass} text-white'>
                        <p class='text-xs font-semibold text-gray-300 mb-1'>{$senderName} ({$time})</p>
                        <p>{$text}</p>
                    </div>
                </div>";
    }

    /* ----------------------------------------------------
       ОБРАБОТЧИК 1.1: ОТПРАВКА СООБЩЕНИЯ (sendMessage)
    ---------------------------------------------------- */
    if ($action === 'sendMessage') {
        $text = trim($_POST['text'] ?? "");
        $chatId = intval($_POST['chat_id'] ?? 0) ?: null;

        if ($text === "" || !$chatId) { 
            echo json_encode(["success"=>false, "error"=>"invalid_data"]); 
            exit; 
        }
        
        // Ограничиваем длину сообщения, чтобы избежать переполнения базы данных
        if (mb_strlen($text) > 5000) { 
            echo json_encode(["success"=>false, "error"=>"message_too_long"]); 
            exit; 
        }

        $conn->begin_transaction(); 

        try {
            // 1. Проверяем, что текущий пользователь — участник чата 
            $stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
            $stmt->bind_param("ii", $chatId, $currentUserId);
            $stmt->execute();
            if ($stmt->get_result()->num_rows === 0) { throw new Exception("User not a participant"); }
            $stmt->close();

            // 2. Отправляем сообщение
            $stmt = $conn->prepare("INSERT INTO messages (chat_id, sender_id, text, created_at) VALUES (?, ?, ?, NOW())");
            // Обратите внимание: $text уже прошел trim() и теперь привязывается как строка (s)
            $stmt->bind_param("iis", $chatId, $currentUserId, $text); 
            $dbSuccess = $stmt->execute();
            $newMessageId = $stmt->insert_id;
            $stmt->close();

            if ($dbSuccess) {
                // 3. Обновляем время последнего сообщения в чате
                $stmt = $conn->prepare("UPDATE chats SET last_message_at = NOW() WHERE id = ?");
                $stmt->bind_param("i", $chatId);
                $stmt->execute();
                $stmt->close();
                
                $conn->commit();
                
                // Возвращаем ID и *сырой* текст (без htmlspecialchars), JS сам обработает его для DOM
                echo json_encode([
                    "success"=>true, 
                    "messageId"=>$newMessageId, 
                    "messageText"=>$text // Возвращаем сырой текст
                ]);
                exit;
            } else {
                throw new Exception("Failed to insert message");
            }

        } catch (Exception $e) {
            $conn->rollback();
            // Возвращаем общее сообщение об ошибке, не раскрывая детали БД
            error_log("Chat message transaction failed: " . $e->getMessage());
            echo json_encode(["success"=>false, "error"=>"server_error_sending_message"]); 
            exit;
        }
    }

    /* ----------------------------------------------------
       ОБРАБОТЧИК 1.2: ЗАГРУЗКА ИЗНАЧАЛЬНЫХ СООБЩЕНИЙ (Initial Load)
    ---------------------------------------------------- */
    if ($action === 'loadInitialMessages') {
        $chatId = intval($_POST['chat_id'] ?? 0);
        
        // Проверка доступа (повтор)
        $stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $chatId, $currentUserId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) { 
            $stmt->close(); 
            echo json_encode(["success"=>false, "error"=>"no_access"]); 
            exit; 
        }
        $stmt->close();
        
        // Загружаем последние 50 сообщений (самые новые внизу)
        // Чтобы избежать сортировки большого количества данных, можем использовать подзапрос для LIMIT/OFFSET, но для LIMIT 50 это сработает быстро.
        $stmt = $conn->prepare("SELECT m.id, m.text, m.sender_id, m.created_at, u.username
                                FROM messages m 
                                JOIN users u ON m.sender_id = u.id
                                WHERE m.chat_id = ? 
                                ORDER BY m.id ASC 
                                LIMIT 50");
        $stmt->bind_param("i", $chatId);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        
        $html = '';
        $lastMessageId = 0;
        
        foreach ($messages as $msg) {
            $html .= generateMessageHtml($msg, $currentUserId);
            $lastMessageId = intval($msg['id']); 
        }

        echo json_encode(["success"=>true, "messagesHtml"=>$html, "lastMessageId" => $lastMessageId]);
        exit;
    }

    /* ----------------------------------------------------
       ОБРАБОТЧИК 1.3: ДЛИТЕЛЬНЫЙ ОПРОС (pollForNewMessages)
    ---------------------------------------------------- */
    if ($action === 'pollForNewMessages') {
        $chatId = intval($_POST['chat_id'] ?? 0);
        $lastMessageId = intval($_POST['last_message_id'] ?? 0);
        $timeout = 25; 
        $startTime = time();

        // Проверка доступа (повтор)
        $stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $chatId, $currentUserId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) { 
            $stmt->close(); 
            echo json_encode(["success"=>false, "error"=>"no_access"]); 
            exit; 
        }
        $stmt->close(); // Закрываем первый stmt

        $messages = [];
        $stmt = null; // Инициализируем $stmt
        
        while (time() - $startTime < $timeout) {
            // Ищем сообщения, ID которых больше последнего увиденного
            $stmt = $conn->prepare("SELECT m.id, m.text, m.sender_id, m.created_at, u.username 
                                    FROM messages m 
                                    JOIN users u ON m.sender_id = u.id
                                    WHERE m.chat_id = ? AND m.id > ?
                                    ORDER BY m.id ASC
                                    LIMIT 20"); // Добавим лимит для большей производительности
            $stmt->bind_param("ii", $chatId, $lastMessageId);
            $stmt->execute();
            $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close(); // Закрываем $stmt после каждого выполнения

            if (!empty($messages)) {
                break; // Сообщения найдены, выходим из цикла
            }
            
            // Если сообщений нет, ждем полсекунды и проверяем снова
            usleep(500000); 
        }
        
        $html = '';
        $newLastMessageId = $lastMessageId;
        
        if (!empty($messages)) {
            foreach ($messages as $msg) {
                $html .= generateMessageHtml($msg, $currentUserId);
                $newLastMessageId = intval($msg['id']); // Обновляем ID самого нового сообщения
            }
        }

        echo json_encode([
            "success" => true, 
            "messagesHtml" => $html,
            "lastMessageId" => $newLastMessageId 
        ]);
        exit;
    }

    // Если действие не распознано
    echo json_encode(['success' => false, 'error' => 'unknown_action']);
    exit;
}

/* ----------------------------------------------------
   --- СЕКЦИЯ 2: ИНИЦИАЛИЗАЦИЯ ЧАТА (HTML-ВЫВОД) ---
   (Выполняется только, если не было AJAX-запроса)
---------------------------------------------------- */

// Используем параметры ?user= (ЛС) или ?chat= (Групповой/Известный чат)
$chatId = isset($_GET['chat']) ? intval($_GET['chat']) : null;
$targetUserId = isset($_GET['user']) ? intval($_GET['user']) : null; 

$chat = null;
$chatName = "Диалог";
$headerLink = "#"; 
$targetUser = null; 

// 1. Найти или создать приватный чат, если передан user ID
if ($targetUserId && $targetUserId !== $currentUserId) {
    
    $conn->begin_transaction();

    try {
        // Проверяем, существует ли пользователь
        $stmt = $conn->prepare("SELECT id, username, avatar FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $targetUserId);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$targetUser) throw new Exception("Пользователь не найден.");

        // Ищем существующий приватный чат 1-на-1
        $stmt = $conn->prepare("
            SELECT c.id FROM chats c
            JOIN chat_participants cp1 ON c.id = cp1.chat_id AND cp1.user_id = ?
            JOIN chat_participants cp2 ON c.id = cp2.chat_id AND cp2.user_id = ?
            WHERE c.type = 'private' AND 
            (SELECT COUNT(*) FROM chat_participants WHERE chat_id = c.id) = 2
            LIMIT 1
        ");
        $stmt->bind_param("ii", $currentUserId, $targetUserId);
        $stmt->execute();
        $res = $stmt->get_result();
        $existingChat = $res->fetch_assoc();
        $stmt->close();

        if ($existingChat) {
            $chatId = intval($existingChat['id']);
        } else {
            // Создаем новый приватный чат
            $stmt = $conn->prepare("INSERT INTO chats (type, last_message_at) VALUES ('private', NOW())");
            $stmt->execute();
            $chatId = $stmt->insert_id;
            $stmt->close();
            
            if (!$chatId) throw new Exception("Ошибка создания чата");

            // Добавляем обоих участников
            $stmt = $conn->prepare("INSERT INTO chat_participants (chat_id, user_id, joined_at) VALUES (?, ?, NOW()), (?, ?, NOW())");
            $stmt->bind_param("iiii", $chatId, $currentUserId, $chatId, $targetUserId);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        $chatName = htmlspecialchars($targetUser['username']);
        $headerLink = 'profile.php?id=' . $targetUserId;
        
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(400);
        echo "Ошибка инициализации чата: " . htmlspecialchars($e->getMessage()); // Экранируем сообщение об ошибке
        exit;
    }
}


// 2. Загрузка данных чата, если известен $chatId
if ($chatId) {
    // Проверяем, что чат существует и что текущий пользователь в нем участвует
    $stmt = $conn->prepare("
        SELECT c.* FROM chats c
        JOIN chat_participants cp ON c.id = cp.chat_id
        WHERE c.id = ? AND cp.user_id = ? LIMIT 1
    ");
    $stmt->bind_param("ii", $chatId, $currentUserId);
    $stmt->execute();
    $chat = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$chat) {
        http_response_code(403);
        echo "Нет доступа к этому чату.";
        exit;
    }
    
    // Если это приватный чат, ищем имя собеседника
    if ($chat['type'] === 'private') {
        $stmt = $conn->prepare("
            SELECT u.id, u.username, u.avatar FROM chat_participants cp
            JOIN users u ON cp.user_id = u.id
            WHERE cp.chat_id = ? AND cp.user_id != ? LIMIT 1
        ");
        $stmt->bind_param("ii", $chatId, $currentUserId);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($targetUser) {
             $chatName = htmlspecialchars($targetUser['username']);
             $targetUserId = intval($targetUser['id']);
             $headerLink = 'profile.php?id=' . $targetUserId; // Ссылка на профиль собеседника
        }
    } else { // Групповой чат
        $chatName = htmlspecialchars($chat['name'] ?? 'Групповой чат');
        $headerLink = 'messages.php?chat=' . $chatId; // Ссылка на страницу чата
    }
}

if (!$chatId) {
    http_response_code(404);
    echo "Чат не найден. Пожалуйста, укажите ?user=<ID> или ?chat=<ID>.";
    exit;
}
?>

<!-- HTML и JavaScript для вывода -->
<?php include __DIR__ . '/elements/php/header.php'; ?>
    <div class="flex w-full max-w-7xl mx-auto min-h-screen">
        <?php require_once __DIR__ . '/elements/php/sidebar-left.php'; ?>

        <!-- !!! ВАЖНО: flex flex-col и min-h-screen для 100% высоты !!! -->
        <main class="w-full md:w-2/3 lg:w-1/2 border-x border-gray-800 min-h-screen bg-gray-900 flex flex-col">
            <!-- Заголовок чата -->
            <header class="sticky top-0 bg-gray-900 bg-opacity-80 backdrop-blur-sm p-4 border-b border-gray-800 z-20 flex items-center">
                <a href="<?= htmlspecialchars($headerLink) ?>" class="flex items-center hover:underline">
                    <?php if ($targetUser && isset($targetUser['avatar'])): ?>
                        <img src="<?= htmlspecialchars($targetUser['avatar']) ?>" class="w-10 h-10 rounded-full mr-3 object-cover" alt="avatar">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-full mr-3 bg-gray-700 flex items-center justify-center text-xl">
                            <?= ($chat && $chat['type'] === 'group') ? '👥' : '💬' ?>
                        </div>
                    <?php endif; ?>
                    <h1 class="text-xl font-bold"><?= $chatName ?></h1>
                </a>
            </header>

            <!-- Контейнер для сообщений: flex-grow занимает все свободное место -->
            <div class="p-4 flex flex-col flex-grow overflow-y-auto" id="messages-container">
                <!-- Сообщения будут загружаться сюда -->
                <p class="text-center text-gray-400">Загрузка сообщений...</p>
            </div>
            
            <!-- Форма отправки сообщения -->
            <div class="sticky bottom-0 bg-gray-900 p-4 border-t border-gray-800">
                <div id="error-message" class="text-red-400 mb-2 hidden"></div>
                <form id="send-message-form" class="flex space-x-2">
                    <!-- Добавляем CSRF токен для безопасности -->
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token) ?>">
                    <input type="hidden" name="action" value="sendMessage">
                    <input type="hidden" name="chat_id" value="<?= $chatId ?>">
                    <!-- Заменили <input> на <textarea> для поддержки переноса строк -->
                    <textarea 
                        name="text" 
                        id="message-text-input"
                        placeholder="Ваше сообщение... Нажмите Enter для отправки, Shift+Enter для новой строки." 
                        class="flex-1 bg-gray-800 p-2 rounded text-white resize-none" 
                        rows="1"
                        required></textarea>
                    <button type="submit" id="send-button" class="bg-blue-500 px-3 py-1 rounded hover:bg-blue-600 transition-colors self-end">Отправить</button>
                </form>
            </div>

        </main>

        <?php require_once __DIR__ . '/elements/php/sidebar-right.php'; ?>
    </div>
    
    <!-- JavaScript для AJAX-общения и Long Polling -->
    <script>
        const sendMessageForm = document.getElementById('send-message-form');
        const messagesContainer = document.getElementById('messages-container');
        const errorDisplay = document.getElementById('error-message');
        const textInput = document.getElementById('message-text-input'); // ИСПОЛЬЗУЕМ ТЕПЕРЬ ТЕКСТАРЕА
        const sendButton = document.getElementById('send-button');
        
        // Получаем токен из скрытого поля формы
        const csrfToken = document.querySelector('input[name="csrf_token"]').value; 
        const currentChatId = <?= $chatId ?>; 
        const AJAX_ENDPOINT = window.location.pathname + window.location.search; 
        
        let lastMessageId = 0; // ID последнего сообщения, которое мы видели на экране
        let isUserScrolling = false; 
        let pollTimeoutId; // ID для управления циклом Long Polling

        // Функция для остановки текущего цикла опроса (для предотвращения дублирования)
        function stopPolling() {
            if (pollTimeoutId) {
                clearTimeout(pollTimeoutId);
                pollTimeoutId = null;
            }
        }
        
        // Заменяем alert() на вывод в специальный блок или консоль
        function displayError(message) {
            console.error('Ошибка:', message);
            errorDisplay.textContent = 'Ошибка: ' + message;
            errorDisplay.classList.remove('hidden');
            setTimeout(() => {
                errorDisplay.classList.add('hidden');
            }, 5000); 
        }

        // Прокрутка вниз
        function scrollToBottom() {
            // Прокручиваем вниз, только если пользователь сам не прокручивает
            // +50 пикселей - допуск
            if (!isUserScrolling) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }
        
        // Отслеживаем прокрутку пользователя
        messagesContainer.addEventListener('scroll', () => {
            // Если скроллбар не в самом низу (допуск 50px), считаем, что пользователь скроллит
            isUserScrolling = messagesContainer.scrollTop < (messagesContainer.scrollHeight - messagesContainer.clientHeight - 50);
        });
        
        // Автоматическая регулировка высоты textarea
        function resizeTextarea() {
            textInput.style.height = 'auto';
            textInput.style.height = (textInput.scrollHeight) + 'px';
        }

        // --- 1. Функция загрузки начальных сообщений (при загрузке страницы) ---
        async function loadInitialMessages() {
            const fd = new FormData();
            fd.append('action', 'loadInitialMessages'); 
            fd.append('chat_id', currentChatId);
            fd.append('csrf_token', csrfToken); // Добавляем токен

            try {
                const res = await fetch(AJAX_ENDPOINT, {method: 'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                
                if (d && d.success) {
                    messagesContainer.innerHTML = d.messagesHtml;
                    lastMessageId = d.lastMessageId || 0;
                    scrollToBottom();
                    // *** АВТОФОКУС ***
                    textInput.focus();
                    // После загрузки запускаем Long Polling
                    pollForNewMessages(); 
                } else {
                    messagesContainer.innerHTML = '<p class="text-center text-red-400">Ошибка загрузки сообщений.</p>';
                    displayError('Ошибка загрузки сообщений. ' + (d.error || ''));
                }
            } catch (err) {
                messagesContainer.innerHTML = '<p class="text-center text-red-400">Ошибка сети при загрузке сообщений.</p>';
                displayError('Ошибка сети при загрузке сообщений.');
            }
        }

        // --- 2. Функция длительного опроса (Long Polling) ---
        async function pollForNewMessages() {
            const fd = new FormData();
            fd.append('action', 'pollForNewMessages');
            fd.append('chat_id', currentChatId);
            fd.append('last_message_id', lastMessageId); 
            fd.append('csrf_token', csrfToken); // Добавляем токен
            
            try {
                const res = await fetch(AJAX_ENDPOINT, {method: 'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                
                if (d && d.success) {
                    if (d.messagesHtml) {
                        // Вставляем новые сообщения в *конец* контейнера
                        messagesContainer.insertAdjacentHTML('beforeend', d.messagesHtml);
                        lastMessageId = d.lastMessageId || lastMessageId;
                        scrollToBottom();
                    }
                    // Успешный ответ, запускаем опрос немедленно (или через небольшую паузу)
                    pollTimeoutId = setTimeout(pollForNewMessages, 50); 

                } else {
                    console.error('Ошибка Long Polling:', d.error);
                    // Ждем 2 секунды перед повтором в случае ошибки
                    pollTimeoutId = setTimeout(pollForNewMessages, 2000);
                }
            } catch (err) {
                // Если произошла ошибка сети (например, таймаут)
                console.warn('Long Polling: Ошибка сети/Таймаут. Повтор через 2 секунды...');
                pollTimeoutId = setTimeout(pollForNewMessages, 2000);
            }
            // Убрали секцию finally, чтобы использовать pollTimeoutId
        }

        // --- 3. Отправка сообщения ---
        sendMessageForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const text = textInput.value.trim();
            if (!text) return;
            
            const fd = new FormData(this);
            textInput.disabled = true;
            sendButton.disabled = true;
            
            try {
                // *** ИСПРАВЛЕНИЕ БАГА ДУБЛИРОВАНИЯ: ОСТАНОВКА ОПРОСА ***
                stopPolling();
                
                const res = await fetch(AJAX_ENDPOINT, {method: 'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                
                if (d && d.success) {
                    // Генерируем HTML для локального отображения, используя D.messageText (сырой текст)
                    // Заменяем \n на <br> и HTML-экранируем текст перед вставкой
                    const safeText = d.messageText
                        .replace(/&/g, '&amp;')
                        .replace(/</g, '&lt;')
                        .replace(/>/g, '&gt;')
                        .replace(/"/g, '&quot;')
                        .replace(/\n/g, '<br>');

                    const myMessageHtml = `
                        <div id='msg-${d.messageId}' data-id='${d.messageId}' class='flex w-full mb-2 justify-end'>
                            <div class='max-w-xs lg:max-w-md p-3 rounded-xl bg-blue-600 ml-auto text-white'>
                                <p class='text-xs font-semibold text-gray-300 mb-1'>Я (${new Date().toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'})})</p>
                                <p>${safeText}</p>
                            </div>
                        </div>
                    `;
                    // Добавляем в конец
                    messagesContainer.insertAdjacentHTML('beforeend', myMessageHtml); 
                    textInput.value = ''; 
                    resizeTextarea(); // Сброс высоты textarea
                    scrollToBottom();

                    // Обновляем ID последнего сообщения, чтобы опрос его не возвращал
                    if (d.messageId) {
                        lastMessageId = d.messageId; 
                    }
                    
                    // *** ИСПРАВЛЕНИЕ БАГА ДУБЛИРОВАНИЯ: ПЕРЕЗАПУСК ОПРОСА ***
                    // Начинаем опрос снова, используя обновленный lastMessageId
                    pollForNewMessages();
                    
                } else {
                    // Используем displayError
                    displayError('Ошибка при отправке сообщения: ' + (d.error || 'Неизвестная ошибка'));
                    // Если отправка не удалась, перезапускаем опрос
                    pollForNewMessages(); 
                }
            } catch (err) {
                console.error('Ошибка сети при отправке сообщения:', err);
                displayError('Ошибка сети при отправке сообщения');
                // Если произошла ошибка сети, перезапускаем опрос
                pollForNewMessages(); 
            } finally {
                textInput.disabled = false;
                sendButton.disabled = false;
                textInput.focus();
            }
        });

        // --- 4. Отправка по Enter и регулировка размера ---
        textInput.addEventListener('keydown', function(e) {
            // Shift+Enter для новой строки
            if (e.key === 'Enter' && e.shiftKey) {
                // Позволяем вставить новую строку
                return;
            }
            
            // Enter без Shift для отправки
            if (e.key === 'Enter') {
                e.preventDefault(); // Останавливаем стандартное действие Enter (которое пытается добавить новую строку)
                sendMessageForm.dispatchEvent(new Event('submit')); // Отправляем форму
            }
        });
        
        // Регулировка высоты при вводе
        textInput.addEventListener('input', resizeTextarea);


        // Инициализация загрузки и старт Long Polling
        loadInitialMessages();

    </script>
<?php include __DIR__ . '/elements/php/footer.php'; ?>
