<?php
declare(strict_types=1);

// 手元で試すための起動スクリプト。偽物の Google と受け口を起動し、止めるまで動かし続ける。
// 本物の LINE・Google にはつながない。試験用の鍵・シークレットはその場で作り、終了時に消す。
// 実行: php tests/demo.php   (止めるときは Ctrl+C)

$root = dirname(__DIR__);
$tmp = $root . '/tests/tmp/demo-' . bin2hex(random_bytes(4));
mkdir($tmp . '/mock', 0700, true);
mkdir($tmp . '/data', 0700, true);
$appPort = 8080;
$mockPort = 8081;
$secret = 'demo-' . bin2hex(random_bytes(8));          // 架空のチャネルシークレット
$group = 'Cdemo' . bin2hex(random_bytes(14));          // 架空のグループID

$key = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
openssl_pkey_export($key, $priv);
file_put_contents($tmp . '/mock/pubkey.pem', openssl_pkey_get_details($key)['key']);
file_put_contents($tmp . '/mock/mode', 'ok');
file_put_contents($tmp . '/mock/rows.jsonl', '');
file_put_contents($tmp . '/sa.json', json_encode(array('client_email' => 'demo@example.invalid', 'private_key' => $priv, 'token_uri' => "http://127.0.0.1:{$mockPort}/token")));
chmod($tmp . '/sa.json', 0600);
file_put_contents($tmp . '/config.php', '<?php return ' . var_export(array(
    'channel_secret' => $secret,
    'allowed_group_ids' => array($group),
    'service_account_key_file' => $tmp . '/sa.json',
    'spreadsheet_id' => 'DEMOSHEET',
    'sheet_name' => '対応記録',
    'data_dir' => $tmp . '/data',
    'log_file' => $tmp . '/data/app.log',
    'sheets_api_base' => "http://127.0.0.1:{$mockPort}",
), true) . ';');

$out = array(1 => array('file', '/dev/null', 'w'), 2 => array('file', '/dev/null', 'w'));
$mock = proc_open(array('php', '-S', "127.0.0.1:{$mockPort}", $root . '/tests/mock_google.php'), $out, $p1, null, array('MOCK_DIR' => $tmp . '/mock', 'PHP_CLI_SERVER_WORKERS' => '4') + getenv());
$app = proc_open(array('php', '-S', "127.0.0.1:{$appPort}", $root . '/public/index.php'), $out, $p2, null, array('LRL_CONFIG' => $tmp . '/config.php', 'PHP_CLI_SERVER_WORKERS' => '4') + getenv());

$cleanup = function () use ($mock, $app, $tmp) {
    proc_terminate($mock);
    proc_terminate($app);
    exec('rm -rf ' . escapeshellarg($tmp));
    echo "\n停止して、試験用のファイルを消しました。\n";
    exit(0);
};
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGINT, $cleanup);
    pcntl_signal(SIGTERM, $cleanup);
}

echo "受け口:   http://127.0.0.1:{$appPort}/\n";
echo "別の端末で、次のように架空の投稿を送れます:\n";
echo "  LRL_TEST_SECRET={$secret} LRL_TEST_GROUP={$group} php tests/send.php http://127.0.0.1:{$appPort}/ '【対応】架空の依頼です'\n";
echo "書き込まれた行(偽物のシート): {$tmp}/mock/rows.jsonl\n";
echo "ログ:                         {$tmp}/data/app.log\n";
echo "Sheetsを失敗させる:           echo fail > {$tmp}/mock/mode   (戻す: echo ok > …)\n";
echo "止めるときは Ctrl+C\n";
while (true) {
    sleep(1);
}
