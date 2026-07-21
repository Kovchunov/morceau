#!/usr/bin/env python3
"""
Собирает data/albums.json из содержимого папки img.
Запускается автоматически при каждом пуше — руками трогать не нужно.

Как раскладывать файлы:

    img/cover.jpg           фон заставки
    img/about-bg.jpg        фон раздела «О себе»
    img/portrait.jpg        портрет автора
    img/contact.jpg         фото в блоке «Связаться»

    img/albums/01-портреты/photo-1.jpg      папка = альбом
    img/albums/01-портреты/photo-2.jpg
    img/albums/02-репортаж/cover.jpg        файл cover.* станет обложкой

Название альбома берётся из имени папки: цифровой префикс отбрасывается,
дефисы и подчёркивания превращаются в пробелы. Если нужно точное название —
положите в папку файл title.txt и напишите его первой строкой.

Цифровой префикс задаёт порядок альбомов на сайте.
"""

import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
IMG = os.path.join(ROOT, "img")
ALBUMS_DIR = os.path.join(IMG, "albums")
OUT = os.path.join(ROOT, "data", "albums.json")

EXTS = (".jpg", ".jpeg", ".png", ".webp", ".avif", ".gif")


def natural_key(name):
    """Чтобы photo-10 шёл после photo-9, а не между 1 и 2."""
    return [int(p) if p.isdigit() else p.lower()
            for p in re.split(r"(\d+)", name)]


def is_image(name):
    return name.lower().endswith(EXTS) and not name.startswith(".")


def pretty_title(folder):
    """01-художественная-съёмка -> Художественная съёмка"""
    t = re.sub(r"^\d+\s*[-_.]?\s*", "", folder)
    t = t.replace("-", " ").replace("_", " ").strip()
    return t[:1].upper() + t[1:] if t else folder


def find_root_image(*stems):
    """Ищет img/<stem>.<любое расширение>, возвращает путь для сайта."""
    if not os.path.isdir(IMG):
        return None
    files = os.listdir(IMG)
    for stem in stems:
        for f in sorted(files, key=natural_key):
            base, ext = os.path.splitext(f)
            if base.lower() == stem and ext.lower() in EXTS:
                return "img/" + f
    return None


def collect_albums():
    albums = []
    if not os.path.isdir(ALBUMS_DIR):
        return albums

    entries = sorted(os.listdir(ALBUMS_DIR), key=natural_key)

    # Вариант «без папок»: фотографии лежат прямо в img/albums
    loose = [f for f in entries if is_image(f)]
    if loose:
        albums.append({
            "title": "Работы",
            "photos": ["img/albums/" + f for f in loose],
        })

    for folder in entries:
        path = os.path.join(ALBUMS_DIR, folder)
        if not os.path.isdir(path):
            continue

        photos = sorted([f for f in os.listdir(path) if is_image(f)],
                        key=natural_key)
        if not photos:
            continue

        # Файл с именем cover.* становится обложкой — переносим в начало
        for i, f in enumerate(photos):
            if os.path.splitext(f)[0].lower() == "cover":
                photos.insert(0, photos.pop(i))
                break

        title = pretty_title(folder)
        title_file = os.path.join(path, "title.txt")
        if os.path.isfile(title_file):
            with open(title_file, encoding="utf-8") as fh:
                first = fh.readline().strip()
                if first:
                    title = first

        albums.append({
            "title": title,
            "photos": ["img/albums/" + folder + "/" + f for f in photos],
        })

    return albums


def main():
    albums = collect_albums()

    images = {
        "cover":    find_root_image("cover", "hero", "заставка"),
        "about":    find_root_image("about-bg", "about", "о-себе"),
        "portrait": find_root_image("portrait", "портрет", "me"),
        "contact":  find_root_image("contact", "контакты"),
    }
    images = {k: v for k, v in images.items() if v}

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump({"albums": albums, "images": images}, fh,
                  ensure_ascii=False, indent=2)

    print("Собрано альбомов: %d" % len(albums))
    for a in albums:
        print("  %-34s %d кадров" % (a["title"], len(a["photos"])))
    if images:
        print("Фоновые фотографии:")
        for k, v in images.items():
            print("  %-10s %s" % (k, v))
    else:
        print("Фоновых фотографий не найдено — будут тёмные заглушки.")

    if not albums:
        print("\nВНИМАНИЕ: в img/albums нет ни одной фотографии.")
        print("Сайт соберётся, но раздел «Альбомы» будет пустым.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
