<?php
declare(strict_types=1);

// 通し試験: ローカルで受け口のサーバーと偽物の Google を起動し、HTTP で LINE の Webhook を模擬して送る。
// 本物の LINE・Google・認証情報は使わない。試験用の鍵はその場で作って、終わったら消す。
// 実行: php tests/e2e.php

$root = dirname(__DIR__);
$tmp = $root . '/tests/tmp/run-' . bin2hex(random_bytes(4));
mkdir($tmp . '/mock', 0700, true);
mkdir($tmp . '/data', 0700, true);
$appPort = 18088;
$mockPort = 18089;
$secret = 'e2e-channel-secret-not-real';
$group = 'Ce2egroup0000000000000000000000001';

// 試験用の鍵(その場限り)と、偽物のサービスアカウント
$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($key, $priv);
file_put_contents($tmp . '/mock/pubkey.pem', openssl_pkey_get_details($key)['key']);
file_put_contents($tmp . '/sa.json', json_encode(array(
    'type' => 'service_account',
    'client_email' => 'line-report-test@example.invalid',
    'private_key' => $priv,
    'token_uri' => "http://127.0.0.1:{$mockPort}/token",
)));
chmod($tmp . '/sa.json', 0600);
file_put_contents($tmp . '/mock/mode', 'ok');
file_put_contents($tmp . '/mock/rows.jsonl', '');
file_put_contents($tmp . '/config.php', '<?php return ' . var_export(array(
    'channel_secret' => $secret,
    'allowed_group_ids' => array($group),
    'marker' => '【対応】',
    'service_account_key_file' => $tmp . '/sa.json',
    'spreadsheet_id' => 'TESTSHEET-not-real',
    'sheet_name' => '対応記録',
    'data_dir' => $tmp . '/data',
    'log_file' => $tmp . '/data/app.log',
    'sheets_api_base' => "http://127.0.0.1:{$mockPort}",
), true) . ';');

