<?php
/**
 * topic_detector_class.php
 * Класс для классификации текста с помощью внешнего AI API.
 */

class EmbeddedTopicClassifier
{
    // !!! Ключевой момент: Убедитесь, что этот адрес совпадает с адресом вашего Python API
    private const API_URL = 'http://127.0.0.1:8000/classify_post/';
    
    /**
     * Определяет тематику переданного текста, отправляя его на внешний API.
     * @param string $postText Текст для анализа.
     * @param int $topK Количество топовых тем для возврата (по умолчанию 3).
     * @return array|null Массив с темами и оценками: [ ['topic' => 'TopicName', 'score' => 0.85], ... ] 
     * Возвращает null или ['error' => '...'] в случае ошибки.
     */
    public static function classify(string $postText, int $topK = 3): ?array
    {
        // Проверка на пустой текст
        if (empty(trim($postText))) {
            return ['error' => 'Текст для классификации не может быть пустым.'];
        }
        
        // Проверяем наличие cURL
        if (!function_exists('curl_init')) {
            error_log('cURL is not installed on this PHP environment.');
            return ['error' => 'Требуется PHP-расширение cURL.'];
        }

        $data = [
            'text' => $postText,
            'top_k' => $topK 
        ];

        // 1. Инициализация cURL
        $ch = curl_init(self::API_URL);
        $jsonPayload = json_encode($data);

        // 2. Настройки cURL
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);

        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($jsonPayload)
        ]);

        // 3. Выполнение запроса
        $response = curl_exec($ch);
        $error_msg = curl_error($ch);
        
        // 4. Обработка ошибок cURL
        if ($error_msg) {
            error_log("Classifier API Error (cURL): {$error_msg}");
            curl_close($ch);
            return ['error' => "Ошибка соединения с API: {$error_msg}"];
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // 5. Обработка ошибок HTTP
        if ($httpCode !== 200) {
            error_log("Classifier API returned HTTP {$httpCode}. Response: {$response}");
            return ['error' => "API вернуло ошибку: HTTP {$httpCode}. Проверьте доступность сервера."];
        }

        // 6. Декодирование и форматирование ответа
        $results = json_decode($response, true);
        
        if (!is_array($results)) {
            error_log('Classifier API Error: Invalid JSON response.');
            return ['error' => 'API вернуло некорректный JSON ответ.'];
        }

        // Форматируем ответ в удобный массив ключ/значение
        $formattedResults = [];
        foreach ($results as $item) {
            // Проверка формата: [Тема, Оценка]
            if (count($item) === 2 && is_string($item[0]) && is_numeric($item[1])) {
                $formattedResults[] = [
                    'topic' => $item[0], 
                    'score' => (float)$item[1]
                ];
            }
        }
        
        return $formattedResults;
    }
}