<?php
declare(strict_types=1);

// 試験用の偽物の Google(トークン発行 + Sheets API の一部)。php -S のルーターとして動かす。
// 本物の Google には一切つながない。状態は MOCK_DIR のファイルに置く。
//   MOCK_DIR/pubkey.pem   … 受け口が署名に使う鍵の公開鍵(JWTの署名を検証する)
//   MOCK_DIR/mode         … ok / fail(Sheets が 503 を返す)
//   MOCK_DIR/rows.jsonl   … 追加された行
//   MOCK_DIR/requests.jsonl … 受けたリクエストの記録(方法・パス・クエリ・スコープだけ)

$dir = getenv('MOCK_DIR') ?: '';
$delayMs = (int) (getenv('MOCK_DELAY_MS') ?: 0);
$token = 'mock-access-token';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = rawurldecode((string) parse_url($uri, PHP_URL_PATH));
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);

function out(int $code, array $data): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
function record(string $dir, array $r): void
{
    file_put_contents($dir . '/requests.jsonl', json_encode($r, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}
function b64d(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

// トークン発行: サービスアカウントの鍵で署名された JWT を検証する
if ($method === 'POST' && $path === '/token') {
    parse_str((string) file_get_contents('php://input'), $form);
    $parts = explode('.', (string) ($form['assertion'] ?? ''));
    $ok = count($parts) === 3 && ($form['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
        && openssl_verify($parts[0] . '.' . $parts[1], b64d($parts[2]), (string) file_get_contents($dir . '/pubkey.pem'), OPENSSL_ALGO_SHA256) === 1;
    $claims = $ok ? json_decode(b64d($parts[1]), true) : array();
    record($dir, array('m' => $method, 'p' => $path, 'jwt_valid' => $ok, 'scope' => $claims['scope'] ?? null));
    if (!$ok) {
        out(401, array('error' => 'invalid_grant'));
        return true;
    }
    out(200, array('access_token' => $token, 'expires_in' => 3600, 'token_type' => 'Bearer'));
    return true;
}

// Sheets API
if (preg_match('#^/v4/spreadsheets/([^/]+)/values/(.+?)(:append)?$#', $path, $m)) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    record($dir, array('m' => $method, 'p' => $m[3] ?? '' ? 'append' : 'get', 'sheet' => $m[1], 'range' => $m[2], 'q' => $query, 'auth' => $auth === 'Bearer ' . $token));
    if ($auth !== 'Bearer ' . $token) {
        out(401, array('error' => 'unauthenticated'));
        return true;
    }
    if ((string) @file_get_contents($dir . '/mode') === 'fail') {
        out(503, array('error' => 'unavailable'));
        return true;
    }
    if ($method === 'POST' && ($m[3] ?? '') === ':append') {
        $req = json_decode((string) file_get_contents('php://input'), true);
        usleep($delayMs * 1000); // 書き込みに時間がかかる状況を作る(同時受信の試験用)
        foreach ((array) ($req['values'] ?? array()) as $row) {
            file_put_contents($dir . '/rows.jsonl', json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
        }
        out(200, array('updates' => array('updatedRows' => count((array) ($req['values'] ?? array())))));
        return true;
    }
    if ($method === 'GET') {
        $col = array();
        foreach (file($dir . '/rows.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array() as $line) {
            $r = json_decode($line, true);
            $col[] = $r[3] ?? '';
        }
        out(200, array('range' => $m[2], 'majorDimension' => 'COLUMNS', 'values' => array($col)));
        return true;
    }
}

out(404, array('error' => 'not_found'));
return true;
