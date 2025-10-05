-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Хост: 127.0.0.1:3306
-- Время создания: Сен 28 2025 г., 11:20
-- Версия сервера: 8.0.30
-- Версия PHP: 8.1.9

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `switter`
--

-- --------------------------------------------------------

--
-- Структура таблицы `follows`
--

CREATE TABLE `follows` (
  `follower_id` int NOT NULL,
  `following_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ;

--
-- Дамп данных таблицы `follows`
--

INSERT INTO `follows` (`follower_id`, `following_id`, `created_at`) VALUES
(2, 1, '2025-09-27 07:42:12');

-- --------------------------------------------------------

--
-- Структура таблицы `likes`
--

CREATE TABLE `likes` (
  `id` int NOT NULL,
  `post_id` int NOT NULL,
  `user_id` int NOT NULL,
  `is_dislike` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `likes`
--

INSERT INTO `likes` (`id`, `post_id`, `user_id`, `is_dislike`, `created_at`) VALUES
(17, 101, 1, 0, '2025-09-27 19:24:37');

-- --------------------------------------------------------

--
-- Структура таблицы `posts`
--

CREATE TABLE `posts` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `text` text NOT NULL,
  `parent_id` int DEFAULT NULL,
  `original_post_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `topic` varchar(50) DEFAULT NULL COMMENT 'Основная тема поста по классификации НС'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `posts`
--

INSERT INTO `posts` (`id`, `user_id`, `text`, `parent_id`, `original_post_id`, `created_at`, `topic`) VALUES
(101, 1, 'Мне нравиться лисы, они такие миленькие мне хочется их погладить и потискать 🦊', NULL, NULL, '2025-09-27 19:24:33', 'Furry'),
(102, 1, 'Я ненавижу Путина, он тварь который хочет вернуть совок с помощью репрессий и смертей людей, он устроил войну на Украине и он мразь', NULL, NULL, '2025-09-27 19:25:22', 'Politics'),
(103, 1, 'Мне очень нравиться телефоны Microsoft/Nokia Lumia оссобено Microsoft Lumia 950 XL на Windows 10 Mobile, они опередили своё время однозначно', NULL, NULL, '2025-09-27 19:26:01', 'IT'),
(104, 1, 'Я голоден, я скорее всего пойду и закажу доставку пиццы на дом, скорее всего закажу пиццу у Pizza Hut я не знаю даже', NULL, NULL, '2025-09-27 19:26:32', 'Food/Cooking'),
(105, 1, 'Талибы - террористы которые захватили власть в авганистане', NULL, NULL, '2025-09-27 19:26:59', 'Dangerous'),
(106, 1, 'У меня недавно порвались кросовки, думаю идти в какой не будь магазин и покупать новые кросовки', NULL, NULL, '2025-09-27 19:27:36', 'Fashion/Beauty'),
(107, 1, 'Xbox Series моя самая первая игровая приставка от Microsoft и как по мне она говно, играю в последние время только на PC потому что есть проблемы с оплатой и аккаунтами Xbox', NULL, NULL, '2025-09-27 19:28:30', 'Gaming'),
(108, 1, 'Мне бы хотелось взять и уехать из России и побыть например в США или в странах европы...', NULL, NULL, '2025-09-27 19:29:08', 'Travel/Geography');

-- --------------------------------------------------------

--
-- Структура таблицы `post_media`
--

CREATE TABLE `post_media` (
  `id` int NOT NULL,
  `post_id` int NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `mime` varchar(100) NOT NULL,
  `position` tinyint NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `reports`
--

CREATE TABLE `reports` (
  `id` int NOT NULL,
  `post_id` int NOT NULL,
  `user_id` int NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `sessions`
--

CREATE TABLE `sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int UNSIGNED DEFAULT NULL,
  `access` int UNSIGNED NOT NULL,
  `data` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `banner` varchar(255) DEFAULT NULL,
  `bio` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `avatar`, `banner`, `bio`, `created_at`) VALUES
(1, 'mihabear8', 'mihabear8@gmail.com', '$2y$10$VLatO5y8noPkg6OMm9e9RuOkGPTPMhU5wwRL0AkUYQKs0iJ0j2Eca', 'uploads/users/1-avatar-68d694323d006.jpg', 'uploads/users/1-banner-68d6cbe402065.png', 'Раз два три тест', '2025-09-25 10:09:48'),
(2, 'mh4', 'mihabear4@gmail.com', '$2y$10$ls.FSZnKSecQlE3HGO25d..pdSvAO99woY0S4qej82OxeogmU3hSK', NULL, NULL, NULL, '2025-09-26 13:58:27'),
(3, 'jolla', 'gamernext2@gmail.com', '$2y$10$JJj2tDayp1vChtBkVZsgmeT/9caEJgUe9dxDqEdi6M1zpYo2aBhCG', NULL, NULL, NULL, '2025-09-27 10:26:32');

-- --------------------------------------------------------

--
-- Структура таблицы `verified_users`
--

CREATE TABLE `verified_users` (
  `user_id` int NOT NULL,
  `verified_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Дамп данных таблицы `verified_users`
--

INSERT INTO `verified_users` (`user_id`, `verified_at`) VALUES
(1, '2025-09-26 16:32:56');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `follows`
--
ALTER TABLE `follows`
  ADD PRIMARY KEY (`follower_id`,`following_id`),
  ADD KEY `following_id` (`following_id`);

--
-- Индексы таблицы `likes`
--
ALTER TABLE `likes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_like_dislike` (`post_id`,`user_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `posts`
--
ALTER TABLE `posts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `parent_id` (`parent_id`),
  ADD KEY `original_post_id` (`original_post_id`);

--
-- Индексы таблицы `post_media`
--
ALTER TABLE `post_media`
  ADD PRIMARY KEY (`id`),
  ADD KEY `post_id` (`post_id`);

--
-- Индексы таблицы `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `post_id` (`post_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `sessions`
--
ALTER TABLE `sessions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `access_idx` (`access`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Индексы таблицы `verified_users`
--
ALTER TABLE `verified_users`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `likes`
--
ALTER TABLE `likes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT для таблицы `posts`
--
ALTER TABLE `posts`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=109;

--
-- AUTO_INCREMENT для таблицы `post_media`
--
ALTER TABLE `post_media`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT для таблицы `reports`
--
ALTER TABLE `reports`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `follows`
--
ALTER TABLE `follows`
  ADD CONSTRAINT `follows_ibfk_follower` FOREIGN KEY (`follower_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `follows_ibfk_following` FOREIGN KEY (`following_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `likes`
--
ALTER TABLE `likes`
  ADD CONSTRAINT `likes_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `likes_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `posts`
--
ALTER TABLE `posts`
  ADD CONSTRAINT `posts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `posts_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `posts_ibfk_3` FOREIGN KEY (`original_post_id`) REFERENCES `posts` (`id`) ON DELETE SET NULL;

--
-- Ограничения внешнего ключа таблицы `post_media`
--
ALTER TABLE `post_media`
  ADD CONSTRAINT `fk_post_media_post` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `reports`
--
ALTER TABLE `reports`
  ADD CONSTRAINT `reports_ibfk_1` FOREIGN KEY (`post_id`) REFERENCES `posts` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reports_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Ограничения внешнего ключа таблицы `verified_users`
--
ALTER TABLE `verified_users`
  ADD CONSTRAINT `verified_users_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
