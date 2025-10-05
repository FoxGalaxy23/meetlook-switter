<?php
$host = "localhost";
$user = "root";   // свой логин
$pass = "";       // пароль
$db   = "switter"; // имя БД

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}
?>
