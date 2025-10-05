<?php
/**
 * TopicClassifier.php
 * Класс для классификации текста с помощью внешнего AI API.
 */

class EmbeddedTopicClassifier
{
    // !!! Ключевой момент: Убедитесь, что этот адрес совпадает с адресом вашего Python API
    private const API_URL = 'http://127.0.0.1:8000/classify_post/';
    
    /**
     * Определяет наиболее вероятную тематику переданного текста, отправляя его на внешний API.
     * @param string $postText Текст для анализа.
     * @return string|null Возвращает строку с названием темы (Top-1) или null в случае ошибки.
     */
    public static function classify(string $postText): ?string
    {
        // Проверка на пустой текст
        if (empty(trim($postText))) {
            return null;
        }
        
        // Проверяем наличие cURL
        if (!function_exists('curl_init')) {
            error_log('Требуется PHP-расширение cURL для вызова AI API.');
            return null;
        }

        $data = [
            'text' => $postText,
            'top_k' => 1 // Нам нужна только одна, самая вероятная тема
        ];

        // 1. Инициализация cURL
        $ch = curl_init(self::API_URL);
        $jsonPayload = json_encode($data);

        // 2. Настройки cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        // Устанавливаем таймаут, чтобы не блокировать страницу слишком долго
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload)
        ]);

        // 3. Выполнение запроса
        $response = curl_exec($ch);
        $error_msg = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // 4. Обработка ошибок
        if ($error_msg || $httpCode !== 200) {
            error_log("Ошибка AI API (HTTP: {$httpCode}, cURL: {$error_msg}) при классификации текста: {$postText}");
            return null;
        }

        // 5. Декодирование и извлечение Top-1 темы
        $results = json_decode($response, true);
        
        // Ожидаем формат: [ ["Тема", 0.85] ]
        if (is_array($results) && isset($results[0]) && is_array($results[0]) && count($results[0]) === 2) {
            $topic = $results[0][0];
            // Возвращаем только название темы
            return is_string($topic) ? $topic : null;
        }
        
        return null; // Тема не определена
    }
}