<?php
session_start();
require_once "elements/php/db.php";

$message = "";

// Если пользователь уже вошёл
if (isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    $stmt = $conn->prepare("SELECT id, username, password FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 1) {
        $stmt->bind_result($id, $username, $hash);
        $stmt->fetch();

        if (password_verify($password, $hash)) {
            // Успешный вход
            $_SESSION["user_id"] = $id;
            $_SESSION["username"] = $username;
            header("Location: index.php");
            exit;
        } else {
            $message = "Неверный пароль!";
        }
    } else {
        $message = "Пользователь с таким email не найден!";
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="ru" class="bg-gray-900 text-gray-100">
<head>
    <meta charset="UTF-8">
    <title>Вход в Meetlook</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="min-h-screen flex flex-col items-center">
    <main class="w-full md:w-2/3 lg:w-1/2 min-h-screen flex items-center justify-center">
        <div class="bg-gray-800 p-8 rounded-2xl shadow-lg w-full max-w-md">
            <h2 class="text-3xl font-bold text-center mb-6">Вход в Meetlook</h2>

            <?php if (!empty($message)): ?>
                <div class="bg-red-500 text-white p-3 rounded mb-4 text-center">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="space-y-4">
                <div>
                    <input type="email" name="email" placeholder="Электронная почта" required
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <input type="password" name="password" placeholder="Пароль" required
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                <button type="submit"
                    class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-3 rounded-full">
                    Войти
                </button>
            </form>

            <p class="text-center mt-4 text-sm text-gray-400">
                Ещё нет аккаунта? <a href="register.php" class="text-blue-500 hover:underline">Зарегистрироваться</a>
            </p>
        </div>
    </main>
</body>
</html>
