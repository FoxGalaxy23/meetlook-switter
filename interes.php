<?php
// =========================================================================
// 1. СТАРТОВЫЙ БЛОК (Ваш код)
// =========================================================================
session_start();
require_once "elements/php/db.php"; // Предполагается, что ваш db.php находится здесь
require_once "ai.php"; // Предполагается, что ai.php содержит что-то важное

// Проверка авторизации
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Текущий пользователь
$currentUserId = intval($_SESSION['user_id']);

// Проверка подключения
if (!isset($conn) || $conn->connect_error) {
    // В случае ошибки подключения, прерываем выполнение
    die("Ошибка подключения к БД. Проверьте файл db.php.");
}


// =========================================================================
// 2. ЗАПРОС И ПОДГОТОВКА ДАННЫХ
// =========================================================================
$data_for_chart = [['Тема', 'Ваши интересы']]; // Заголовок для Google Charts
$username = 'Пользователь';

try {
    // 2.1. Получаем имя пользователя
    $stmt_user = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $stmt_user->bind_param("i", $currentUserId);
    $stmt_user->execute();
    $stmt_user->bind_result($temp_username);
    $stmt_user->fetch();
    $stmt_user->close();
    
    if ($temp_username) {
        $username = $temp_username;
    } else {
        // Если ID есть в сессии, но пользователя нет в БД — это ошибка.
        die("Пользователь не найден."); 
    }

    // 2.2. Запрос на получение интересов пользователя
    // Каждая найденная запись = 1 "единица" интереса для диаграммы
    $sql = "
        SELECT 
            topic
        FROM 
            user_interests
        WHERE
            user_id = ?
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $currentUserId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            // Присваиваем каждой теме вес 1 для равных долей в пироге
            $data_for_chart[] = [$row['topic'], 1]; 
        }
    }
    
    $stmt->close();
    
    // Преобразуем массив PHP в формат JSON
    $json_data = json_encode($data_for_chart);

} catch (\Exception $e) {
    // В случае ошибки запроса
    die("Ошибка при получении данных об интересах: " . $e->getMessage());
} finally {
    // Закрываем соединение с БД
    if (isset($conn)) {
        $conn->close();
    }
}
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Мой Пирог Интересов</title>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script type="text/javascript">
        // Загрузка библиотеки "Pie Chart"
        google.charts.load('current', {'packages':['corechart']});
        google.charts.setOnLoadCallback(drawChart);

        function drawChart() {
            var data = google.visualization.arrayToDataTable(
                <?php echo $json_data; ?>
            );

            // Настройки графика
            var options = {
                title: 'Мой Пирог Интересов: <?php echo addslashes($username); ?>',
                is3D: true, 
                legend: { position: 'right' },
                titleTextStyle: { fontSize: 18 }
            };
            
            // Если данных нет
            if (data.getNumberOfRows() <= 1) { 
                document.getElementById('piechart').innerHTML = '<h2>Вы ещё не добавили ни одного интереса!</h2>';
                return;
            }

            var chart = new google.visualization.PieChart(document.getElementById('piechart'));
            chart.draw(data, options);
        }
    </script>
    <style>
        body { font-family: sans-serif; padding: 20px; }
        h1 { color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 5px; }
    </style>
</head>
<body>

    <h1>Пирог Интересов для <span style="color:#3498db;"><?php echo htmlspecialchars($username); ?></span></h1>
    <p>Процентное соотношение тематик, которые Вы отметили как интересные.</p>

    <div id="piechart" style="width: 900px; height: 500px;"></div>

</body>
</html>