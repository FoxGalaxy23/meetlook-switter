<?php
include "elements/php/header.php";
?>

<div class="flex w-full max-w-7xl mx-auto min-h-screen">
    <?php include "elements/php/sidebar-left.php"; ?>

    <main class="w-full md:w-2/3 lg:w-1/2 border-x border-gray-800 min-h-screen">
        <header class="sticky top-0 bg-gray-900 bg-opacity-80 backdrop-blur-sm p-4 border-b border-gray-800">
            <h1 class="text-xl font-bold">Уведомления</h1>
        </header>

        <div id="notifications" class="divide-y divide-gray-800">
            <div class="p-4 hover:bg-gray-800 transition-colors flex items-center space-x-3">
                <i class="fas fa-heart text-red-500 text-lg"></i>
                <p><span class="font-bold">User1</span> лайкнул ваш пост</p>
                <span class="text-sm text-gray-400">5 минут назад</span>
            </div>
            <div class="p-4 hover:bg-gray-800 transition-colors flex items-center space-x-3">
                <i class="fas fa-user-plus text-blue-500 text-lg"></i>
                <p><span class="font-bold">User2</span> подписался на вас</p>
                <span class="text-sm text-gray-400">10 минут назад</span>
            </div>
            <div class="p-4 hover:bg-gray-800 transition-colors flex items-center space-x-3">
                <i class="fas fa-comment text-green-500 text-lg"></i>
                <p><span class="font-bold">User3</span> оставил комментарий</p>
                <span class="text-sm text-gray-400">30 минут назад</span>
            </div>
        </div>
    </main>

    <?php include "elements/php/sidebar-right.php"; ?>
    <?php include "elements/php/modal.php"; ?>
</div>

<?php
include "elements/php/footer.php";
?>