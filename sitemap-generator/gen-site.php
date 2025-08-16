<?php

/**
 * Генератор sitemap.xml для Bitrix на основе инфоблоков и статических страниц.
 * Без обхода HTTP и без проверки кода 200.
 *
 * PHP 7.4+ (желательно 8.x), Bitrix D7.
 * Разместить: /local/scripts/generate_sitemap.php
 * Запуск:
 *   php -d memory_limit=1024M /var/www/your-site/local/scripts/generate_sitemap.php
 * или по вебу (с токеном).
 */

/** ===== BOOTSTRAP + LOCK + EARLY-FINISH =====
 * Выполняется в глобальном namespace и не ломает твой код ниже.
 */
namespace {
    // Защита по токену при веб-запуске
    if (php_sapi_name() !== 'cli') {
        if (($_GET['token'] ?? '') !== 'YOUR-SECRET-CODE') {
            http_response_code(403);
            exit('Forbidden');
        }
    }

    // Bitrix bootstrap
    if (empty($_SERVER['DOCUMENT_ROOT'])) {
        $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__ . '/../../');
    }
    if (!defined('NO_KEEP_STATISTIC'))     define('NO_KEEP_STATISTIC', true);
    if (!defined('NOT_CHECK_PERMISSIONS')) define('NOT_CHECK_PERMISSIONS', true);
    if (!defined('CHK_EVENT'))             define('CHK_EVENT', true);

    require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
    if (!class_exists('\\Bitrix\\Main\\Loader')) {
        http_response_code(500);
        exit('Bitrix bootstrap failed');
    }

    // Хуки/очередь/лок
    require_once $_SERVER['DOCUMENT_ROOT'] . '/local/tools/sitemap_hooks.php';

    // Алиасы, чтобы старые вызовы SectionValues/ElementValues работали
    if (!class_exists('Enisey\\SitemapGen\\SectionValues')
        && class_exists('\\Bitrix\\Iblock\\InheritedProperty\\SectionValues')) {
        class_alias('\\Bitrix\\Iblock\\InheritedProperty\\SectionValues', 'Enisey\\SitemapGen\\SectionValues');
    }
    if (!class_exists('Enisey\\SitemapGen\\ElementValues')
        && class_exists('\\Bitrix\\Iblock\\InheritedProperty\\ElementValues')) {
        class_alias('\\Bitrix\\Iblock\\InheritedProperty\\ElementValues', 'Enisey\\SitemapGen\\ElementValues');
    }

    // Настройки выполнения
    ignore_user_abort(true);
    @ini_set('memory_limit', '1024M');
    @set_time_limit(0);

    // === ЛОК теперь здесь ===
    if (\Enisey\Sitemap\Hooks::isLocked()) {
        // Уже идёт генерация — сразу выходим с 202
        if (php_sapi_name() !== 'cli') {
            http_response_code(202);
            echo 'Locked';
        }
        return;
    }

    // Ставим лок на 15 минут (страховочный TTL)
    \Enisey\Sitemap\Hooks::lockUntil(15 * 60);
    @file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/sitemap.log',
        '[' . date('Y-m-d H:i:s') . "] GEN: lock set for 15 minutes\n", FILE_APPEND);

    // При любом завершении: снять лок и, если очередь накопилась — пнуть воркер
    register_shutdown_function(function () {
        \Enisey\Sitemap\Hooks::unlock();
        @file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/sitemap.log',
            '[' . date('Y-m-d H:i:s') . "] GEN: lock released\n", FILE_APPEND);

        $q = \Enisey\Sitemap\Hooks::loadQueue();
        if (!empty($q)) {
            @file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/sitemap.log',
                '[' . date('Y-m-d H:i:s') . "] GEN: queue not empty after run, re-kick worker\n", FILE_APPEND);

            // fire-and-forget пинок воркера
            $host = 'enisey-m.ru';
            $port = 443;
            $path = '/local/tools/sitemap_worker.php?k=' . rawurlencode(\Enisey\Sitemap\Hooks::secret()) . '&_t=' . time();
            $errno = 0; $errstr = '';
            $fp = @fsockopen('ssl://' . $host, $port, $errno, $errstr, 1);
            if ($fp) {
                $req  = "GET {$path} HTTP/1.1\r\n";
                $req .= "Host: {$host}\r\n";
                $req .= "Connection: Close\r\n\r\n";
                fwrite($fp, $req);
                fclose($fp);
            }
        }
    });

    // Сразу отдадим 200 OK и закроем соединение с клиентом, чтобы не было 504
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(200);
        echo "OK\n";
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request(); // клиент (воркер/браузер) получил 200 и закрылся
        }
        // Дальше скрипт продолжает работать в фоне в этом же PHP-процессе.
    }

    // Подготовим снапшот очереди и сразу очистим её,
    // чтобы новые события копились отдельно во время текущего прогона.
    $GLOBALS['SITEMAP_QUEUE_SNAPSHOT'] = \Enisey\Sitemap\Hooks::loadQueue();
    \Enisey\Sitemap\Hooks::clearQueue();
    @file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/sitemap.log',
        '[' . date('Y-m-d H:i:s') . "] GEN: snapshot size=" . count($GLOBALS['SITEMAP_QUEUE_SNAPSHOT']) . "\n", FILE_APPEND);
}
/** ===== END BOOTSTRAP + LOCK + EARLY-FINISH ===== */


namespace Enisey\SitemapGen {

    use Bitrix\Main\Loader;
    use Bitrix\Main\Context;
    use Bitrix\Main\Config\Option;
    use Bitrix\Main\IO\Directory;
    use Bitrix\Main\IO\File;
    use Bitrix\Main\IO\FileNotFoundException;
    use Bitrix\Main\IO\InvalidPathException;
    use Bitrix\Main\Localization\Loc;
    use Bitrix\Main\Web\Uri;
    use Bitrix\Iblock\ElementTable;
    use Bitrix\Iblock\SectionTable;
    use Bitrix\Main\Application;
    use Bitrix\Main\DB\SqlQueryException;

    const _DIRECT = 1; // просто метка
    $__direct = !debug_backtrace();


