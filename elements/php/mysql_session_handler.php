<?php
// elements/php/mysql_session_handler.php

/**
 * Класс для управления сессиями через базу данных MySQL.
 * * Реализует интерфейс SessionHandlerInterface для переопределения
 * стандартных методов работы с сессиями (чтение, запись, удаление и т.д.).
 */
class MySQLSessionHandler implements SessionHandlerInterface {
    
    private mysqli $conn;
    
    // Время жизни сессии (в секундах). Берется из настроек PHP (session.gc_maxlifetime)
    private int $maxlifetime;

    /**
     * Конструктор, принимающий активное подключение к базе данных.
     * * @param mysqli $conn Активное подключение mysqli.
     */
    public function __construct(mysqli $conn) {
        $this->conn = $conn;
        // Получаем максимальное время жизни сессии из настроек PHP
        $this->maxlifetime = (int)ini_get('session.gc_maxlifetime');
    }

    /**
     * Открывает сессию.
     * @param string $path Путь к сохранению (не используется в MySQL).
     * @param string $name Имя сессии (не используется в MySQL).
     * @return bool Всегда true.
     */
    public function open(string $path, string $name): bool {
        return true;
    }

    /**
     * Закрывает сессию.
     * @return bool Всегда true.
     */
    public function close(): bool {
        return true;
    }

    /**
     * Читает данные сессии из базы.
     * @param string $id ID сессии.
     * @return string Сериализованные данные сессии или пустая строка.
     */
    public function read(string $id): string {
        $stmt = $this->conn->prepare("SELECT data FROM sessions WHERE id = ? AND access > ?");
        // Проверяем, что сессия не истекла
        $time = time(); 
        $stmt->bind_param('si', $id, $time);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result && $row = $result->fetch_assoc()) {
            $stmt->close();
            return $row['data'];
        }

        $stmt->close();
        return "";
    }

    /**
     * Записывает данные сессии в базу или обновляет существующую запись.
     * @param string $id ID сессии.
     * @param string $data Сериализованные данные сессии.
     * @return bool true в случае успеха, false в противном случае.
     */
    public function write(string $id, string $data): bool {
        $access = time() + $this->maxlifetime;
        
        // ВАЖНО: $_SESSION['user_id'] может быть не установлен или быть null.
        // Используем оператор объединения с null для безопасного получения user_id.
        $user_id = $_SESSION['user_id'] ?? null;
        
        // Используем ON DUPLICATE KEY UPDATE для атомарной записи/обновления
        $stmt = $this->conn->prepare("INSERT INTO sessions (id, user_id, access, data) 
                                      VALUES (?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE 
                                      user_id = VALUES(user_id), access = VALUES(access), data = VALUES(data)");
        
        // Определяем тип параметра для user_id
        $types = 'siss'; // id(string), user_id(int), access(int), data(string)
        
        // bind_param требует, чтобы переменные передавались по ссылке.
        $stmt->bind_param($types, $id, $user_id, $access, $data);
        
        $success = $stmt->execute();
        $stmt->close();
        
        return $success;
    }

    /**
     * Уничтожает (удаляет) сессию.
     * @param string $id ID сессии.
     * @return bool true в случае успеха, false в противном случае.
     */
    public function destroy(string $id): bool {
        $stmt = $this->conn->prepare("DELETE FROM sessions WHERE id = ?");
        $stmt->bind_param('s', $id);
        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }

    /**
     * Запускает сборщик мусора: удаляет просроченные сессии.
     * @param int $max_lifetime Максимальное время жизни сессии (не используется, берем из класса).
     * @return int|bool Количество удаленных строк или false в случае ошибки.
     */
    public function gc(int $max_lifetime): int|bool {
        $time = time();
        // Удаляем все записи, у которых время access меньше текущего времени
        $stmt = $this->conn->prepare("DELETE FROM sessions WHERE access < ?");
        $stmt->bind_param('i', $time);
        
        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            return $affected_rows;
        }
        
        $stmt->close();
        return false;
    }
}
