<?php

?>

<div class="p-4 border-b border-gray-800">
        <form id="post-form" class="space-y-2" enctype="multipart/form-data">
            <textarea name="text" placeholder="Что происходит?" rows="3" class="w-full bg-gray-800 text-white text-lg resize-none outline-none py-2 px-3 rounded"></textarea>
            <div class="flex items-center justify-between">
                <div>
                    <label for="media-input" class="cursor-pointer text-gray-400 hover:text-blue-400 transition-colors duration-200">
                        <span class="text-2xl">📎</span>
                    </label>
                    <input type="file" id="media-input" name="media[]" accept="image/*,video/*,audio/*" multiple class="hidden">
                    <div class="text-sm text-gray-400"></div>
                </div>
                <div class="flex items-center space-x-2">
                    <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-6 rounded-full">Опубликовать</button>
                </div>
            </div>
            <div id="post-preview" class="mt-2 grid gap-2 grid-cols-2"></div>
        </form>
    </div>