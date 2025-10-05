"""
classifier_api.py
Запускает модель классификации в виде простого и быстрого веб-API (FastAPI).

Сервер загружает модель и эмбеддинги тем один раз при старте.
PHP обращается к этому серверу через HTTP POST.
"""

import os
import pickle
from typing import List, Dict, Tuple
import numpy as np
from sentence_transformers import SentenceTransformer, util
from tqdm import tqdm

from fastapi import FastAPI, Body

# ---------- Настройки ----------
MODEL_NAME = "sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2"
CACHE_FILE = "topics_cache.pkl"

# Ручные темы: ключ -> список примеров описаний/фраз для этой темы.
# (Добавлены новые темы: Travel, Food, Health, Fashion)
TOPICS_EXAMPLES: Dict[str, List[str]] = {
    "Politics": [
        "высокая политика", "выборы", "правительство", "геополитика", "санкции",
        "президент", "Трамп", "Байден", "Путин", "СВО", "War", "government",
        "elections", "diplomacy"
    ],
    "IT": [
        "программирование", "нейросети", "AI", "DevOps", "обновления ОС", "гаджеты",
        "стартапы", "Apple", "Microsoft", "Google", "software", "hardware", "technology"
    ],
    "News": [
        "срочные новости", "инциденты", "репортажи", "breaking news", "новости дня",
        "смерть", "accident", "report"
    ],
    "Blogs": [
        "личный блог", "дневник", "размышления о жизни", "мой опыт", "лайфстайл",
        "personal blog", "thoughts"
    ],
    "Sport": [
        "футбол", "баскетбол", "матчи", "результаты матчей", "Олимпиада", "Чемпионат",
        "голы", "sports", "athlete", "competition"
    ],
    "Entertainment/Culture": [
        "кино", "музыка", "сериалы", "книги", "мемы", "концерты", "entertainment",
        "culture", "TV", "movies"
    ],
    "Economics/Business": [
        "рынок", "акции", "инвестиции", "компании", "экономика", "крипта", "биткойн",
        "финансовые пирамиды", "бюджет", "business", "finance"
    ],
    "Science/Education": [
        "исследования", "университет", "научные открытия", "учёба", "школа",
        "студенты", "NASA", "SpaceX", "science", "education"
    ],
    "Gaming": [
        "Nintendo", "PlayStation", "Xbox", "Steam", "VR", "GeForce RTX", "GTA",
        "Dota 2", "гейминг", "shooter", "RPG", "MMO"
    ],
    "Dangerous": [
        "терроризм", "теракт", "бомба", "оружие", "геноцид", "насилие", "убийство",
        "экстремизм", "расизм", "призывы к насилию", "terrorism", "mass shooting",
        "violence", "hate speech"
    ],
    "Travel/Geography": [
        "путешествия", "туризм", "отпуск", "горы", "море", "страна", "город",
        "достопримечательности", "traveling", "vacation", "trip", "geography"
    ],
    "Food/Cooking": [
        "рецепты", "кулинария", "еда", "ресторан", "кухня", "готовка", "food",
        "cooking", "recipe", "chef"
    ],
    "Health/Medicine": [
        "здоровье", "медицина", "врач", "больница", "лечение", "фитнес", "диета",
        "vaccination", "health", "doctor", "fitness"
    ],
    "Fashion/Beauty": [
        "мода", "стиль", "одежда", "макияж", "косметика", "бренды", "fashion",
        "style", "makeup", "beauty"
    ],
    "Furry": [
        "Собака", "животные", "лисы", "антропоморфные",
        "Кошки", "домашние питомцы", "Протоген"
    ],
    "LGBT": [
        "Гей", "Лезбиянка", "Трансгендер", "смена пола", "Трансвестит", "Фембой", "феминизм",
        "pride", "бдсм"
    ]
}


# ---------- Функции классификации (из ai_theme.py) ----------
def load_model(model_name: str = MODEL_NAME) -> SentenceTransformer:
    """Загружает модель Sentence-Transformer."""
    print(f"Загружаю модель {model_name}...")
    # Если модель не найдена локально, она будет загружена из Hugging Face
    model = SentenceTransformer(model_name)
    return model

