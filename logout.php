<?php
session_start(); // Запускаем сессию

// Очищаем все переменные сессии
$_SESSION = [];

// Уничтожаем сессию
session_destroy();

// Удаляем cookie сессии, если нужно
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Перенаправляем пользователя на страницу входа
header("Location: index.php");
exit;
?>
