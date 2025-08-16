<?php
// /local/tools/sitemap_worker.php
use Enisey\Sitemap\Hooks;

require_once $_SERVER['DOCUMENT_ROOT'] . '/local/tools/sitemap_hooks.php';

function logx(string $m): void {
    @file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/local/var/sitemap.log',
        '[' . date('Y-m-d H:i:s') . "] $m\n", FILE_APPEND);
}

// ---- HTTP защита по секрету
$k = $_GET['k'] ?? '';
if ($k !== Hooks::secret()) {
    http_response_code(403);
    echo "Forbidden";
    return;
}

// ВНИМАНИЕ: локом теперь управляет сам gen-site.php.
// Воркер только пинает генератор и выходит.

// ---- Пинаем строго внешний URL
$targetUrl = 'YOUR_HOST/local/tools/gen-site.php?token=YOUR-SECRET-CODEr&_t=' . time();

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $targetUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT        => 10,         // коротко: генератор сам ответит сразу и продолжит в фоне
    CURLOPT_HTTPHEADER     => ['Connection: close'],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$body   = curl_exec($ch);
$errno  = curl_errno($ch);
$error  = curl_error($ch);
$code   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

logx("WORKER: kick gen-site.php code={$code} errno={$errno} body_len=" . strlen((string)$body) . ($error ? " error={$error}" : ''));

// Никаких re-kick и работы с локом здесь — всё делает gen-site.php.
http_response_code(200);
echo "OK";