def build_topic_embeddings(model: SentenceTransformer,
                           topics_examples: Dict[str, List[str]],
                           cache_file: str = CACHE_FILE,
                           force_rebuild: bool = False) -> Dict[str, np.ndarray]:
    """
    Возвращает dict: topic -> embedding (l2-normalized).
    Кэширует результат в файл для ускорения.
    """
    if os.path.exists(cache_file) and not force_rebuild:
        try:
            with open(cache_file, "rb") as f:
                cache = pickle.load(f)
            if cache.get("topics_keys") == list(topics_examples.keys()):
                print("Загружены эмбеддинги тем из кэша.")
                return cache["topic_embeddings"]
            else:
                print("Темы изменились — перестрою эмбеддинги.")
        except Exception:
            print("Не удалось загрузить кэш, перестрою эмбеддинги.")

    topic_embeddings = {}
    for topic, examples in tqdm(topics_examples.items(), desc="Building topic embeddings"):
        if not examples:
            print(f"ВНИМАНИЕ: Тема '{topic}' пропущена, так как у нее нет примеров.")
            continue
            
        embeddings = model.encode(examples, convert_to_tensor=False, show_progress_bar=False)
        avg = np.mean(embeddings, axis=0)
        
        # Нормировка (для косинусной схожести)
        norm = avg / (np.linalg.norm(avg) + 1e-9)
        topic_embeddings[topic] = norm

    # Сохранение кэша
    try:
        with open(cache_file, "wb") as f:
            pickle.dump({
                "topics_keys": list(topic_embeddings.keys()),
                "topic_embeddings": topic_embeddings
            }, f)
        print(f"Эмбеддинги тем сохранены в {cache_file}")
    except Exception as e:
        print(f"Не удалось сохранить кэш: {e}")

    return topic_embeddings

def classify_text(model: SentenceTransformer,
                  topic_embeddings: Dict[str, np.ndarray],
                  text: str,
                  top_k: int = 3) -> List[Tuple[str, float]]:
    """
    Возвращает список (topic, score) отсортированный по убыванию score.
    """
    # L2-нормировка текста
    text_emb = model.encode([text], convert_to_tensor=False)[0]
    text_emb = text_emb / (np.linalg.norm(text_emb) + 1e-9)

    scores = []
    for topic, topic_emb in topic_embeddings.items():
        # Расчет косинусного сходства (так как векторы нормированы)
        score = float(np.dot(text_emb, topic_emb))
        scores.append((topic, score))
        
    scores.sort(key=lambda x: x[1], reverse=True)
    return scores[:top_k]

# --- Инициализация API и Глобальные переменные ---
app = FastAPI(title="Topic Classifier API", 
              description="Сервис для классификации постов по темам с использованием Sentence-Transformers.")

model: SentenceTransformer
topic_embeddings: Dict[str, np.ndarray]

@app.on_event("startup")
async def startup_event():
    """Загрузка модели и эмбеддингов тем при старте сервера."""
    global model, topic_embeddings
    print("--- Запуск сервера классификации ---")
    model = load_model()
    # Загружаем или перестраиваем эмбеддинги
    topic_embeddings = build_topic_embeddings(model, TOPICS_EXAMPLES, force_rebuild=False)
    print("API готово к приему POST-запросов на /classify_post/")

@app.post("/classify_post/")
def classify_post_endpoint(
    text: str = Body(..., description="Текст поста для классификации."),
    top_k: int = Body(3, description="Сколько топ-тем вернуть.")
) -> List[Tuple[str, float]]:
    """
    Основная точка API для классификации текста.
    Возвращает список в формате: [["Тема_1", 0.95], ["Тема_2", 0.80], ...]
    """
    if not text:
        return []
        
    # Вызываем функцию классификации
    results = classify_text(model, topic_embeddings, text, top_k=top_k)
    return results

# Запуск: uvicorn classifier_api:app --host 0.0.0.0 --port 8000