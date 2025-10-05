        <main class="w-full md:w-2/3 lg:w-1/2 min-h-screen flex items-center justify-center">
            <div class="bg-gray-800 p-8 rounded-2xl shadow-lg w-full max-w-md">
                <!-- Форма входа -->
                <div id="login-form-container">
                    <h2 class="text-3xl font-bold text-center mb-6">Вход в Meetlook</h2>
                    <form id="login-form" class="space-y-4">
                        <div>
                            <label for="login-email" class="sr-only">Электронная почта</label>
                            <input type="email" id="login-email" placeholder="Электронная почта" required
                                class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                        </div>
                        <div>
                            <label for="login-password" class="sr-only">Пароль</label>
                            <input type="password" id="login-password" placeholder="Пароль" required
                                class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                        </div>
                        <button type="submit"
                            class="w-full bg-blue-500 hover:bg-blue-600 transition-colors text-white font-bold py-3 rounded-full">
                            Войти
                        </button>
                    </form>
                    <p class="text-center mt-4 text-sm text-gray-400">
                        Ещё нет аккаунта? <a href="#" id="show-register" class="text-blue-500 hover:underline">Зарегистрироваться</a>
                    </p>
                </div>

                <!-- Форма регистрации (изначально скрыта) -->
                <div id="register-form-container" class="hidden">
                    <h2 class="text-3xl font-bold text-center mb-6">Создать аккаунт</h2>
                    <form id="register-form" class="space-y-4">
                        <div>
                            <label for="register-email" class="sr-only">Электронная почта</label>
                            <input type="email" id="register-email" placeholder="Электронная почта" required
                                class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                        </div>
                        <div>
                            <label for="register-password" class="sr-only">Пароль</label>
                            <input type="password" id="register-password" placeholder="Пароль" required
                                class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                        </div>
                        <div>
                            <label for="confirm-password" class="sr-only">Повторите пароль</label>
                            <input type="password" id="confirm-password" placeholder="Повторите пароль" required
                                class="w-full px-4 py-3 bg-gray-700 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors">
                        </div>
                        <button type="submit"
                            class="w-full bg-blue-500 hover:bg-blue-600 transition-colors text-white font-bold py-3 rounded-full">
                            Зарегистрироваться
                        </button>
                    </form>
                    <p class="text-center mt-4 text-sm text-gray-400">
                        Уже есть аккаунт? <a href="#" id="show-login" class="text-blue-500 hover:underline">Войти</a>
                    </p>
                </div>
            </div>
        </main>