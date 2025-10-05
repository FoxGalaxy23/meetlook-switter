<?php
// message_ajax.php - Автономный обработчик AJAX для сообщений и длительного опроса

session_start();
require_once __DIR__ . "/elements/php/db.php"; 

// Установим лимит выполнения скрипта для Long Polling (например, 30 секунд)
set_time_limit(35); 
header('Content-Type: application/json');

if (!$conn) { /* ... (обработка ошибок) ... */ }

$currentUserId = isset($_SESSION['user_id']) ? intval($_SESSION['user_id']) : null;
$action = $_POST['action'] ?? null;

if (!$currentUserId) { /* ... (обработка ошибок) ... */ }
if (!$action) { /* ... (обработка ошибок) ... */ }

/* ----------------------------------------------------
   --- ОБРАБОТЧИК ОТПРАВКИ СООБЩЕНИЯ (sendMessage) ---
---------------------------------------------------- */
if ($action === 'sendMessage') {
    $text = trim($_POST['text'] ?? "");
    if ($text === "") { echo json_encode(["success"=>false, "error"=>"empty_message"]); exit; }

    $chatId = intval($_POST['chat_id'] ?? 0) ?: null;
    if (!$chatId) { echo json_encode(["success"=>false, "error"=>"chat_id_missing"]); exit; }
    
    $conn->begin_transaction(); 

    try {
        // ... (Проверка участия в чате - код не меняется) ...
        $stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param("ii", $chatId, $currentUserId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows === 0) { throw new Exception("User not a participant"); }
        $stmt->close();

        // 2. Отправляем сообщение
        $stmt = $conn->prepare("INSERT INTO messages (chat_id, sender_id, text, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->bind_param("iis", $chatId, $currentUserId, $text);
        $dbSuccess = $stmt->execute();
        $newMessageId = $stmt->insert_id; // Получаем ID нового сообщения
        $stmt->close();

        if ($dbSuccess) {
            // 3. Обновляем время последнего сообщения в чате
            $stmt = $conn->prepare("UPDATE chats SET last_message_at = NOW() WHERE id = ?");
            $stmt->bind_param("i", $chatId);
            $stmt->execute();
            $stmt->close();
            
            $conn->commit();
            // Возвращаем ID, чтобы клиент знал, с какого сообщения продолжать опрос
            echo json_encode(["success"=>true, "chatId"=>$chatId, "messageText"=>htmlspecialchars($text), "messageId"=>$newMessageId]);
            exit;
        } else {
            throw new Exception("Failed to insert message");
        }

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["success"=>false, "error"=>"Transaction failed: " . $e->getMessage()]); 
        exit;
    }
}


/* ----------------------------------------------------
   --- ОБРАБОТЧИК ЗАГРУЗКИ НОВЫХ СООБЩЕНИЙ (Long Polling) ---
---------------------------------------------------- */
if ($action === 'pollForNewMessages') {
    $chatId = intval($_POST['chat_id'] ?? 0);
    $lastMessageId = intval($_POST['last_message_id'] ?? 0); // ID последнего сообщения, которое видел клиент
    $timeout = 25; // Максимальное время ожидания (в секундах)
    $startTime = time();

    // 1. Проверяем доступ (код не меняется)
    $stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $chatId, $currentUserId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(["success"=>false, "error"=>"no_access"]);
        exit;
    }
    $stmt->close();

    // 2. Основной цикл ожидания
    $messages = [];
    while (time() - $startTime < $timeout) {
        $stmt = $conn->prepare("SELECT m.id, m.text, m.sender_id, m.created_at, u.username 
                                FROM messages m 
                                JOIN users u ON m.sender_id = u.id
                                WHERE m.chat_id = ? AND m.id > ?
                                ORDER BY m.created_at ASC 
                                LIMIT 50");
        $stmt->bind_param("ii", $chatId, $lastMessageId);
        $stmt->execute();
        $messages = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        if (!empty($messages)) {
            break; // Сообщения найдены, выходим из цикла
        }

        // Ждем 500 миллисекунд перед следующей проверкой
        usleep(500000); 
    }
    
    // 3. Форматируем и отправляем
    $html = '';
    $newLastMessageId = $lastMessageId;
    
    if (!empty($messages)) {
        foreach ($messages as $msg) {
            $isSender = intval($msg['sender_id']) === $currentUserId;
            $bubbleClass = $isSender ? 'bg-blue-600 self-end' : 'bg-gray-700 self-start';
            $alignmentClass = $isSender ? 'justify-end' : 'justify-start';
            $senderName = $isSender ? 'Я' : htmlspecialchars($msg['username']);
            $time = (new DateTime($msg['created_at']))->format('H:i');
            $text = nl2br(htmlspecialchars($msg['text']));
            
            // Сообщения будут добавляться в HTML в хронологическом порядке
            $html .= "<div class='flex w-full mb-2 {$alignmentClass}'>
                        <div class='max-w-xs lg:max-w-md p-3 rounded-xl {$bubbleClass} text-white'>
                            <p class='text-xs font-semibold text-gray-300 mb-1'>{$senderName} ({$time})</p>
                            <p>{$text}</p>
                        </div>
                      </div>";
            $newLastMessageId = intval($msg['id']);
        }
    }

    echo json_encode([
        "success" => true, 
        "messagesHtml" => $html,
        // Обязательно возвращаем ID последнего увиденного сообщения
        "lastMessageId" => $newLastMessageId 
    ]);
    exit;
}

/* ----------------------------------------------------
   --- ОБРАБОТЧИК ЗАГРУЗКИ ИЗНАЧАЛЬНЫХ СООБЩЕНИЙ ---
---------------------------------------------------- */
if ($action === 'loadInitialMessages') {
    $chatId = intval($_POST['chat_id'] ?? 0);
    
    // ... (Проверка доступа - код не меняется) ...
    $stmt = $conn->prepare("SELECT 1 FROM chat_participants WHERE chat_id = ? AND user_id = ? LIMIT 1");
    $stmt->bind_param("ii", $chatId, $currentUserId);
    $stmt->execute();
    if ($stmt->get_result()->num_rows === 0) {
        echo json_encode(["success"=>false, "error"=>"no_access"]);
        exit;
    }
    $stmt->close();
    
    // Загружаем последние 50 сообщений (самые новые вверху)
    $stmt = $conn->prepare("SELECT m.id, m.text, m.sender_id, m.created_at, u.username
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
    $lastMessageId = 0;

    // Сортировка: Нам нужно, чтобы самый первый элемент в массиве был самым старым из 50.
    // Мы загрузили DESC (новые сверху), теперь разворачиваем, чтобы новые были в конце.
    $messages = array_reverse($messages); 
    
    foreach ($messages as $msg) {
        // ... (Форматирование HTML - код не меняется) ...
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
        $lastMessageId = intval($msg['id']); // Сохраняем ID самого нового из 50 загруженных
    }

    echo json_encode(["success"=>true, "messagesHtml"=>$html, "lastMessageId" => $lastMessageId]);
    exit;
}

echo json_encode(['success' => false, 'error' => 'unknown_action']);
exit;