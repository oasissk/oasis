<?php
declare(strict_types=1);

// 手元の受け口に、署名つきの架空の LINE Webhook を1件送る道具(ローカル確認用)。
// 使い方:
//   LRL_TEST_SECRET=… LRL_TEST_GROUP=C… php tests/send.php http://127.0.0.1:8080/ '【対応】架空の依頼です'
// シークレットはコマンドの引数ではなく環境変数で渡す(コマンド履歴に残さないため)。

$url = $argv[1] ?? '';
$text = $argv[2] ?? '【対応】架空の依頼です';
$secret = getenv('LRL_TEST_SECRET') ?: '';
$group = getenv('LRL_TEST_GROUP') ?: '';
if ($url === '' || $secret === '' || $group === '') {
    fwrite(STDERR, "使い方: LRL_TEST_SECRET=… LRL_TEST_GROUP=C… php tests/send.php <URL> [本文]\n");
    exit(2);
}
$body = json_encode(array('destination' => 'Ubot', 'events' => array(array(
    'type' => 'message',
    'webhookEventId' => '01LOCAL' . strtoupper(bin2hex(random_bytes(10))),
    'timestamp' => (int) (microtime(true) * 1000),
    'deliveryContext' => array('isRedelivery' => false),
    'source' => array('type' => 'group', 'groupId' => $group, 'userId' => 'Ulocaltest'),
    'replyToken' => 'dummy',
    'mode' => 'active',
    'message' => array('type' => 'text', 'id' => '1', 'text' => $text),
))), JSON_UNESCAPED_UNICODE);
$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'X-Line-Signature: ' . base64_encode(hash_hmac('sha256', $body, $secret, true))),
));
curl_exec($ch);
echo 'HTTP ' . curl_getinfo($ch, CURLINFO_RESPONSE_CODE) . "\n";
