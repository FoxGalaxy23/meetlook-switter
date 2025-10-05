<!-- Затемнение фона -->
<div id="overlay" class="fixed inset-0 bg-black bg-opacity-50 hidden z-40"></div>

<!-- Десктопное меню -->
<div id="desktop-sidebar" class="w-64 z-50 p-4 sticky top-0 h-screen hidden md:block">
    <img src="elements/media/images/switter.png" alt="">
    <div class="flex flex-col h-full">
        <a href="index.php" class="flex items-center space-x-4 p-3 rounded-full hover:bg-gray-800 transition-colors">
            <i class="fas fa-home text-xl"></i>
            <span class="text-xl font-bold">Главная</span>
        </a>
        <a href="notifications.php" class="flex items-center space-x-4 p-3 rounded-full hover:bg-gray-800 transition-colors">
            <i class="fas fa-bell text-xl"></i>
            <span class="text-xl font-bold">Уведомления</span>
        </a>
        <a href="messenger.php" class="flex items-center space-x-4 p-3 rounded-full hover:bg-gray-800 transition-colors">
            <i class="fas fa-envelope text-xl"></i>
            <span class="text-xl font-bold">Мессенджер</span>
        </a>
<a href="profile.php" class="flex items-center space-x-4 p-3 rounded-full hover:bg-gray-800 transition-colors mb-4">
    <i class="fas fa-user text-xl"></i>
    <span class="text-xl font-bold">Профиль</span>
</a>
<button id="post-button" class="w-full bg-blue-500 hover:bg-blue-600 transition-colors text-white font-bold py-3 rounded-full">
    Опубликовать
</button>
    </div>
</div>

<!-- Мобильное меню -->
<div id="mobile-sidebar" class="fixed inset-y-0 left-0 bg-gray-900 w-64 transform -translate-x-full transition-transform md:hidden z-50">
    <div class="p-4 flex flex-col">
        <a href="index.php" class="flex items-center space-x-4 p-3 rounded-full hover:bg-gray-800 transition-colors">
            <i class="fas fa-home text-xl"></i>
            <span class="text-xl font-bold">Главная</span>
        </a>
        <a href="notifications.php" class="flex items-center space-x-4 p-3 rounded-full hover:bg-gray-800 transition-colors">
            <i class="fas fa-bell text-xl"></i>
            <span class="text-xl font-bold">Уведомления</span>
        </a>
        <a href="messenger.php" class="flex items-center space-x-4 p-3 rounded-full hover:bg-gray-800 transition-colors">
            <i class="fas fa-envelope text-xl"></i>
            <span class="text-xl font-bold">Мессенджер</span>
        </a>
        <a href="profile.php" class="flex items-center space-x-4 p-3 rounded-full hover:bg-gray-800 transition-colors">
            <i class="fas fa-user text-xl"></i>
            <span class="text-xl font-bold">Профиль</span>
        </a>
    </div>
</div>

<script>
    const mobileSidebar = document.getElementById('mobile-sidebar');
    const overlay = document.getElementById('overlay');
    let touchstartX = 0;
    let touchendX = 0;

    function openSidebar() {
        mobileSidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    }

    function closeSidebar() {
        mobileSidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    }

    function checkDirection() {
        if (touchendX > touchstartX + 50) {
            openSidebar();
        }
        if (touchendX < touchstartX - 50) {
            closeSidebar();
        }
    }

    // Swipe управление
    document.addEventListener('touchstart', e => {
        touchstartX = e.changedTouches[0].screenX;
    });

    document.addEventListener('touchend', e => {
        touchendX = e.changedTouches[0].screenX;
        checkDirection();
    });

    // Клик по overlay закрывает меню
    overlay.addEventListener('click', closeSidebar);
</script>
