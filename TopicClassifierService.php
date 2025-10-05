<?php
// elements/php/TopicClassifierService.php

class TopicClassifierService {
    // ВАЖНО: API Key оставляем пустым, как того требует среда Canvas.
    private $apiKey = ""; 
    // Используем модель для быстрого текстового анализа
    private $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-preview-05-20:generateContent";

    /**
     * Классифицирует текст, вызывая внешний API-сервис.
     * * @param string $text Текст поста для классификации.
     * @param int $topK Количество тем для возврата (мы используем 1).
     * @return array Массив, содержащий тему (topic) и оценку (score), или ошибку.
     */
    public function classifyPost(string $text, int $topK = 1): array {
        if (empty($text)) {
            return ['error' => 'Текст пуст.'];
        }

        // Список тем для классификации. Вы можете настроить его под ваши нужды.
        $topicList = [
            "Politics", "Technology", "Life", "Sports", "Science", 
            "Art", "Gaming", "Finance", "Food", "Travel", "History"
        ];
        $topicListString = implode(', ', $topicList);

        // Инструкция для модели (System Instruction)
        $systemPrompt = "Act as an expert content tagger. Analyze the core subject matter of the input text and return the single most relevant topic from the following list: {$topicListString}. Respond ONLY with a JSON object containing the determined topic under the key 'topic' and a confidence score (from 0.0 to 1.0) under the key 'score'. Do not include any other text, explanation, or markdown formatting.";

        $userQuery = "Classify this text: \"{$text}\"";

        // Формирование Payload для API
        $payload = [
            'contents' => [['parts' => [['text' => $userQuery]]]],
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            // Запрашиваем структурированный JSON-ответ
            'generationConfig' => [
                'responseMimeType' => "application/json",
                'responseSchema' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'topic' => ['type' => 'STRING', 'enum' => $topicList],
                        'score' => ['type' => 'NUMBER', 'description' => 'Confidence score between 0.0 and 1.0']
                    ],
                    'required' => ['topic', 'score']
                ]
            ]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->apiUrl . "?key=" . $this->apiKey);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        // Механизм повторных попыток (Exponential Backoff)
        $maxRetries = 3;
        $delay = 1;

        for ($i = 0; $i < $maxRetries; $i++) {
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if ($httpCode === 200 && $response !== false) {
                curl_close($ch);
                break;
            }

            error_log("API call failed (Attempt " . ($i + 1) . "): HTTP Code " . $httpCode . ", Response: " . $response);
            if ($i < $maxRetries - 1) {
                sleep($delay);
                $delay *= 2; 
            } else {
                curl_close($ch);
                return ['error' => 'Не удалось получить классификацию после нескольких попыток.'];
            }
        }
        
        $result = json_decode($response, true);

        // Извлечение JSON строки из ответа модели
        $jsonString = $result['candidates'][0]['content']['parts'][0]['text'] ?? null;
        
        if ($jsonString) {
            $parsedJson = json_decode($jsonString, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($parsedJson['topic'])) {
                // Возвращаем главную тему в виде массива, который ожидает feed.php
                return [['topic' => $parsedJson['topic'], 'score' => $parsedJson['score'] ?? 1.0]];
            }
        }

        return ['error' => 'Некорректный ответ от классификатора.'];
    }
}
