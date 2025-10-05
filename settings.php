<?php
session_start();
require_once "elements/php/db.php";

// Проверяем, вошёл ли пользователь. Если нет, отправляем его на страницу входа.
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

$message = "";
$userId = $_SESSION["user_id"];

// 1. Обновлено: Добавлены поля bio и banner в SELECT
$stmt = $conn->prepare("SELECT username, email, avatar, bio, banner FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();
$stmt->close();

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Обработка данных из формы
    if (isset($_POST["update_profile"])) {
        $newUsername = trim($_POST["username"]);
        $newEmail = trim($_POST["email"]);
        $newPassword = $_POST["password"];
        // Новое: Поле для биографии
        $newBio = trim($_POST["bio"] ?? '');
        
        $avatarChanged = false;
        $bannerChanged = false;
        $uploadDir = 'uploads/users/'; // Общая папка для аватаров и баннеров

        // Создаём общую папку, если её нет
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Вспомогательная функция для загрузки файла
        $uploadFile = function ($fileArray, $fieldName, $oldPath, $userId) use ($uploadDir, &$message) {
            if (isset($fileArray[$fieldName]) && $fileArray[$fieldName]["error"] === 0) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
                $fileType = $fileArray[$fieldName]['type'];
                
                if (in_array($fileType, $allowedTypes)) {
                    // Создание уникального имени файла (user_ID-тип-уникальный ID)
                    $extension = pathinfo($fileArray[$fieldName]["name"], PATHINFO_EXTENSION);
                    $fileName = $userId . '-' . $fieldName . '-' . uniqid() . '.' . $extension;
                    $filePath = $uploadDir . $fileName;

                    if (move_uploaded_file($fileArray[$fieldName]["tmp_name"], $filePath)) {
                        // Удаляем старый файл, если он есть
                        if ($oldPath && file_exists($oldPath)) {
                            unlink($oldPath);
                        }
                        return $filePath;
                    } else {
                        $message .= "Ошибка при загрузке {$fieldName}. ";
                    }
                } else {
                    $message .= "Неверный тип файла для {$fieldName}. Разрешены только JPG, PNG и GIF. ";
                }
            }
            return null;
        };


        // Обновление аватара (логика упрощена с использованием новой функции)
        $newAvatarPath = $uploadFile($_FILES, 'avatar', $user['avatar'], $userId);
        if ($newAvatarPath !== null) {
            $user['avatar'] = $newAvatarPath;
            $avatarChanged = true;
        }

        // Новое: Обновление баннера
        $newBannerPath = $uploadFile($_FILES, 'banner', $user['banner'], $userId);
        if ($newBannerPath !== null) {
            $user['banner'] = $newBannerPath;
            $bannerChanged = true;
        }


        // Подготовка SQL-запроса: добавляем 'bio' и проверяем 'avatar'/'banner'
        $sql = "UPDATE users SET username = ?, email = ?, bio = ?";
        $params = "sss";
        $values = [$newUsername, $newEmail, $newBio]; // Включаем bio

        if (!empty($newPassword)) {
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $sql .= ", password = ?";
            $params .= "s";
            $values[] = $hashedPassword;
        }

        if ($avatarChanged) {
            $sql .= ", avatar = ?";
            $params .= "s";
            $values[] = $user['avatar'];
        }
        
        // Новое: Добавляем banner в запрос
        if ($bannerChanged) {
            $sql .= ", banner = ?";
            $params .= "s";
            $values[] = $user['banner'];
        }

        $sql .= " WHERE id = ?";
        $params .= "i";
        $values[] = $userId;

        $stmt = $conn->prepare($sql);
        // Оператор spread (...) для передачи массива значений
        if ($stmt && $stmt->bind_param($params, ...$values)) {
            if ($stmt->execute()) {
                $message .= "Данные обновлены!";
                // Обновляем ник в сессии
                $_SESSION['username'] = $newUsername;
                // После успешного обновления перечитываем данные, чтобы форма показывала актуальные значения
                $user = array_merge($user, ['username' => $newUsername, 'email' => $newEmail, 'bio' => $newBio]);
            } else {
                $message .= "Ошибка при обновлении данных: " . $stmt->error;
            }
            $stmt->close();
        } else {
            $message .= "Ошибка подготовки запроса.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ru" class="bg-gray-900 text-gray-100">
<head>
    <meta charset="UTF-8">
    <title>Настройки аккаунта</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .file-input {
            border: 1px solid #374151; /* gray-700 */
            border-radius: 0.5rem;
            padding: 0.75rem 1rem;
            display: block;
            width: 100%;
            background-color: #1f2937; /* gray-800 */
        }
        .file-input:hover {
            border-color: #60a5fa; /* blue-400 */
        }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center">
    <main class="w-full md:w-2/3 lg:w-1/2 min-h-screen flex items-center justify-center">
        <div class="bg-gray-800 p-8 rounded-2xl shadow-lg w-full max-w-md">
            <h2 class="text-3xl font-bold text-center mb-6">Настройки аккаунта</h2>

            <?php if (!empty($message)): ?>
                <div class="bg-blue-500 text-white p-3 rounded mb-4 text-center">
                    <?= htmlspecialchars($message) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" class="space-y-4">
                
                <div>
                    <label class="block text-sm font-medium mb-1">Баннер профиля</label>
                    <?php if ($user['banner']): ?>
                        <div class="relative mb-2">
                            <img src="<?= htmlspecialchars($user['banner']) ?>" alt="Баннер пользователя" class="w-full h-24 object-cover rounded-lg">
                        </div>
                    <?php endif; ?>
                    <input type="file" name="banner" accept="image/jpeg,image/png,image/gif" class="file-input text-gray-400"/>
                </div>

                <div class="flex flex-col items-center mb-4 pt-4 border-t border-gray-700">
                    <label class="block text-sm font-medium mb-1">Аватар</label>
                    <?php if ($user['avatar']): ?>
                        <img src="<?= htmlspecialchars($user['avatar']) ?>" alt="Аватар пользователя" class="w-24 h-24 rounded-full object-cover mb-2">
                    <?php else: ?>
                        <div class="w-24 h-24 rounded-full bg-gray-700 flex items-center justify-center mb-2">
                            <span class="text-xl">👤</span>
                        </div>
                    <?php endif; ?>
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif" class="file-input text-gray-400"/>
                </div>

                <div>
                    <input type="text" name="username" placeholder="Имя пользователя" value="<?= htmlspecialchars($user['username']) ?>" required
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>
                
                <div>
                    <input type="email" name="email" placeholder="Электронная почта" value="<?= htmlspecialchars($user['email']) ?>" required
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label for="bio" class="block text-sm font-medium mb-1">О себе (Биография)</label>
                    <textarea id="bio" name="bio" placeholder="Расскажите немного о себе..." rows="4"
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500 resize-none"><?= htmlspecialchars($user['bio'] ?? '') ?></textarea>
                </div>
                
                <div>
                    <input type="password" name="password" placeholder="Новый пароль (оставьте пустым, чтобы не менять)"
                        class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:ring-2 focus:ring-blue-500">
                </div>

                <button type="submit" name="update_profile"
                    class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-3 rounded-full">
                    Сохранить изменения
                </button>
            </form>
        </div>
    </main>
</body>
</html>