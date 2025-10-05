<?php
// 1. Подключение класса (если он в отдельном файле)
require_once 'embedded.php'; 

// 2. Использование
$newText = "Ученые обнаружили новую экзопланету, пригодную для жизни.";
$themes = EmbeddedTopicClassifier::classify($newText, 2);

if (!isset($themes['error'])) {
    // Успех! $themes содержит массив результатов.
    echo "Главная тема: " . $themes[0]['topic'];
}
?>