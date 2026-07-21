#!/usr/bin/env python3
"""
Собирает data/albums.json из содержимого папки img.
Запускается автоматически при каждом пуше — руками трогать не нужно.

РАСКЛАДКА ФАЙЛОВ

Фоновые снимки — прямо в img:

    img/cover.jpg           фон заставки
    img/about-bg.jpg        фон раздела «О себе»
    img/portrait.jpg        портрет автора
    img/contact.jpg         фото в блоке «Связаться»

Простой альбом — папка со снимками:

    img/albums/02-портреты/photo-1.jpg
    img/albums/02-портреты/photo-2.jpg

Альбом с вложенными альбомами — папка с подпапками:

    img/albums/01-художественная-съёмка/01-танец/photo-1.jpg
    img/albums/01-художественная-съёмка/02-лес/photo-1.jpg
    img/albums/01-художественная-съёмка/cover.jpg      обложка раздела

Вкладывать можно на любую глубину: внутри «танца» тоже могут быть папки.

ПРАВИЛА

    название      имя папки без цифрового префикса, дефисы -> пробелы
                  либо первая строка файла title.txt в этой папке
    порядок       цифры в начале имени папки: 01, 02, 10
    обложка       файл cover.* в папке, иначе первый снимок
    сортировка    по именам файлов, photo-2 идёт перед photo-10
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
MAX_DEPTH = 6

warnings = []


def natural_key(name):
    """Чтобы photo-10 шёл после photo-9, а не между 1 и 2."""
    return [int(p) if p.isdigit() else p.lower()
            for p in re.split(r"(\d+)", name)]


def is_image(name):
    return name.lower().endswith(EXTS) and not name.startswith(".")


def web_path(abs_path):
    """Абсолютный путь на диске -> путь для сайта."""
    return os.path.relpath(abs_path, ROOT).replace(os.sep, "/")


def pretty_title(folder):
    """01-художественная-съёмка -> Художественная съёмка"""
    t = re.sub(r"^\d+\s*[-_.]?\s*", "", folder)
    t = t.replace("-", " ").replace("_", " ").strip()
    return t[:1].upper() + t[1:] if t else folder


def read_title(path, folder):
    title_file = os.path.join(path, "title.txt")
    if os.path.isfile(title_file):
        try:
            with open(title_file, encoding="utf-8") as fh:
                first = fh.readline().strip()
                if first:
                    return first
        except OSError as e:
            warnings.append("не прочитался %s: %s" % (web_path(title_file), e))
    return pretty_title(folder)


def order_photos(path, images):
    """Сортирует снимки, поднимая cover.* на первое место."""
    photos = sorted(images, key=natural_key)
    for i, f in enumerate(photos):
        if os.path.splitext(f)[0].lower() == "cover":
            photos.insert(0, photos.pop(i))
            break
    return [web_path(os.path.join(path, f)) for f in photos]


def build_node(path, folder, depth=0):
    """Строит альбом из папки. Возвращает None, если снимков внутри нет."""
    if depth > MAX_DEPTH:
        warnings.append("слишком глубокая вложенность, пропущено: %s" % web_path(path))
        return None

    try:
        entries = sorted(os.listdir(path), key=natural_key)
    except OSError as e:
        warnings.append("не прочиталась папка %s: %s" % (web_path(path), e))
        return None

    images = [f for f in entries if is_image(f)]
    subdirs = [f for f in entries
               if os.path.isdir(os.path.join(path, f)) and not f.startswith(".")]

    children = []
    for d in subdirs:
        node = build_node(os.path.join(path, d), d, depth + 1)
        if node:
            children.append(node)

    title = read_title(path, folder)

    # Есть вложенные альбомы — это раздел
    if children:
        cover = None
        for f in images:
            if os.path.splitext(f)[0].lower() == "cover":
                cover = web_path(os.path.join(path, f))
                break
        if cover is None and images:
            cover = web_path(os.path.join(path, sorted(images, key=natural_key)[0]))
        if cover is None:
            cover = children[0].get("cover")

        others = [f for f in images if os.path.splitext(f)[0].lower() != "cover"]
        if others:
            warnings.append(
                "в разделе «%s» есть снимки вне подпапок (%d шт.) — "
                "они не показываются, используется только cover.*" % (title, len(others)))

        return {"title": title, "cover": cover, "children": children}

    # Вложенных папок нет — это обычный альбом
    if images:
        photos = order_photos(path, images)
        return {"title": title, "cover": photos[0], "photos": photos}

    return None


def collect_albums():
    if not os.path.isdir(ALBUMS_DIR):
        return []

    try:
        entries = sorted(os.listdir(ALBUMS_DIR), key=natural_key)
    except OSError as e:
        warnings.append("не прочиталась папка albums: %s" % e)
        return []

    albums = []

    # Снимки, лежащие прямо в img/albums — собираем в один альбом
    loose = [f for f in entries if is_image(f)]
    if loose:
        photos = order_photos(ALBUMS_DIR, loose)
        albums.append({"title": "Работы", "cover": photos[0], "photos": photos})

    for folder in entries:
        path = os.path.join(ALBUMS_DIR, folder)
        if not os.path.isdir(path) or folder.startswith("."):
            continue
        node = build_node(path, folder)
        if node:
            albums.append(node)

    return albums


def find_root_image(*stems):
    if not os.path.isdir(IMG):
        return None
    try:
        files = sorted(os.listdir(IMG), key=natural_key)
    except OSError:
        return None
    for stem in stems:
        for f in files:
            base, ext = os.path.splitext(f)
            if base.lower() == stem and ext.lower() in EXTS:
                return "img/" + f
    return None


def print_tree(nodes, indent=1):
    for n in nodes:
        pad = "  " * indent
        if "children" in n:
            print("%s%-30s раздел, вложено: %d" % (pad, n["title"], len(n["children"])))
            print_tree(n["children"], indent + 1)
        else:
            print("%s%-30s %d кадров" % (pad, n["title"], len(n["photos"])))


def count_photos(nodes):
    total = 0
    for n in nodes:
        total += len(n["photos"]) if "photos" in n else count_photos(n["children"])
    return total


def main():
    albums = collect_albums()

    images = {
        "cover":    find_root_image("cover", "hero"),
        "about":    find_root_image("about-bg", "about"),
        "portrait": find_root_image("portrait", "me"),
        "contact":  find_root_image("contact"),
    }
    images = {k: v for k, v in images.items() if v}

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as fh:
        json.dump({"albums": albums, "images": images}, fh,
                  ensure_ascii=False, indent=2)

    print("Альбомов верхнего уровня: %d, всего кадров: %d"
          % (len(albums), count_photos(albums)))
    print_tree(albums)

    if images:
        print("Фоновые фотографии:")
        for k, v in images.items():
            print("  %-10s %s" % (k, v))
    else:
        print("Фоновых фотографий не найдено — будут тёмные заглушки.")

    for w in warnings:
        print("  ! %s" % w)

    if not albums:
        print("\nВНИМАНИЕ: в img/albums нет ни одной фотографии.")
        print("Сайт соберётся, но раздел «Альбомы» будет пустым.")

    return 0


if __name__ == "__main__":
    sys.exit(main())
