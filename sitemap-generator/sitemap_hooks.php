<?php
namespace Enisey\Sitemap;

use Bitrix\Main\Context;

/**
 * Sitemap Hooks & Queue helper
 *
 * Файл: /local/tools/sitemap_hooks.php
 * Папка данных: /local/var/
 */
final class Hooks
{
    /** Секрет для вызова воркера по HTTP. ДОЛЖЕН совпадать с тем, что в sitemap_worker.php */
    private const SECRET = 'YOUR-SECRET-CODE';

    /** Базовая папка для служебных файлов */
    private static function varDir(): string
    {
        $dir = $_SERVER['DOCUMENT_ROOT'] . '/local/var';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /** Пути служебных файлов */
    private static function queuePath(): string     { return self::varDir() . '/sitemap-queue.json'; }
    private static function lockPath(): string      { return self::varDir() . '/sitemap.lock'; }
    private static function logPath(): string       { return self::varDir() . '/sitemap.log'; }
    private static function lastKickPath(): string  { return self::varDir() . '/sitemap.lastkick'; }

    /* ==================== Публичные хэндлеры Bitrix ==================== */

    public static function onElementChange(array $fields): void
    {
        $id = (int)($fields['ID'] ?? 0);
        if ($id > 0) {
            self::enqueue('element', $id, 'upsert');
            self::maybeKickWorker();
        }
    }

    public static function onElementDelete(array $fields): void
    {
        $id = (int)($fields['ID'] ?? 0);
        if ($id > 0) {
            self::enqueue('element', $id, 'delete');
            self::maybeKickWorker();
        }
    }

    public static function onSectionChange(array $fields): void
    {
        $id = (int)($fields['ID'] ?? 0);
        if ($id > 0) {
            self::enqueue('section', $id, 'upsert');
            self::maybeKickWorker();
        }
    }

    public static function onSectionDelete(array $fields): void
    {
        $id = (int)($fields['ID'] ?? 0);
        if ($id > 0) {
            self::enqueue('section', $id, 'delete');
            self::maybeKickWorker();
        }
    }

    /* ==================== Очередь ==================== */

    /**
     * Кладём событие в очередь (с дедупликацией по ключу type:op:id).
     * Если хочешь коалесцировать всё в FULL_REBUILD — можно заменить реализацию тут.
     */
    private static function enqueue(string $type, int $id, string $op): void
    {
        $path = self::queuePath();
        $queue = [];

        if (is_file($path)) {
            $raw = (string)@file_get_contents($path);
            $queue = json_decode($raw, true) ?: [];
        }

        $key = "{$type}:{$op}:{$id}";
        $queue[$key] = [
            'type' => $type,     // element|section
            'op'   => $op,       // upsert|delete
            'id'   => $id,
            'ts'   => time(),
        ];

        @file_put_contents($path, json_encode($queue, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        self::log("enqueue {$type} {$op} id={$id}");
    }

    /** Прочитать всю очередь как список элементов */
    public static function loadQueue(): array
    {
        $path = self::queuePath();
        if (!is_file($path)) {
            return [];
        }
        $raw = (string)@file_get_contents($path);
        $arr = json_decode($raw, true) ?: [];
        return array_values($arr);
    }

    /** Полностью очистить очередь */
    public static function clearQueue(): void
    {
        @unlink(self::queuePath());
    }

    /* ==================== Лок (управляется генератором) ==================== */

    /** Проверка: активен ли лок (TTL в будущем) */
    public static function isLocked(): bool
    {
        $p = self::lockPath();
        if (!is_file($p)) {
            return false;
        }
        $exp = (int)trim((string)@file_get_contents($p));
        return ($exp > time());
    }

    /** Выставить лок на указанное количество секунд вперёд */
    public static function lockUntil(int $secondsFromNow): void
    {
        @file_put_contents(self::lockPath(), (string)(time() + $secondsFromNow), LOCK_EX);
    }

    /** Снять лок (удалить файл) */
    public static function unlock(): void
    {
        $p = self::lockPath();
        if (is_file($p)) {
            @unlink($p);
        }
    }

    /* ==================== Пинок воркера ==================== */

    /**
     * Умный пинок:
     * - если лок активен — ничего не делаем (генератор сам перепнет после завершения);
     * - антидребезг 3 секунды между пинками;
     * - fire-and-forget запрос на прод-домен.
     */
    private static function maybeKickWorker(): void
    {
        // Генерация уже идёт — просто копим очередь
        if (self::isLocked()) {
            return;
        }

        // Антидребезг
        $now = time();
        $lp  = self::lastKickPath();
        $last = (int)@file_get_contents($lp);
        if ($last && ($now - $last) < 3) {
            return;
        }
        @file_put_contents($lp, (string)$now, LOCK_EX);

        // Пинаем воркер по доменному URL (не ждём ответа)
        $host = 'YOUR_HOST';
        $port = 443;
        $path = '/local/tools/sitemap_worker.php?k=' . rawurlencode(self::secret()) . '&_t=' . $now;

        $errno = 0; $errstr = '';
        $fp = @fsockopen('ssl://' . $host, $port, $errno, $errstr, 1);
        if ($fp) {
            $req  = "GET {$path} HTTP/1.1\r\n";
            $req .= "Host: {$host}\r\n";
            $req .= "Connection: Close\r\n\r\n";
            fwrite($fp, $req);
            fclose($fp);
            self::log('kick worker');
        } else {
            self::log("kick worker failed: {$errno} {$errstr}");
        }
    }

    /* ==================== Сервис ==================== */

    /** Секрет для воркера */
    public static function secret(): string
    {
        return self::SECRET;
    }

    /** Логгер */
    private static function log(string $msg): void
    {
        @file_put_contents(
            self::logPath(),
            '[' . date('Y-m-d H:i:s') . "] {$msg}\n",
            FILE_APPEND
        );
    }
}
