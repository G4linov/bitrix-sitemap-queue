
# Bitrix Sitemap Queue

Автоматическая регенерация `sitemap.xml` для Bitrix на событиях инфоблоков: изменения складываются в очередь, воркер пинает генератор, генератор обрабатывает весь снепшот очереди и работает строго в одном экземпляре (файловый лок).

![diagram](docs/screenshots/flow.swg)

## Зачем
- 🔄 Автозапуск по хукам (элементы/разделы).
- 🔒 Без гонок и дубликатов — один генератор за раз (file lock).
- 🗂 Очередь событий не теряется, коалесцируется.
- 📜 Подробные логи для диагностики.
- ⚙️ Подходит как для полной, так и инкрементальной регенерации.

---

## Как это работает

1. **Хуки** (`init.php` → `sitemap_hooks.php`) ловят события Bitrix (`OnAfterIBlockElement*`, `OnAfterIBlockSection*`), кладут записи в очередь `local/var/sitemap-queue.json` и «пинают» воркер по HTTPS.
2. **Воркер** (`sitemap_worker.php`) вызывает `gen-site.php` и сразу завершает работу (клиент получает `200 OK` мгновенно).
3. **Генератор** (`gen-site.php`) сам:
   - ставит лок на 15 минут (`local/var/sitemap.lock`);
   - читает **всю** очередь → **очищает** её → выполняет регенерацию;
   - по завершении снимает лок;
   - если во время работы накопилось новое — сам «пинает» воркер на следующий цикл.

> Ранний ответ реализован через `fastcgi_finish_request()`, чтобы веб-сервер не резал долгую генерацию по таймауту.

---

## Установка

### 1) Файлы
Скопируйте в проект:

```

/local/tools/
sitemap\_hooks.php
sitemap\_worker.php
gen-site.php

/local/php\_interface/init.php  (добавьте подключение и обработчики)

````

### 2) Подключение в `init.php`

```php
<?php
use Bitrix\Main\Loader;
Loader::includeModule('iblock');

AddEventHandler('iblock', 'OnAfterIBlockElementAdd',    ['Enisey\\Sitemap\\Hooks','onElementChange']);
AddEventHandler('iblock', 'OnAfterIBlockElementUpdate', ['Enisey\\Sitemap\\Hooks','onElementChange']);
AddEventHandler('iblock', 'OnAfterIBlockElementDelete', ['Enisey\\Sitemap\\Hooks','onElementDelete']);

AddEventHandler('iblock', 'OnAfterIBlockSectionAdd',    ['Enisey\\Sitemap\\Hooks','onSectionChange']);
AddEventHandler('iblock', 'OnAfterIBlockSectionUpdate', ['Enisey\\Sitemap\\Hooks','onSectionChange']);
AddEventHandler('iblock', 'OnAfterIBlockSectionDelete', ['Enisey\\Sitemap\\Hooks','onSectionDelete']);

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/tools/sitemap_hooks.php';
````

### 3) Папка для данных

```bash
mkdir -p local/var
chmod 775 local/var
```

### 4) Секреты и домен

* В `local/tools/sitemap_hooks.php` и `local/tools/sitemap_worker.php` установите **одинаковый** `SECRET`.
* Вызовы по HTTPS ориентированы на прод-домен (по умолчанию `enisey-m.ru`). Поменяйте на свой домен в обоих файлах.

### 5) Проверка

Сохраните/обновите элемент инфоблока — в `local/var/sitemap.log` появятся строки вида:

```
[YYYY-mm-dd HH:MM:SS] enqueue element upsert id=123
[YYYY-mm-dd HH:MM:SS] kick worker
[YYYY-mm-dd HH:MM:SS] GEN: lock set for 15 minutes
[YYYY-mm-dd HH:MM:SS] GEN: snapshot size=1
[YYYY-mm-dd HH:MM:SS] GEN: lock released
```

---

## Кастомизация

* **Полный пересчёт vs точечный**
  В `gen-site.php` можете:

    * всегда делать полный пересчёт;
    * либо разбирать `$GLOBALS['SITEMAP_QUEUE_SNAPSHOT']` и обновлять точечно.

* **Антидребезг пинка**
  В `sitemap_hooks.php` уже встроен троттлинг (не чаще 1 раза в 3 сек), и если лок активен — хуки только копят очередь.

* **Cron-страховка (не обязательно)**
  Можно добавить минutely-пинок воркера:

  ```bash
  * * * * * /usr/bin/php /var/www/<site>/local/tools/sitemap_worker.php > /dev/null 2>&1
  ```

  Дубликатов не будет: лок контролирует запуск.

---

## Структура

```
.
├── local
│   ├── php_interface
│   │   └── init.php
│   └── tools
│       ├── gen-site.php
│       ├── sitemap_hooks.php
│       └── sitemap_worker.php
├── local
│   └── var
│       ├── sitemap-queue.json
│       ├── sitemap.lock
│       └── sitemap.log
├── docs
│   └── screenshots
│       ├── flow.png
│       └── logs.png
└── examples
    └── logs
        └── sitemap.log
```

---

## Типовые логи

### Успешный цикл + повторный старт

```
[03:55:02] enqueue element upsert id=16587
[03:55:02] kick worker
[03:55:02] GEN: lock set for 15 minutes
[03:55:02] GEN: snapshot size=1
... (генерация) ...
[04:06:11] GEN: lock released
[04:06:11] GEN: queue not empty after run, re-kick worker
[04:06:12] GEN: lock set for 15 minutes
[04:06:12] GEN: snapshot size=1
```

### Во время активного лока

```
[03:55:20] enqueue element delete id=16587
# хуки не пинают воркер (лок активен), событие копится
```

---

## Безопасность

* Генератор (`gen-site.php`) при запуске по вебу требует `?token=...`. Храните токен вне репозитория или меняйте по деплою.
* Воркер вызывает генератор только на ваш домен, с отключенной строгой проверкой SSL при необходимости (можно включить обратно).
* Логи не содержат приватных данных, но всё равно не коммитьте реальные ID пользователей/URL, если это критично.

---

## Troubleshooting

* **В логах `errno=28 timeout` у воркера** — нормально, если генератор уже работает; воркер настроен ждать недолго, чтобы не засорять пул PHP-FPM.
* **`Class "Bitrix\Main\Loader" not found` в gen-site.php** — проверьте, что вверху `gen-site.php` есть бутстрап Bitrix (`prolog_before.php`).
* **Генерация не стартует** — смотрите `sitemap.log`:

    * нет строк `GEN: lock set` → воркер не достучался до генератора (домен/SSL/фаервол).
    * `GEN: snapshot size=0` и при этом изменения были → очередь не пишется (права на `local/var`).

---

## Лицензия

MIT License.

````

---

## Примеры и артефакты для репозитория

### `examples/logs/sitemap.log`
```text
[2025-08-16 03:55:02] enqueue element upsert id=16587
[2025-08-16 03:55:02] kick worker
[2025-08-16 03:55:02] GEN: lock set for 15 minutes
[2025-08-16 03:55:02] GEN: snapshot size=1
[2025-08-16 04:06:11] GEN: lock released
[2025-08-16 04:06:11] GEN: queue not empty after run, re-kick worker
[2025-08-16 04:06:12] GEN: lock set for 15 minutes
[2025-08-16 04:06:12] GEN: snapshot size=1
````

### `docs/screenshots/flow.svg`