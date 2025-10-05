<?php
// Подключение к базе
require_once "elements/php/db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirm = $_POST["confirm"];

    if ($password !== $confirm) {
        $message = "Пароли не совпадают!";
    } else {
        // Проверим, нет ли такого пользователя
        $stmt = $conn->prepare("SELECT id FROM users WHERE email=? OR username=? LIMIT 1");
        $stmt->bind_param("ss", $email, $username);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 0) {
            $message = "Такой email или имя уже зарегистрированы!";
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            $stmt->bind_param("sss", $username, $email, $hash);
            if ($stmt->execute()) {
                header("Location: login.php?registered=1");
                exit;
            } else {
                $message = "Ошибка при регистрации. Попробуйте позже.";
            }
        }
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="ru" class="bg-gray-900 text-gray-100">
<head>
    <meta charset="UTF-8">
    <title>Регистрация в Meetlook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen flex flex-col items-center">
    <main class="w-full md:w-2/3 lg:w-1/2 min-h-screen flex items-center justify-center">
        <div class="bg-gray-800 p-8 rounded-2xl shadow-lg w-full max-w-md">
            <h2 class="text-3xl font-bold text-center mb-6">Создать аккаунт</h2>

            <?php if (!empty($message)): ?>
                <div class="bg-red-500 text-white p-3 rounded mb-4 text-center">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4">
                <div>
                    <input type="text" name="username" placeholder="Имя пользователя" required
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <input type="email" name="email" placeholder="Электронная почта" required
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <input type="password" name="password" placeholder="Пароль" required
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <input type="password" name="confirm" placeholder="Повторите пароль" required
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <button type="submit"
                    class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-3 rounded-full">
                    Зарегистрироваться
                </button>
            </form>

            <p class="text-center mt-4 text-sm text-gray-400">
                Уже есть аккаунт? <a href="login.php" class="text-blue-500 hover:underline">Войти</a>
            </p>
        </div>
    </main>
</body>
</html>
