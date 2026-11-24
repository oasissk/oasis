<?php
declare(strict_types=1);

// LINE Webhook の受け口。公開フォルダにはこのファイルだけを置く。
// src/・設定・鍵・記録は公開フォルダの外に置き、下の2か所で場所を指定する
// (環境変数 LRL_SRC / LRL_CONFIG があればそちらを使う)。
$src    = getenv('LRL_SRC') ?: dirname(__DIR__) . '/src';                    // 例: '/home/USER/line-report-logger/src'
$config = getenv('LRL_CONFIG') ?: dirname(__DIR__) . '/config/config.php';  // 例: '/home/USER/line-report-logger/config/config.php'

require_once $src . '/bootstrap.php';

$headers = array();
foreach ($_SERVER as $k => $v) {
    if (strpos($k, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
    }
}

try {
    $handler = LineReportLogger\build_handler($config);
    list($status, $body) = $handler->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $headers, (string) file_get_contents('php://input'));
} catch (Throwable $e) {
    // 設定の不備など。内容は出さない
    error_log('[line-report-logger] setup_error ' . preg_replace('/[^0-9A-Za-z_ :-]/', '?', $e->getMessage()));
    $status = 500;
    $body = '';
}

http_response_code($status);
header('Content-Type: text/plain; charset=utf-8');
echo $body;