$procs = array();
function start(array $cmd, array $env): array
{
    $p = proc_open($cmd, array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w')), $pipes, null, $env + getenv());
    return array($p);
}
$procs[] = start(array('php', '-S', "127.0.0.1:{$mockPort}", $root . '/tests/mock_google.php'), array('MOCK_DIR' => $tmp . '/mock', 'MOCK_DELAY_MS' => '700', 'PHP_CLI_SERVER_WORKERS' => '4'));
$procs[] = start(array('php', '-S', "127.0.0.1:{$appPort}", $root . '/public/index.php'), array('LRL_CONFIG' => $tmp . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '6'));
foreach (array($appPort, $mockPort) as $port) {
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) {
        usleep(100000);
    }
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  OK  ' : '  NG  ') . $name . ($ok || $detail === '' ? '' : " ({$detail})") . "\n";
}
function body(array $events): string
{
    return json_encode(array('destination' => 'Ubot', 'events' => $events), JSON_UNESCAPED_UNICODE);
}
function ev(string $id, string $text, string $group, array $over = array()): array
{
    return array_replace_recursive(array(
        'type' => 'message', 'webhookEventId' => $id, 'timestamp' => 1764000000000,
        'deliveryContext' => array('isRedelivery' => false),
        'source' => array('type' => 'group', 'groupId' => $group, 'userId' => 'Ue2euser'),
        'replyToken' => 'dummy', 'mode' => 'active',
        'message' => array('type' => 'text', 'id' => '1', 'text' => $text),
    ), $over);
}
/** 複数のリクエストを同時に送る。@return int[] ステータス */
function sendAll(array $bodies, int $port, ?string $secret, bool $badSig = false): array
{
    $mh = curl_multi_init();
    $hs = array();
    foreach ($bodies as $b) {
        $ch = curl_init("http://127.0.0.1:{$port}/");
        $sig = $secret === null ? null : base64_encode(hash_hmac('sha256', $b, $badSig ? 'wrong' : $secret, true));
        curl_setopt_array($ch, array(CURLOPT_POST => true, CURLOPT_POSTFIELDS => $b, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => array_merge(array('Content-Type: application/json'), $sig === null ? array() : array('X-Line-Signature: ' . $sig))));
        curl_multi_add_handle($mh, $ch);
        $hs[] = $ch;
    }
    do {
        curl_multi_exec($mh, $running);
        curl_multi_select($mh, 0.1);
    } while ($running);
    $codes = array();
    foreach ($hs as $ch) {
        $codes[] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_multi_remove_handle($mh, $ch);
    }
    return $codes;
}
function rows(string $tmp): array
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, file($tmp . '/mock/rows.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array());
}
function reqs(string $tmp): array
{
    return array_map(function ($l) {
        return json_decode($l, true);
    }, @file($tmp . '/mock/requests.jsonl', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: array());
}
function countId(array $rows, string $id): int
{
    return count(array_filter($rows, function ($r) use ($id) {
        return ($r[3] ?? '') === $id;
    }));
}

echo "[正常な投稿(HTTP経由・偽物のGoogleへ書き込み)]\n";
$b = body(array(ev('01E2EEVENT00000000000000001', '【対応】明日までに雨樋の見積もりを確認', $group)));
$codes = sendAll(array($b), $appPort, $secret);
$rows = rows($tmp);
check('200を返す', $codes === array(200), json_encode($codes));
check('シートに1行', count($rows) === 1 && $rows[0] === array('2025-11-25 01:00:00', '明日までに雨樋の見積もりを確認', '未対応', '01E2EEVENT00000000000000001'), json_encode($rows, JSON_UNESCAPED_UNICODE));
$r = reqs($tmp);
$tok = array_values(array_filter($r, function ($x) {
    return $x['p'] === '/token';
}));
$app = array_values(array_filter($r, function ($x) {
    return $x['p'] === 'append';
}));
check('鍵で署名した認証で、トークンを取得', isset($tok[0]) && $tok[0]['jwt_valid'] === true);
check('要求する権限はスプレッドシートだけ', isset($tok[0]) && $tok[0]['scope'] === 'https://www.googleapis.com/auth/spreadsheets', $tok[0]['scope'] ?? '');
check('書き込みは文字列として(valueInputOption=RAW)', isset($app[0]) && ($app[0]['q']['valueInputOption'] ?? '') === 'RAW');
check('書き込み先は指定のシートのA〜D列', isset($app[0]) && $app[0]['range'] === "'対応記録'!A:D", $app[0]['range'] ?? '');

echo "[同時に届いた重複(5本を同時送信)]\n";
$b = body(array(ev('01E2EEVENT00000000000000002', '【対応】架空の同時受信テスト', $group)));
$t0 = microtime(true);
$codes = sendAll(array_fill(0, 5, $b), $appPort, $secret);
$sec = microtime(true) - $t0;
check('5本とも200', $codes === array_fill(0, 5, 200), json_encode($codes));
check('シートには1行だけ', countId(rows($tmp), '01E2EEVENT00000000000000002') === 1, (string) countId(rows($tmp), '01E2EEVENT00000000000000002'));
check('Sheetsへの書き込み要求も1回だけ', count(array_filter(reqs($tmp), function ($x) {
    return $x['p'] === 'append';
})) === 2);
echo sprintf("      (所要 %.1f 秒。書き込みに0.7秒かかる設定で、同時に受けている)\n", $sec);

echo "[同時に届いた再送(内容が違う本文・同じイベントID)]\n";
$b1 = body(array(ev('01E2EEVENT00000000000000003', '【対応】架空の再送テスト', $group)));
$b2 = body(array(ev('01E2EEVENT00000000000000003', '【対応】架空の再送テスト', $group, array('deliveryContext' => array('isRedelivery' => true)))));
$codes = sendAll(array($b1, $b2, $b2), $appPort, $secret);
check('すべて200、シートには1行だけ', $codes === array(200, 200, 200) && countId(rows($tmp), '01E2EEVENT00000000000000003') === 1);

echo "[署名不正]\n";
$before = count(reqs($tmp));
$b = body(array(ev('01E2EEVENT00000000000000004', '【対応】架空の不正テスト', $group)));
$c1 = sendAll(array($b), $appPort, $secret, true);
$c2 = sendAll(array($b), $appPort, null);
check('違う署名・署名なしは401', $c1 === array(401) && $c2 === array(401));
check('Googleへの通信も発生しない', count(reqs($tmp)) === $before);
check('状態も残らない', !glob($tmp . '/data/events/' . hash('sha256', '01E2EEVENT00000000000000004') . '.state'));

echo "[対象外グループ・目印なし・画像]\n";
$before = count(reqs($tmp));
$b = body(array(
    ev('01E2EEVENT00000000000000005', '【対応】架空', 'Cothergroup00000000000000000000002'),
    ev('01E2EEVENT00000000000000006', '架空の雑談', $group),
    ev('01E2EEVENT00000000000000007', '', $group, array('message' => array('type' => 'image', 'text' => null))),
));
$codes = sendAll(array($b), $appPort, $secret);
check('200で、Googleへの通信なし', $codes === array(200) && count(reqs($tmp)) === $before);

echo "[書き込み失敗 → 再送]\n";
file_put_contents($tmp . '/mock/mode', 'fail');
$b = body(array(ev('01E2EEVENT00000000000000008', '【対応】架空の失敗テスト', $group)));
$c1 = sendAll(array($b), $appPort, $secret);
check('Sheetsが失敗したら500(完了扱いにしない)', $c1 === array(500), json_encode($c1));
check('シートに書かれていない', countId(rows($tmp), '01E2EEVENT00000000000000008') === 0);
file_put_contents($tmp . '/mock/mode', 'ok');
$c2 = sendAll(array($b, $b), $appPort, $secret);
check('復旧後の再送(同時2本)で1行だけ書かれる', $c2 === array(200, 200) && countId(rows($tmp), '01E2EEVENT00000000000000008') === 1, json_encode($c2));

echo "[数式にならない]\n";
$b = body(array(ev('01E2EEVENT00000000000000009', '【対応】=IMPORTXML("http://example.invalid","//a")', $group)));
sendAll(array($b), $appPort, $secret);
$last = rows($tmp);
$last = end($last);
check('本文はそのまま文字列で送られる(RAW)', $last[1] === '=IMPORTXML("http://example.invalid","//a")');

echo "[ログ]\n";
$log = (string) @file_get_contents($tmp . '/data/app.log');
$leaks = array();
foreach (array('雨樋', '架空', 'IMPORTXML', $secret, $group, 'Ue2euser', 'mock-access-token', 'PRIVATE KEY') as $w) {
    if (strpos($log, $w) !== false) {
        $leaks[] = $w;
    }
}
check('ログに本文・シークレット・トークン・ID・鍵がない', $leaks === array(), implode(',', $leaks));
check('ログに処理の結果は残る', strpos($log, 'recorded') !== false && strpos($log, 'write_failed') !== false && strpos($log, 'bad_signature') !== false);
$perm = substr(sprintf('%o', fileperms($tmp . '/data/events')), -3);
check('処理状況のフォルダは本人だけが読める(700)', $perm === '700', $perm);

// 後片付け
foreach ($procs as $p) {
    proc_terminate($p[0]);
}
exec('rm -rf ' . escapeshellarg($tmp));
@rmdir($root . '/tests/tmp');

echo "\n通し試験: {$pass} 件成功 / {$fail} 件失敗\n";
exit($fail === 0 ? 0 : 1);
