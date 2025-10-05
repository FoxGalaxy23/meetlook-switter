<?php
/**
 * index.php
 * Интерфейс для Детектора Темы Поста, использующий класс EmbeddedTopicClassifier.
 */

// Подключаем класс, который умеет общаться с внешним API
include 'embedded.php'; // !!! Убедитесь, что имя файла совпадает

// =================================================================
// 1. ЛОГИКА ОБРАБОТКИ ФОРМЫ
// =================================================================
$topicResults = null;
$textToAnalyze = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['text_to_classify'])) {
    $textToAnalyze = trim($_POST['text_to_classify']);
    // Получаем top_k из формы, по умолчанию 3
    $topK = (int)($_POST['top_k'] ?? 3); 
    
    // Запускаем классификацию через подключенный класс
    $topicResults = EmbeddedTopicClassifier::classify($textToAnalyze, $topK);
}

// =================================================================
// 2. HTML-ИНТЕРФЕЙС
// =================================================================
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>AI Детектор Темы</title>
</head>
<body>

<div style="border: 1px solid #ccc; padding: 15px; border-radius: 5px; max-width: 600px; margin: 20px auto; font-family: sans-serif;">
    <h3 style="margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 10px;">Детектор Темы Поста (AI) 🤖</h3>

    <form method="POST" action="">
        <label for="text_to_classify" style="display: block; margin-bottom: 5px; font-weight: bold;">Введите текст для анализа:</label>
        <textarea 
            name="text_to_classify" 
            id="text_to_classify" 
            rows="5" 
            style="width: 98%; padding: 10px; border: 1px solid #ddd; border-radius: 3px; resize: vertical;" 
            placeholder="Например: Новая презентация Apple показала невероятный чип M4 для ИИ вычислений."
            required
        ><?php echo htmlspecialchars($textToAnalyze); ?></textarea>
        
        <label for="top_k" style="display: block; margin-top: 10px; margin-bottom: 5px;">Количество тем (Top K):</label>
        <input type="number" name="top_k" id="top_k" value="<?php echo isset($_POST['top_k']) ? (int)$_POST['top_k'] : 3; ?>" min="1" max="10" style="padding: 5px; border: 1px solid #ddd; border-radius: 3px; width: 60px;">

        <button type="submit" style="background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 3px; cursor: pointer; margin-top: 15px;">
            Определить Тему
        </button>
    </form>

    <?php if ($topicResults !== null): ?>
        <div style="margin-top: 20px; padding-top: 10px; border-top: 1px solid #eee;">
            <?php if (isset($topicResults['error'])): ?>
                <p style="color: red; font-weight: bold;">❌ Ошибка: <?php echo htmlspecialchars($topicResults['error']); ?></p>
                <p style="font-size: small; color: #666;">Убедитесь, что Python API запущен и доступен по адресу: <?php echo EmbeddedTopicClassifier::API_URL; ?></p>
            <?php elseif (empty($topicResults)): ?>
                 <p style="color: orange; font-weight: bold;">⚠️ Не удалось классифицировать текст или получена пустая тема.</p>
            <?php else: ?>
                <h4 style="margin-top: 0;">✅ Результаты Классификации:</h4>
                <ul style="list-style-type: none; padding: 0;">
                    <?php foreach ($topicResults as $item): ?>
                        <?php $scorePercent = number_format($item['score'] * 100, 2) . '%'; ?>
                        <li style="margin-bottom: 5px; padding: 5px; background-color: #f9f9f9; border-left: 5px solid #007bff;">
                            <strong>Тема:</strong> <?php echo htmlspecialchars($item['topic']); ?> 
                            <span style="float: right; font-weight: bold; color: #28a745;"><?php echo $scorePercent; ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

</body>
</html>