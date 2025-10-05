<?php
// messages.php - Страница личных и общих сообщений

session_start();
require_once __DIR__ . "/elements/php/db.php"; // $conn - файл подключения к базе данных

if (!$conn) {
    http_response_code(500);
    echo "DB connection error";
    exit;
}

$currentUserId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;

if (!$currentUserId) {
    header("Location: login.php");
    exit;
}

$chatId = isset($_GET['chat_id']) ? intval($_GET['chat_id']) : null;
$targetUserId = isset($_GET['user_id']) ? intval($_GET['user_id']) : null; // Для создания 1-на-1

$chat = null;
$chatName = "Диалог";
$headerLink = "#"; 
$targetUser = null; // Данные собеседника для 1-на-1 чата


// 1. Поиск или определение приватного чата
if ($targetUserId && $targetUserId !== $currentUserId) {
    // Ищем существующий приватный чат
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
        // Если чата нет, просто показываем страницу с именем собеседника
        $stmt = $conn->prepare("SELECT id, username, avatar FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $targetUserId);
        $stmt->execute();
        $targetUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if (!$targetUser) {
            http_response_code(404);
            echo "Пользователь не найден.";
            exit;
        }
        $chatName = htmlspecialchars($targetUser['username']);
        $headerLink = 'profile.php?id=' . $targetUserId;
    }
}


// 2. Загрузка данных чата
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
    
    // Если это групповой чат, берем имя из БД
    $chatName = htmlspecialchars($chat['name'] ?? 'Групповой чат');
    $headerLink = 'messages.php?chat_id=' . $chatId; // Ссылка на самого себя для группового чата (или на info.php)
    
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
             $headerLink = 'profile.php?id=' . $targetUserId;
        }
    }
}


if (!$chatId && !$targetUserId) {
    http_response_code(404);
    echo "Чат или пользователь не найден.";
    exit;
}
?>

<?php include __DIR__ . '/elements/php/header.php'; ?>
    <div class="flex w-full max-w-7xl mx-auto min-h-screen">
        <?php require_once __DIR__ . '/elements/php/sidebar-left.php'; ?>

        <main class="w-full md:w-2/3 lg:w-1/2 border-x border-gray-800 min-h-screen bg-gray-900">
            <header class="sticky top-0 bg-gray-900 bg-opacity-80 backdrop-blur-sm p-4 border-b border-gray-800 z-20 flex items-center">
                <a href="<?= $headerLink ?>" class="flex items-center hover:underline">
                    <?php if (isset($targetUser['avatar'])): ?>
                        <img src="<?= htmlspecialchars($targetUser['avatar']) ?>" class="w-10 h-10 rounded-full mr-3 object-cover" alt="avatar">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-full mr-3 bg-gray-700 flex items-center justify-center text-xl">
                            <?= ($chat && $chat['type'] === 'group') ? '👥' : '💬' ?>
                        </div>
                    <?php endif; ?>
                    <h1 class="text-xl font-bold"><?= $chatName ?></h1>
                </a>
            </header>

            <div class="p-4 flex flex-col-reverse" id="messages-container" style="max-height: calc(100vh - 120px); overflow-y: auto;">
                <p class="text-center text-gray-400">Загрузка сообщений...</p>
            </div>
            
            <div class="sticky bottom-0 bg-gray-900 p-4 border-t border-gray-800">
                <form id="send-message-form" class="flex space-x-2">
                    <input type="hidden" name="action" value="sendMessage">
                    <input type="hidden" name="target_user_id" value="<?= $targetUserId ?? 0 ?>"> 
                    <input type="hidden" name="chat_id" value="<?= $chatId ?? 0 ?>">
                    <input type="text" name="text" placeholder="Ваше сообщение..." class="flex-1 bg-gray-800 p-2 rounded text-white" required>
                    <button type="submit" class="bg-blue-500 px-3 py-1 rounded hover:bg-blue-600 transition-colors">Отправить</button>
                </form>
            </div>

        </main>

        <?php require_once __DIR__ . '/elements/php/sidebar-right.php'; ?>
    </div>
    
    <script>
        const sendMessageForm = document.getElementById('send-message-form');
        const messagesContainer = document.getElementById('messages-container');
        // Получаем chat_id из PHP (может быть 0, если чат еще не создан)
        let currentChatId = <?= $chatId ?? 0 ?>; 

        function scrollToBottom() {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // Загрузка сообщений
        async function loadMessages() {
            if (!currentChatId) { 
                messagesContainer.innerHTML = '<p class="text-center text-gray-400">Начните диалог, отправив первое сообщение.</p>';
                return; 
            }
            
            const fd = new FormData();
            fd.append('action', 'loadMessages');
            fd.append('chat_id', currentChatId);

            try {
                // Отправляем запрос на profile.php, где находится наш AJAX-обработчик
                const res = await fetch('profile.php', {method: 'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                
                if (d && d.success) {
                    // Используем flex-col-reverse, чтобы сообщения отображались снизу
                    messagesContainer.innerHTML = d.messagesHtml;
                    scrollToBottom();
                } else {
                    console.error('Ошибка при загрузке сообщений:', d.error);
                    if (messagesContainer.innerHTML.indexOf('Загрузка') !== -1) {
                         messagesContainer.innerHTML = '<p class="text-center text-red-400">Ошибка загрузки сообщений. ' + (d.error || '') + '</p>';
                    }
                }
            } catch (err) {
                console.error('Ошибка сети при загрузке сообщений:', err);
            }
        }
        
        // Отправка сообщения
        sendMessageForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const textInput = this.querySelector('input[name=text]');
            const text = textInput.value.trim();
            if (!text) return;
            
            const fd = new FormData(this);
            
            textInput.disabled = true;
            
            try {
                // Отправляем запрос на profile.php
                const res = await fetch('profile.php', {method: 'POST', body: fd, credentials: 'same-origin'});
                const d = await res.json();
                
                if (d && d.success) {
                    // Если чат только что создался, обновляем ID
                    if (currentChatId === 0 && d.chatId) {
                        currentChatId = d.chatId;
                        // Обновляем скрытое поле в форме
                        this.querySelector('input[name=chat_id]').value = currentChatId;
                    }
                    
                    // Добавляем новое сообщение (вручную, чтобы не ждать loadMessages)
                    const myMessageHtml = `
                        <div class='flex w-full mb-2 justify-end'>
                            <div class='max-w-xs lg:max-w-md p-3 rounded-xl bg-blue-600 text-white'>
                                <p class='text-xs font-semibold text-gray-300 mb-1'>Я (${new Date().toLocaleTimeString('ru-RU', {hour: '2-digit', minute: '2-digit'})})</p>
                                <p>${d.messageText.replace(/\n/g, '<br>')}</p>
                            </div>
                        </div>
                    `;
                    // Используем insertAdjacentHTML, чтобы добавить элемент в конец (низ) контейнера
                    messagesContainer.insertAdjacentHTML('afterbegin', myMessageHtml); 
                    textInput.value = ''; // Очищаем поле
                    scrollToBottom();
                    
                } else {
                    alert('Ошибка: ' + (d.error || 'Неизвестная ошибка'));
                }
            } catch (err) {
                console.error('Ошибка сети при отправке сообщения:', err);
                alert('Ошибка сети при отправке сообщения');
            } finally {
                textInput.disabled = false;
            }
        });

        // Инициализация загрузки
        loadMessages();
        
        // Обновляем сообщения каждые 5 секунд
        setInterval(loadMessages, 5000); 

    </script>
<?php include __DIR__ . '/elements/php/footer.php'; ?>