    if (!function_exists(__NAMESPACE__ . '\\run')) {
        function run(): void
        {

            $__log = $_SERVER['DOCUMENT_ROOT'] . '/local/logs/sitemap_gen.log';
            @file_put_contents($__log, '[' . date('c') . "] run: enter\n", FILE_APPEND);
            register_shutdown_function(function () use ($__log) {
                @file_put_contents($__log, '[' . date('c') . "] run: leave\n", FILE_APPEND);
            });

            // Если нас вызвали по вебу — проверяем токен (требуется воркером/внешним вызовом)
            if (php_sapi_name() !== 'cli') {
                if (!isset($_GET['token']) || $_GET['token'] !== 'slava-generator') {
                    http_response_code(403);
                    // НИКАКИХ exit; внутри функции — только return;
                    return;
                }
            }

            ini_set('memory_limit', '1024M');
            set_time_limit(0);

            $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? realpath(__DIR__ . '/../../');
            define('NO_KEEP_STATISTIC', true);
            define('NOT_CHECK_PERMISSIONS', true);
            define('CHK_EVENT', true);

            require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
            if (!Loader::includeModule('iblock')) {
                @file_put_contents($__log, '[' . date('c') . "] run: iblock not loaded\n", FILE_APPEND);
                return;
            }

            \Bitrix\Main\Loader::includeModule('aspro.allcorp3');

            if (!\Bitrix\Main\Loader::includeModule('iblock')) {
                fwrite(STDERR, "Error: module iblock is not installed.\n");
                exit(1);
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\db_ping')) {
            function db_ping(): void
            {
                static $last = 0;
                if (time() - $last < 20) return; // не чаще раза в 20 сек
                $last = time();

                $conn = Application::getConnection();
                try {
                    $conn->queryExecute('SELECT 1'); // дешёвый пинг
                } catch (SqlQueryException $e) {
                }
                // переподключение
                try {
                    $conn->disconnect();
                } catch (\Throwable $t) {
                }
                $conn->connect();
            }
        }

        if (!Loader::includeModule('iblock')) {
            fwrite(STDERR, "Error: module iblock is not installed.\n");
            exit(1);
        }

// ========================= CONFIG =========================
        db_ping();
        $CONFIG = [
            'SITE_ID' => SITE_ID ?: 's1',
            'BASE_URL' => (function () {
                $site = \Bitrix\Main\SiteTable::getList(['filter' => ['=DEF' => 'Y']])->fetch();
                $host = $_SERVER['HTTP_HOST'] ?? $site['SERVER_NAME'] ?? '';
                $scheme = 'https';
                return $scheme . '://' . rtrim($host ?: 'enisey-m.ru', '/');
            })(),
            'IBLOCK_IDS' => [],
            'INCLUDE_SECTIONS' => true,
            'INCLUDE_ELEMENTS' => true,
            'STATIC_URLS' => [
                '/',
            ],
            'MENU_TYPES' => [
                // ['type' => 'top', 'dir' => '/'],
                // ['type' => 'left', 'dir' => '/'],
            ],
            'EXCLUDE_QUERY' => true,
            'RESPECT_ROBOTS' => true,
            'RESPECT_META_ROBOTS' => true,
            'LASTMOD' => 'iblock', // iblock|now|none
            'OUTPUT_DIR' => '/home/b/b2bclig5/new.enisey-m.ru/public_html',
            'SITEMAP_NAME' => 'sitemap.xml',
            'SPLIT_THRESHOLD' => 50000,
            'VALIDATE_HTTP' => false,    // выключаем предыдущую сетевую проверку
            'SAFE_HTTP_ON_MISS' => true,     // разрешить ОДИН короткий HTTP-пинг только если Битрикс-проверка не уверена
            'SAFE_HTTP_TIMEOUT' => 3,
            'SAFE_HTTP_BLACKLIST' => ['/contacts', '/personal', '/order', '/cabinet'],   // включить финальную проверку
            'VALIDATE_CONC' => 20,     // одновременных соединений
            'VALIDATE_TIMEOUT' => 8,     // таймаут на URL
            'VALIDATE_HTML_ONLY' => true,  // отфильтровать не-HTML
            'LOG_FILE' => $_SERVER['DOCUMENT_ROOT'] . '/local/logs/sitemap_skip.log',
            'DEBUG_REPORT' => true,
            'DEBUG_VERBOSE' => true,
            'RESPECT_META_ROBOTS' => true,

            // Веб-запуск (опционально)
            'AUTH_TOKEN' => 'YOUR-SECRET-CODE',

            'SEF_MAP' => [
                ['prefix' => '/content', 'iblock' => 4, 'type' => 'section'],
                ['prefix' => '/content', 'iblock' => 4, 'type' => 'element'],
                ['prefix' => '/content', 'iblock' => 9, 'type' => 'section'],
                ['prefix' => '/content', 'iblock' => 9, 'type' => 'element'],
                ['prefix' => '/content', 'iblock' => 10, 'type' => 'section'],
                ['prefix' => '/content', 'iblock' => 10, 'type' => 'element'],
                ['prefix' => '/content', 'iblock' => 11, 'type' => 'section'],
                ['prefix' => '/content', 'iblock' => 11, 'type' => 'element'],
                ['prefix' => '/spestehnika', 'iblock' => 12, 'type' => 'section'],
                ['prefix' => '/spestehnika', 'iblock' => 12, 'type' => 'element'],
                ['prefix' => '/navesnoe-oborudovanie', 'iblock' => 13, 'type' => 'section'],
                ['prefix' => '/navesnoe-oborudovanie', 'iblock' => 13, 'type' => 'element'],
                ['prefix' => '/malaya-mekhanizatsiya', 'iblock' => 14, 'type' => 'section'],
                ['prefix' => '/malaya-mekhanizatsiya', 'iblock' => 14, 'type' => 'element'],
                ['prefix' => '/news', 'iblock' => 15, 'type' => 'element'],
                ['prefix' => '/photogallery', 'iblock' => 18, 'type' => 'element'],
                ['prefix' => '/arenda', 'iblock' => 20, 'type' => 'element'],
                ['prefix' => '/articles', 'iblock' => 21, 'type' => 'element'],
                ['prefix' => '/zp', 'iblock' => 22, 'type' => 'section'],
                ['prefix' => '/zp', 'iblock' => 22, 'type' => 'element'],
                ['prefix' => '/videogallery', 'iblock' => 23, 'type' => 'element'],
                ['prefix' => '/news', 'iblock' => 24, 'type' => 'element'],
                ['prefix' => '/service', 'iblock' => 25, 'type' => 'element'],
                ['prefix' => '/photogallery', 'iblock' => 26, 'type' => 'element'],
                ['prefix' => '/photogallery', 'iblock' => 28, 'type' => 'element'],
                ['prefix' => '/news', 'iblock' => 29, 'type' => 'element'],
                ['prefix' => '/catalog', 'iblock' => 30, 'type' => 'element'],
                ['prefix' => '/o-kompanii', 'iblock' => 50, 'type' => 'element'],
                ['prefix' => '/company', 'iblock' => 55, 'type' => 'element'],
                ['prefix' => '/o-kompanii', 'iblock' => 57, 'type' => 'element'],
                ['prefix' => '/company', 'iblock' => 58, 'type' => 'element'],
                ['prefix' => '/contacts', 'iblock' => 60, 'type' => 'section'],
                ['prefix' => '/contacts', 'iblock' => 60, 'type' => 'element'],
                ['prefix' => '/zp', 'iblock' => 65, 'type' => 'section'],
                ['prefix' => '/zp', 'iblock' => 65, 'type' => 'element'],
                ['prefix' => '/o-kompanii', 'iblock' => 66, 'type' => 'element'],
                ['prefix' => '/articles', 'iblock' => 67, 'type' => 'section'],
                ['prefix' => '/articles', 'iblock' => 67, 'type' => 'element'],
                ['prefix' => '/news', 'iblock' => 68, 'type' => 'section'],
                ['prefix' => '/sales', 'iblock' => 69, 'type' => 'section'],
                ['prefix' => '/sales', 'iblock' => 69, 'type' => 'element'],
                ['prefix' => '/company', 'iblock' => 70, 'type' => 'element'],
                ['prefix' => '/projects', 'iblock' => 71, 'type' => 'section'],
                ['prefix' => '/projects', 'iblock' => 71, 'type' => 'element'],
                ['prefix' => '/uslugi', 'iblock' => 72, 'type' => 'section'],
                ['prefix' => '/uslugi', 'iblock' => 72, 'type' => 'element'],
                ['prefix' => '/spestehnika', 'iblock' => 73, 'type' => 'section'],
                ['prefix' => '/spestehnika', 'iblock' => 73, 'type' => 'element'],
                ['prefix' => '/cabinet', 'iblock' => 75, 'type' => 'section'],
                ['prefix' => '/cabinet', 'iblock' => 75, 'type' => 'element'],
            ],

        ];

// Ограничить веб-доступ
        if (php_sapi_name() !== 'cli') {
            if (!isset($_GET['token']) || $_GET['token'] !== $CONFIG['AUTH_TOKEN']) {
                http_response_code(403);
                echo 'Forbidden';
                exit;
            }
        }

// ========================= HELPERS =========================

        /**
         * Разобрать абсолютный URL на относительный путь и сегменты.
         */
        if (!function_exists(__NAMESPACE__ . '\\url_path_parts')) {
            function url_path_parts(string $abs): array
            {
                $path = parse_url($abs, PHP_URL_PATH) ?: '/';
                $path = preg_replace('#/+#', '/', $path);
                $path = rtrim($path, '/') . '/';
                $parts = array_values(array_filter(explode('/', $path)));
                return [$path, $parts];
            }
        }

        /**
         * Найти ID раздела по цепочке CODE-ов (SECTION_CODE_PATH).
         * Возвращает sectionId или null.
         */
        if (!function_exists(__NAMESPACE__ . '\\findSectionByCodePath')) {
            function findSectionByCodePath(int $iblockId, array $codes): ?int
            {
                $parent = 0;
                foreach ($codes as $code) {
                    $res = \CIBlockSection::GetList([], [
                        'IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'GLOBAL_ACTIVE' => 'Y',
                        'CODE' => $code, 'SECTION_ID' => $parent
                    ], false, ['ID', 'IBLOCK_SECTION_ID']);
                    if (!($row = $res->Fetch())) return null;
                    $parent = (int)$row['ID'];
                }
                return $parent ?: null;
            }
        }

        /**
         * Проверить существование ЭЛЕМЕНТА по пути вида /prefix/.../#ELEMENT_CODE#/
         * 1) Вычисляем SECTION_CODE_PATH, берем последний сегмент как ELEMENT_CODE
         * 2) Ищем активный элемент в найденном разделе
         */
        if (!function_exists(__NAMESPACE__ . '\\bitrixElementExistsByPath')) {
            function bitrixElementExistsByPath(int $iblockId, string $absUrl, string $prefix = ''): bool
            {
                [, $parts] = url_path_parts($absUrl);
                if ($prefix) {
                    $pref = trim($prefix, '/');
                    if ($parts && $parts[0] === $pref) array_shift($parts);
                }

                if (count($parts) < 1) return false;

                $elCode = array_pop($parts);
                if ($elCode === '') return false;

                $sectionId = null;
                if ($parts) {
                    $sectionId = findSectionByCodePath($iblockId, $parts);
                    if ($sectionId === null) return false;
                }

                $filter = ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'CODE' => $elCode];
                if ($sectionId) $filter['SECTION_ID'] = $sectionId;

                $res = \CIBlockElement::GetList([], $filter, false, ['nTopCount' => 1], ['ID']);
                return (bool)$res->Fetch();
            }
        }

        /**
         * Проверить существование РАЗДЕЛА по пути /prefix/#SECTION_CODE_PATH#/
         */

        if (!function_exists(__NAMESPACE__ . '\\bitrixSectionExistsByPath')) {
            function bitrixSectionExistsByPath(int $iblockId, string $absUrl, string $prefix = ''): bool
            {
                [, $parts] = url_path_parts($absUrl);
                if ($prefix) {
                    $pref = trim($prefix, '/');
                    if ($parts && $parts[0] === $pref) array_shift($parts);
                }
                if (!$parts) return false;
                $sectionId = findSectionByCodePath($iblockId, $parts);
                return $sectionId !== null;
            }
        }

        /**
         * Универсальная проверка URL на «существует/404» без HTTP‑запроса.
         * Возвращает true если точно существует, false если точно нет, null если не смогли определить.
         *
         * Для определения используем карту «префикс → ИБ → шаблон».
         * Если URL не подходит под известные шаблоны — возвращаем null (можно добить safe HTTP).
         */
        if (!function_exists(__NAMESPACE__ . '\\bitrixUrlExists')) {
            function bitrixUrlExists(string $absUrl, array $map): ?bool
            {
                foreach ($map as $rule) {
                    $prefix = rtrim($rule['prefix'] ?? '/', '/');
                    $type = $rule['type'];
                    $ibId = (int)$rule['iblock'];
                    if (strpos($absUrl, $prefix . '/') !== 0) continue;
                    if ($type === 'element') return bitrixElementExistsByPath($ibId, $absUrl, $prefix);
                    else                      return bitrixSectionExistsByPath($ibId, $absUrl, $prefix);
                }
                return null;
            }// не распознали маршрут
        }

        if (!function_exists(__NAMESPACE__ . '\\http_head_or_get_small')) {
            function http_head_or_get_small(string $url, string $ua, int $timeout, bool $follow = true): array
            {
                // HEAD
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 3,
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout,
                    CURLOPT_USERAGENT => $ua, CURLOPT_HEADER => true,
                ]);
                $resp = curl_exec($ch);
                $info = curl_getinfo($ch);
                curl_close($ch);
                $status = (int)($info['http_code'] ?? 0);
                $ctype = strtolower($info['content_type'] ?? '');
                $final = $info['url'] ?? $url;

                if ($status === 405 || $status === 403 || $status === 0) {
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_NOBODY => false, CURLOPT_HTTPHEADER => ['Range: bytes=0-0'],
                        CURLOPT_FOLLOWLOCATION => $follow, CURLOPT_MAXREDIRS => 3,
                        CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => $timeout, CURLOPT_TIMEOUT => $timeout,
                        CURLOPT_USERAGENT => $ua, CURLOPT_HEADER => true,
                    ]);
                    $resp = curl_exec($ch);
                    $info = curl_getinfo($ch);
                    curl_close($ch);
                    $status = (int)($info['http_code'] ?? 0);
                    $ctype = strtolower($info['content_type'] ?? $ctype);
                    $final = $info['url'] ?? $final;
                }
                return ['status' => $status, 'ctype' => $ctype, 'final' => $final];
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\validate_urls')) {
            function validate_urls(array $urls, array $cfg): array
            {
                // Параллельная проверка batched + HEAD→GET fallback по месту
                $concurrency = max(1, (int)$cfg['VALIDATE_CONC']);
                $timeout = max(2, (int)$cfg['VALIDATE_TIMEOUT']);
                $ua = $cfg['user_agent'] ?? 'SitemapBot/1.0';

                $kept = [];
                $batch = [];
                $i = 0;

                $emitLog = function ($line) use ($cfg) {
                    if (!empty($cfg['DEBUG_VERBOSE'])) {
                        $ts = '[' . gmdate('c') . '] ';
                        if (!empty($cfg['LOG_FILE'])) {
                            @mkdir(dirname($cfg['LOG_FILE']), 0775, true);
                            @file_put_contents($cfg['LOG_FILE'], $ts . $line . PHP_EOL, FILE_APPEND);
                        }
                    }
                    error_log($line);
                };

                // Простая батч‑обработка (без curl_multi для компактности и надёжности)
                foreach ($urls as $loc => $meta) {
                    $res = http_head_or_get_small($loc, $ua, $timeout, true);

                    // follow до финала: берём только если финальный статус 200
                    $isOk200 = ($res['status'] === 200);
                    $isHtml = (!$cfg['VALIDATE_HTML_ONLY']) || (
                            strpos($res['ctype'], 'text/html') !== false || strpos($res['ctype'], 'application/xhtml+xml') !== false
                        );

                    if ($isOk200 && $isHtml) {
                        $kept[$loc] = $meta;
                        $emitLog("keep: {$loc} [{$res['status']} {$res['ctype']}]");
                    } else {
                        $emitLog("drop: {$loc} [status={$res['status']}, ctype={$res['ctype']}, final={$res['final']}]");
                    }
                }
                return $kept;
            }

        }


        if (!function_exists(__NAMESPACE__ . '\\logSkip')) {
            function logSkip(string $why, array $ctx, array $cfg): void
            {
                logDecision('skip:' . $why, $ctx, $cfg);
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\dbg_write')) {
            function dbg_write(string $line, array $cfg): void
            {
                $ts = '[' . gmdate('c') . '] ';
                $msg = $ts . $line . PHP_EOL;

                // В файл
                if (!empty($cfg['LOG_FILE'])) {
                    @mkdir(dirname($cfg['LOG_FILE']), 0775, true);
                    @file_put_contents($cfg['LOG_FILE'], $msg, FILE_APPEND);
                }
                error_log($line);
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\logDecision')) {
            function logDecision(string $status, array $ctx, array $cfg): void
            {
                if (empty($cfg['DEBUG_VERBOSE'])) return;
                $line = $status . ' :: ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
                dbg_write($line, $cfg);
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\normalize_url')) {
            function normalize_url(string $loc, string $base): ?string
            {
                $loc = trim($loc);
                if ($loc === '') return null;

                // Относительное -> абсолютное
                if (parse_url($loc, PHP_URL_SCHEME) === null) {
                    if ($loc[0] !== '/') $loc = '/' . $loc;
                    $loc = rtrim($base, '/') . $loc;
                }

                // Убираем фрагменты
                $parts = parse_url($loc);
                if (!$parts || empty($parts['host'])) return null;

                $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'https';
                $host = strtolower($parts['host']);
                $path = isset($parts['path']) ? preg_replace('#/+#', '/', $parts['path']) : '/';
                $query = $parts['query'] ?? null;

                if ($path !== '/' && substr($path, -1) === '/') {
                    $path = rtrim($path, '/') . '/'; // для красивых слешевых URL сохраняем / на конце
                }
                $u = $scheme . '://' . $host . $path;
                if ($query) $u .= '?' . $query;
                return $u;
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\getSectionCodePath')) {
            function getSectionCodePath(int $sectionId, int $iblockId): string
            {
                static $cache = [];
                $key = $iblockId . ':' . $sectionId;
                if (isset($cache[$key])) return $cache[$key];
                $path = [];
                $nav = \CIBlockSection::GetNavChain($iblockId, $sectionId, ['ID', 'CODE']);
                while ($s = $nav->GetNext()) {
                    if (!empty($s['CODE'])) $path[] = $s['CODE'];
                }
                return $cache[$key] = implode('/', $path);
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\getElementPrimarySectionId')) {
            function getElementPrimarySectionId(int $elementId, int $iblockId): ?int
            {
                // Берём самую глубокую активную секцию
                $res = \CIBlockElement::GetElementGroups($elementId, true, ['ID', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'ACTIVE', 'GLOBAL_ACTIVE']);
                $bestId = null;
                $bestDepth = -1;
                while ($g = $res->Fetch()) {
                    if ($g['ACTIVE'] !== 'Y' || $g['GLOBAL_ACTIVE'] !== 'Y') continue;
                    $depth = (int)$g['DEPTH_LEVEL'];
                    if ($depth > $bestDepth) {
                        $bestDepth = $depth;
                        $bestId = (int)$g['ID'];
                    }
                }
                return $bestId;
            }
        }

        class RobotsTxt
        {
            private string $base;
            private array $rules = []; // allow/disallow regex

            public function __construct(string $baseUrl)
            {
                $this->base = rtrim($baseUrl, '/');
                $this->load();
            }

            private function load(): void
            {
                $url = $this->base . '/robots.txt';
                $txt = @file_get_contents($url);
                if ($txt === false) return;
                $ua = '*';
                foreach (preg_split('/\r\n|\r|\n/', $txt) as $line) {
                    $line = trim(preg_split('/#/', $line)[0] ?? '');
                    if ($line === '') continue;
                    if (stripos($line, 'User-agent:') === 0) {
                        $ua = strtolower(trim(substr($line, strlen('User-agent:')))) ?: '*';
                        continue;
                    }
                    if (stripos($line, 'Allow:') === 0 || stripos($line, 'Disallow:') === 0) {
                        [$k, $v] = array_map('trim', explode(':', $line, 2));
                        $k = strtolower($k);
                        $v = $v !== '' ? $v : '/';
                        $pattern = preg_quote($v, '#');
                        $pattern = str_replace('\*', '.*', $pattern);
                        if (substr($pattern, -2) === '\$') $pattern = substr($pattern, 0, -2) . '$';
                        $regex = '#^' . $pattern . '#';
                        $this->rules[$ua][] = [$k, $regex, $v];
                    }
                }
            }

            public function allowed(string $absUrl, string $ua = '*'): bool
            {
                $path = parse_url($absUrl, PHP_URL_PATH) ?? '/';
                $ua = strtolower($ua);
                $rules = array_merge($this->rules[$ua] ?? [], $this->rules['*'] ?? []);
                if (!$rules) return true;
                usort($rules, fn($a, $b) => strlen($b[2]) <=> strlen($a[2]));
                foreach ($rules as [$type, $regex, $_]) {
                    if (preg_match($regex, $path)) return $type === 'allow';
                }
                return true;
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\isNoindexByIpropForElement')) {
            function isNoindexByIpropForElement(int $iblockId, int $elementId): bool
            {
                $iprop = new ElementValues($iblockId, $elementId);
                $vals = $iprop->getValues();
                $robots = strtolower($vals['ELEMENT_META_ROBOTS'] ?? '');
                return ($robots && (strpos($robots, 'noindex') !== false || strpos($robots, 'none') !== false));
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\isNoindexByIpropForSection')) {
            function isNoindexByIpropForSection(int $iblockId, int $sectionId): bool
            {
                $iprop = new SectionValues($iblockId, $sectionId);
                $vals = $iprop->getValues();
                $robots = strtolower($vals['SECTION_META_ROBOTS'] ?? '');
                return ($robots && (strpos($robots, 'noindex') !== false || strpos($robots, 'none') !== false));
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\write_atomic')) {
            function write_atomic(string $path, string $content): bool
            {
                $tmp = $path . '.tmp';
                $ok = (bool)@file_put_contents($tmp, $content);
                if (!$ok) return false;
                $ok = @rename($tmp, $path);
                if ($ok) {
                    @chmod($path, 0664);
                }
                return $ok;
            }
        }

        if (!function_exists(__NAMESPACE__ . '\\writeSitemaps')) {
            function writeSitemaps(array $urls, array $cfg): void
            {
                ksort($urls, SORT_STRING);

                $dir = rtrim($cfg['OUTPUT_DIR'], DIRECTORY_SEPARATOR);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }

                // 1 файл
                if (count($urls) <= (int)$cfg['SPLIT_THRESHOLD']) {
                    $xml = new \XMLWriter();
                    $buf = '';
                    $xml->openMemory();
                    $xml->startDocument('1.0', 'UTF-8');
                    $xml->setIndent(true);
                    $xml->startElement('urlset');
                    $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

                    foreach ($urls as $loc => $meta) {
                        $xml->startElement('url');
                        $xml->writeElement('loc', $loc);
                        if (!empty($meta['lastmod'])) $xml->writeElement('lastmod', $meta['lastmod']);
                        if (!empty($meta['changefreq'])) $xml->writeElement('changefreq', $meta['changefreq']);
                        if (!is_null($meta['priority'])) $xml->writeElement('priority', number_format((float)$meta['priority'], 1));
                        $xml->endElement();
                    }
                }

                $xml->endElement();
                $xml->endDocument();
                $buf = $xml->outputMemory();

                if (!write_atomic($dir . '/' . $cfg['SITEMAP_NAME'], $buf)) {
                    @file_put_contents($cfg['LOG_FILE'], '[' . date('c') . "] ERROR: write sitemap.xml failed\n", FILE_APPEND);
                }
                return;


                // Несколько файлов: пишем sitemap-*.xml + sitemap-index.xml, И ТАКЖЕ генерим sitemap.xml как ИНДЕКС (БЕЗ symlink)
                $chunks = array_chunk($urls, (int)$cfg['SPLIT_THRESHOLD'], true);
                $indexEntries = [];

                foreach ($chunks as $i => $chunk) {
                    $fname = 'sitemap-' . ($i + 1) . '.xml';
                    $xml = new \XMLWriter();
                    $xml->openMemory();
                    $xml->startDocument('1.0', 'UTF-8');
                    $xml->setIndent(true);
                    $xml->startElement('urlset');
                    $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
                    foreach ($chunk as $loc => $meta) {
                        $xml->startElement('url');
                        $xml->writeElement('loc', $loc);
                        if (!empty($meta['lastmod'])) $xml->writeElement('lastmod', $meta['lastmod']);
                        if (!empty($meta['changefreq'])) $xml->writeElement('changefreq', $meta['changefreq']);
                        if (!is_null($meta['priority'])) $xml->writeElement('priority', number_format((float)$meta['priority'], 1));
                        $xml->endElement();
                    }
                    $xml->endElement();
                    $xml->endDocument();

                    if (!write_atomic($dir . '/' . $fname, $xml->outputMemory())) {
                        @file_put_contents($cfg['LOG_FILE'], '[' . date('c') . "] ERROR: write $fname failed\n", FILE_APPEND);
                    }

                    $indexEntries[] = $fname;
                }

                // sitemap-index.xml
                $xml = new \XMLWriter();
                $xml->openMemory();
                $xml->startDocument('1.0', 'UTF-8');
                $xml->setIndent(true);
                $xml->startElement('sitemapindex');
                $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
                $base = rtrim($cfg['BASE_URL'], '/') . '/';

                foreach ($indexEntries as $fname) {
                    $xml->startElement('sitemap');
                    $xml->writeElement('loc', $base . $fname);
                    $xml->writeElement('lastmod', gmdate('Y-m-d\TH:i:s\Z'));
                    $xml->endElement();
                }
                $xml->endElement();
                $xml->endDocument();
                $indexXml = $xml->outputMemory();

                // Пишем индекс
                if (!write_atomic($dir . '/sitemap-index.xml', $indexXml)) {
                    @file_put_contents($cfg['LOG_FILE'], '[' . date('c') . "] ERROR: write sitemap-index.xml failed\n", FILE_APPEND);
                }

                // Пишем ТАК ЖЕ sitemap.xml с тем же содержимым индекса (без симлинка)
                if (!write_atomic($dir . '/' . $cfg['SITEMAP_NAME'], $indexXml)) {
                    @file_put_contents($cfg['LOG_FILE'], '[' . date('c') . "] ERROR: write sitemap.xml (index mode) failed\n", FILE_APPEND);
                }
            }
        }

// ========================= COLLECT =========================
        $BASE = $CONFIG['BASE_URL'];
        $index = []; // 'absUrl' => ['lastmod'=>..., 'changefreq'=>..., 'priority'=>...]

// robots
        $robots = $CONFIG['RESPECT_ROBOTS'] ? new RobotsTxt($BASE) : null;

// статические ссылки
        foreach ($CONFIG['STATIC_URLS'] as $u) {
            $loc = normalize_url($u, $BASE);
            if (!$loc) continue;
            if ($CONFIG['EXCLUDE_QUERY'] && parse_url($loc, PHP_URL_QUERY)) continue;
            if ($robots && !$robots->allowed($loc)) continue;

            $index[$loc] = [
                'lastmod' => ($CONFIG['LASTMOD'] === 'now') ? gmdate('Y-m-d\TH:i:s\Z') : null,
                'changefreq' => null,
                'priority' => null,
            ];
        }

// ссылки из меню (опционально)
        foreach ($CONFIG['MENU_TYPES'] as $mt) {
            $menu = new \CMenu($mt['type']);
            $menu->Init($mt['dir'] ?? '/', true, $mt['dir'] ?? '/');
            foreach ($menu->arMenu as $item) {
                $href = $item[1] ?? '';
                $loc = normalize_url($href, $BASE);
                if (!$loc) continue;
                if ($CONFIG['EXCLUDE_QUERY'] && parse_url($loc, PHP_URL_QUERY)) continue;
                if ($robots && !$robots->allowed($loc)) continue;
                $index[$loc] = $index[$loc] ?? ['lastmod' => ($CONFIG['LASTMOD'] === 'now' ? gmdate('Y-m-d\TH:i:s\Z') : null), 'changefreq' => null, 'priority' => null];
            }
        }

// какие инфоблоки
        $iblockFilter = ['ACTIVE' => 'Y'];
        if ($CONFIG['IBLOCK_IDS']) $iblockFilter['ID'] = $CONFIG['IBLOCK_IDS'];

        $debugStats = [];

        $ibRes = \CIBlock::GetList([], $iblockFilter);
        while ($ib = $ibRes->Fetch()) {
            $iblockId = (int)$ib['ID'];

            $debugStats[$iblockId] = ['name' => $ib['NAME'], 'active_total' => 0, 'urls_emitted' => 0];

            // -------- Разделы --------
            if ($CONFIG['INCLUDE_SECTIONS']) {
                db_ping();
                $secRes = \CIBlockSection::GetList(
                    ['LEFT_MARGIN' => 'ASC'],
                    ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y', 'GLOBAL_ACTIVE' => 'Y'],
                    false,
                    ['ID', 'IBLOCK_ID', 'CODE', 'SECTION_PAGE_URL', 'TIMESTAMP_X', 'DEPTH_LEVEL', 'IBLOCK_SECTION_ID']
                );
                while ($sec = $secRes->GetNext()) {
                    $urlTpl = $sec['SECTION_PAGE_URL'] ?: \CIBlock::GetArrayByID($iblockId, "SECTION_PAGE_URL");
                    if (!$urlTpl || trim($urlTpl) === '') {
                        $urlTpl = '/#SECTION_CODE_PATH#';
                    }
                    $secForUrl = $sec;
                    if (strpos($urlTpl, '#SECTION_CODE_PATH#') !== false) {
                        $secForUrl['SECTION_CODE_PATH'] = getSectionCodePath((int)$sec['ID'], $iblockId);
                    }
                    $rel = \CIBlock::ReplaceSectionUrl($urlTpl, $secForUrl, true, 'S');
                    $loc = normalize_url($rel, $BASE);
                    if (!$loc) continue;
                    if ($CONFIG['EXCLUDE_QUERY'] && parse_url($loc, PHP_URL_QUERY)) continue;
                    if ($robots && !$robots->allowed($loc)) continue;

                    if ($CONFIG['RESPECT_META_ROBOTS'] && isNoindexByIpropForSection($iblockId, (int)$sec['ID'])) {
                        continue;
                    }

                    $lastmod = null;
                    if ($CONFIG['LASTMOD'] === 'iblock' && !empty($sec['TIMESTAMP_X'])) {
                        $ts = strtotime($sec['TIMESTAMP_X']);
                        if ($ts) $lastmod = gmdate('Y-m-d\TH:i:s\Z', $ts);
                    } elseif ($CONFIG['LASTMOD'] === 'now') {
                        $lastmod = gmdate('Y-m-d\TH:i:s\Z');
                    }

                    $index[$loc] = [
                        'lastmod' => $lastmod,
                        'changefreq' => null,
                        'priority' => null,
                    ];
                }
            }

            // -------- Элементы --------
            if ($CONFIG['INCLUDE_ELEMENTS']) {
                db_ping();
                $elRes = \CIBlockElement::GetList(
                    ['ID' => 'ASC'],
                    [
                        'IBLOCK_ID' => $iblockId,
                        'ACTIVE' => 'Y',
                        // 'ACTIVE_DATE' => 'Y',      // включите, если используете даты активности
                        // 'CHECK_PERMISSIONS' => 'N',// в CLI можно не проверять права
                    ],
                    false,
                    false,
                    ['ID', 'IBLOCK_ID', 'CODE', 'NAME', 'DETAIL_PAGE_URL', 'TIMESTAMP_X', 'IBLOCK_SECTION_ID']
                );

                while ($el = $elRes->GetNext()) {
                    $debugStats[$iblockId]['elements']++;

                    // 1) Шаблон URL деталки
                    $urlTpl = $el['DETAIL_PAGE_URL'] ?: \CIBlock::GetArrayByID($iblockId, "DETAIL_PAGE_URL");
                    // Fallback, если в инфоблоке пусто
                    if (!$urlTpl || trim($urlTpl) === '') {
                        $urlTpl = '/#SECTION_CODE_PATH#/#ELEMENT_CODE#/';
                    }

                    // 2) Собираем список всех активных секций элемента (для шаблонов с SECTION_CODE_PATH)
                    $sectIds = [];
                    $groups = \CIBlockElement::GetElementGroups((int)$el['ID'], true, ['ID', 'DEPTH_LEVEL', 'ACTIVE', 'GLOBAL_ACTIVE']);
                    while ($g = $groups->Fetch()) {
                        if ($g['ACTIVE'] === 'Y' && $g['GLOBAL_ACTIVE'] === 'Y') {
                            $sectIds[(int)$g['ID']] = (int)$g['DEPTH_LEVEL'];
                        }
                    }
                    // fallback, если к секциям не привязан
                    if (!$sectIds && (int)$el['IBLOCK_SECTION_ID'] > 0) {
                        $sectIds[(int)$el['IBLOCK_SECTION_ID']] = 0;
                    }

                    // 3) Если шаблон БЕЗ SECTION_CODE_PATH — генерим единожды
                    if (strpos($urlTpl, '#SECTION_CODE_PATH#') === false) {
                        $rel = \CIBlock::ReplaceDetailUrl($urlTpl, $el, true, 'E');
                        $loc = normalize_url($rel, $BASE);
                        if (!$loc) {
                            logSkip('bad-url', ['elId' => $el['ID'], 'tpl' => $urlTpl, 'rel' => $rel], $CONFIG);
                            continue;
                        }
                        if ($CONFIG['EXCLUDE_QUERY'] && parse_url($loc, PHP_URL_QUERY)) {
                            logSkip('query-excluded', ['loc' => $loc], $CONFIG);
                            continue;
                        }
                        if ($robots && !$robots->allowed($loc)) {
                            logSkip('robots-disallow', ['loc' => $loc], $CONFIG);
                            continue;
                        }
                        if ($CONFIG['RESPECT_META_ROBOTS'] && isNoindexByIpropForElement($iblockId, (int)$el['ID'])) {
                            logSkip('meta-robots-noindex', ['loc' => $loc], $CONFIG);
                            continue;
                        }

                        $lastmod = null;
                        if ($CONFIG['LASTMOD'] === 'iblock' && !empty($el['TIMESTAMP_X'])) {
                            $ts = strtotime($el['TIMESTAMP_X']);
                            if ($ts) $lastmod = gmdate('Y-m-d\TH:i:s\Z', $ts);
                        } elseif ($CONFIG['LASTMOD'] === 'now') {
                            $lastmod = gmdate('Y-m-d\TH:i:s\Z');
                        }

                        if (!isset($index[$loc])) {
                            $index[$loc] = ['lastmod' => $lastmod, 'changefreq' => null, 'priority' => null];
                            $debugStats[$iblockId]['emit']++;
                            $debugStats[$iblockId]['sections']++;
                            logDecision('emit:section', ['loc' => $loc, 'iblock' => $iblockId, 'secId' => $sec['ID']], $CONFIG);
                        } else {
                            logDecision('dup:section', ['loc' => $loc, 'iblock' => $iblockId, 'secId' => $sec['ID']], $CONFIG);
                        }

                        $index[$loc] = ['lastmod' => $lastmod, 'changefreq' => null, 'priority' => null];
                        continue;
                    }

                    // 4) Шаблон С SECTION_CODE_PATH — генерим URL для КАЖДОЙ привязки (fan-out)
                    arsort($sectIds, SORT_NUMERIC); // сначала более глубокие пути

                    foreach (array_keys($sectIds) as $secId) {
                        $elForUrl = $el;
                        $elForUrl['IBLOCK_SECTION_ID'] = $secId;
                        $elForUrl['SECTION_CODE_PATH'] = getSectionCodePath($secId, $iblockId);

                        $rel = \CIBlock::ReplaceDetailUrl($urlTpl, $elForUrl, true, 'E');
                        $loc = normalize_url($rel, $BASE);
                        if (!$loc) {
                            logSkip('bad-url', ['elId' => $el['ID'], 'secId' => $secId, 'tpl' => $urlTpl, 'rel' => $rel], $CONFIG);
                            continue;
                        }
                        if ($CONFIG['EXCLUDE_QUERY'] && parse_url($loc, PHP_URL_QUERY)) {
                            logSkip('query-excluded', ['loc' => $loc, 'elId' => $el['ID']], $CONFIG);
                            continue;
                        }
                        if ($robots && !$robots->allowed($loc)) {
                            logSkip('robots-disallow', ['loc' => $loc, 'secId' => $secId], $CONFIG);
                            continue;
                        }
                        if ($CONFIG['RESPECT_META_ROBOTS'] && isNoindexByIpropForElement($iblockId, (int)$el['ID'])) {
                            logSkip('meta-robots-noindex', ['loc' => $loc, 'elId' => $el['ID']], $CONFIG);
                            continue;
                        }

                        $lastmod = null;
                        if ($CONFIG['LASTMOD'] === 'iblock' && !empty($el['TIMESTAMP_X'])) {
                            $ts = strtotime($el['TIMESTAMP_X']);
                            if ($ts) $lastmod = gmdate('Y-m-d\TH:i:s\Z', $ts);
                        } elseif ($CONFIG['LASTMOD'] === 'now') {
                            $lastmod = gmdate('Y-m-d\TH:i:s\Z');
                        }

                        $index[$loc] = ['lastmod' => $lastmod, 'changefreq' => null, 'priority' => null]; // дедуп по ключу
                        // Если нужно включать только «самый глубокий» путь — раскомментируйте:
                        // break;
                    }
                }
                $debugStats[$iblockId]['active_total']++;
            }
        }

// ========================= WRITE =========================

        $must = [
            'https://enisey-m.ru/articles/drugoe/',
            'https://enisey-m.ru/articles/drugoe/shtabeler-elektricheskiy-ep-es15-15cs-idealnyy-vybor-dlya-vashego-sklada/',
            // добавьте ещё 2–3 любых проблемных
        ];
        foreach ($must as $u) {
            dbg_write('PROBE ' . (isset($index[$u]) ? 'OK ' : 'MISS ') . $u, $CONFIG);
        }
        if (!empty($CONFIG['DEBUG_REPORT'])) {
            dbg_write('=== SITEMAP DEBUG REPORT ===', $CONFIG);
            foreach ($debugStats as $bid => $st) {
                dbg_write(sprintf(
                    "IBLOCK #%d (%s): elements=%d, sections=%d, EMIT=%d, SKIP=%d",
                    $bid, $st['name'], $st['elements'], $st['sections'], $st['emit'], $st['skip']
                ), $CONFIG);
            }
            dbg_write('=== END DEBUG REPORT ===', $CONFIG);
        }

        $articlesTotal = 0;
        foreach ($index as $loc => $_) {
            if (strpos($loc, 'https://enisey-m.ru/articles/') === 0) $articlesTotal++;
        }
        dbg_write("ARTICLES URLs IN SITEMAP: " . $articlesTotal, $CONFIG);

        ksort($index, SORT_STRING);

// Шаг 1: фильтр без HTTP — только проверка существования по Битрикс-БД
        $kept = [];
        $removed = 0;
        $ua = $CONFIG['user_agent'] ?? 'SitemapBot/1.0';

        foreach ($index as $loc => $meta) {
            // чёрный список для HTTP‑пинга
            $inBlacklist = false;
            foreach ($CONFIG['SAFE_HTTP_BLACKLIST'] as $p) {
                $p = rtrim($p, '/');
                if ($p !== '' && strpos($loc, rtrim($CONFIG['BASE_URL'], '/') . $p) === 0) {
                    $inBlacklist = true;
                    break;
                }
            }

            // 1) пробуем распознать маршрут и проверить через Битрикс
            $exists = bitrixUrlExists($loc, $CONFIG['SEF_MAP']); // true|false|null
            if ($exists === true) {
                $kept[$loc] = $meta;
                logDecision('keep:bitrix', ['loc' => $loc], $CONFIG);
                continue;
            }
            if ($exists === false) {
                $removed++;
                logDecision('drop:bitrix404', ['loc' => $loc], $CONFIG);
                continue;
            }

            // 2) маршрут не распознан — аккуратный HTTP‑пинг (если разрешено и не в blacklist)
            if (!empty($CONFIG['SAFE_HTTP_ON_MISS']) && !$inBlacklist) {
                $res = http_head_or_get_small($loc, $ua, (int)$CONFIG['SAFE_HTTP_TIMEOUT'], true);
                $ok = ($res['status'] === 200) &&
                    (strpos($res['ctype'], 'text/html') !== false || strpos($res['ctype'], 'application/xhtml+xml') !== false);
                if ($ok) {
                    $kept[$loc] = $meta;
                    logDecision('keep:safehttp', ['loc' => $loc, 'status' => $res['status']], $CONFIG);
                } else {
                    $removed++;
                    logDecision('drop:safehttp', ['loc' => $loc, 'status' => $res['status'], 'final' => $res['final']], $CONFIG);
                }
            } else {
                $removed++;
                logDecision('drop:unknown', ['loc' => $loc], $CONFIG);
            }
        }

        if (php_sapi_name() !== 'cli') {
            http_response_code(200);
            echo "OK";
        }

        dbg_write('BITRIX-VALIDATION removed: ' . $removed, $CONFIG);
        $index = $kept;


        writeSitemaps($index, $CONFIG);

        // Ответ при веб‑запуске
        if (php_sapi_name() !== 'cli') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "OK. URLs: " . count($index) . PHP_EOL;
        }

    }

    if (!defined('SITEMAP_RUN')) {
        $isDirectScript = isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__);
        if ($isDirectScript) {
            define('SITEMAP_RUN', true);
        }
    }

    if (defined('SITEMAP_RUN')) {
        \Enisey\SitemapGen\run();
    }
}