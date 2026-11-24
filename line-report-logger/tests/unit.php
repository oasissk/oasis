<?php
declare(strict_types=1);

// 単体試験: 本物のLINE・Googleの代わりに偽物を使う。架空のデータだけを使う。
// 実行: php tests/unit.php

namespace LineReportLogger\Tests;

use LineReportLogger\EventFilter;
use LineReportLogger\EventStore;
use LineReportLogger\Logger;
use LineReportLogger\SheetsWriter;
use LineReportLogger\WebhookHandler;

require dirname(__DIR__) . '/src/bootstrap.php';

/** 偽物のシート。mode: ok / fail(書けない) / fail_after(書けたのに応答が来ない) / read_fail(確認もできない) */
final class FakeSheets implements SheetsWriter
{
    /** @var array<int,array<int,string>> */
    public $rows = array();
    /** @var string */
    public $mode = 'ok';
    /** @var int */
    public $appendCalls = 0;

    public function appendRow(array $row): void
    {
        $this->appendCalls++;
        if ($this->mode === 'fail' || $this->mode === 'read_fail') {
            throw new \RuntimeException('sheets append failed: HTTP 503');
        }
        $this->rows[] = $row;
        if ($this->mode === 'fail_after') {
            throw new \RuntimeException('sheets append failed: HTTP 0');
        }
    }

    public function hasEventId(string $eventId): bool
    {
        if ($this->mode === 'read_fail') {
            throw new \RuntimeException('sheets read failed: HTTP 503');
        }
        foreach ($this->rows as $r) {
            if ($r[3] === $eventId) {
                return true;
            }
        }
        return false;
    }
}

final class MemLogger extends Logger
{
    /** @var string[] */
    public $lines = array();
    protected function write(string $line): void
    {
        $this->lines[] = $line;
    }
}

const SECRET = 'test-channel-secret-not-real';
const GROUP = 'Ctestgroup000000000000000000000001';
const OTHER = 'Cothergroup00000000000000000000002';

function sign(string $body, string $secret = SECRET): string
{
    return base64_encode(hash_hmac('sha256', $body, $secret, true));
}

/** @param array<string,mixed> $over */
function textEvent(string $id, string $text, string $group = GROUP, array $over = array()): array
{
    return array_replace_recursive(array(
        'type' => 'message',
        'webhookEventId' => $id,
        'timestamp' => 1764000000000, // 2025-11-24 16:00:00 UTC = 2025-11-25 01:00:00 JST
        'deliveryContext' => array('isRedelivery' => false),
        'source' => array('type' => 'group', 'groupId' => $group, 'userId' => 'Utestuser'),
        'replyToken' => 'dummy',
        'mode' => 'active',
        'message' => array('type' => 'text', 'id' => '1', 'text' => $text),
    ), $over);
}

/** @param array<int,array<string,mixed>> $events */
function body(array $events): string
{
    return json_encode(array('destination' => 'Ubot', 'events' => $events), JSON_UNESCAPED_UNICODE);
}

$tmp = sys_get_temp_dir() . '/lrl-unit-' . bin2hex(random_bytes(4));
$sheets = new FakeSheets();
$log = new MemLogger();
$store = new EventStore($tmp);
$handler = new WebhookHandler(SECRET, new EventFilter(array(GROUP), '【対応】'), $store, function () use ($sheets) {
    return $sheets;
}, $log);

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  OK  {$name}\n";
    } else {
        $fail++;
        echo "  NG  {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n";
    }
}
function post(WebhookHandler $h, string $body, ?string $sig): array
{
    $headers = $sig === null ? array() : array('x-line-signature' => $sig);
    return $h->handle('POST', $headers, $body);
}

echo "[正常な投稿]\n";
$b = body(array(textEvent('01TESTEVENT0000000000000001', '【対応】明日までに雨樋の見積もりを確認')));
list($st) = post($handler, $b, sign($b));
check('200を返す', $st === 200, (string) $st);
check('1行追加される', count($sheets->rows) === 1);
check('列が「受信日時(日本時間)/対応内容/ステータス/イベントID」', $sheets->rows[0] === array('2025-11-25 01:00:00', '明日までに雨樋の見積もりを確認', '未対応', '01TESTEVENT0000000000000001'), json_encode($sheets->rows[0] ?? null, JSON_UNESCAPED_UNICODE));
check('イベントの状態が done', $store->stateOf('01TESTEVENT0000000000000001') === 'done');

echo "[先頭の空白・改行は無視する]\n";
$b = body(array(textEvent('01TESTEVENT0000000000000002', "  \n【対応】  屋根の点検日を連絡  ")));
post($handler, $b, sign($b));
check('対応内容の前後の空白を除いて記録', end($sheets->rows)[1] === '屋根の点検日を連絡');

echo "[記録しないもの]\n";
$before = count($sheets->rows);
$cases = array(
    '対象外のグループ' => textEvent('01TESTEVENT0000000000000010', '【対応】架空の依頼', OTHER),
    '目印なし' => textEvent('01TESTEVENT0000000000000011', '明日までに雨樋の見積もりを確認'),
    '目印が途中にある' => textEvent('01TESTEVENT0000000000000012', '了解です【対応】架空'),
    '目印だけ(内容なし)' => textEvent('01TESTEVENT0000000000000013', '【対応】   '),
    '画像' => textEvent('01TESTEVENT0000000000000014', '', GROUP, array('message' => array('type' => 'image', 'text' => null))),
    'ファイル' => textEvent('01TESTEVENT0000000000000015', '', GROUP, array('message' => array('type' => 'file', 'fileName' => 'a.pdf', 'text' => null))),
    'スタンプ' => textEvent('01TESTEVENT0000000000000016', '', GROUP, array('message' => array('type' => 'sticker', 'text' => null))),
    '個別チャット' => textEvent('01TESTEVENT0000000000000017', '【対応】架空', GROUP, array('source' => array('type' => 'user', 'groupId' => null))),
    'メッセージ以外(参加)' => array('type' => 'join', 'webhookEventId' => '01TESTEVENT0000000000000018', 'timestamp' => 1764000000000, 'source' => array('type' => 'group', 'groupId' => GROUP)),
);
foreach ($cases as $name => $ev) {
    $b = body(array($ev));
    list($st) = post($handler, $b, sign($b));
    check($name . ' → 記録しない(200)', $st === 200 && count($sheets->rows) === $before);
}
$emptyFilter = new WebhookHandler(SECRET, new EventFilter(array(), '【対応】'), $store, function () use ($sheets) {
    return $sheets;
}, $log);
$b = body(array(textEvent('01TESTEVENT0000000000000019', '【対応】架空')));
post($emptyFilter, $b, sign($b));
check('許可グループが未設定なら何も記録しない', count($sheets->rows) === $before);

echo "[署名の検証]\n";
$b = body(array(textEvent('01TESTEVENT0000000000000020', '【対応】架空の依頼')));
$calls = $sheets->appendCalls;
list($st1) = post($handler, $b, null);
list($st2) = post($handler, $b, sign($b, 'wrong-secret'));
list($st3) = post($handler, $b, 'not-base64!!');
$tampered = str_replace('架空の依頼', '改ざんした依頼', $b);
list($st4) = post($handler, $tampered, sign($b));
list($st5) = post($handler, $b, '');
check('署名なし → 401', $st1 === 401, (string) $st1);
check('違うシークレットの署名 → 401', $st2 === 401);
check('でたらめな署名 → 401', $st3 === 401);
check('本文を改ざん → 401', $st4 === 401);
check('空の署名 → 401', $st5 === 401);
check('署名不正のときはシートに一切触れない', $sheets->appendCalls === $calls && count($sheets->rows) === $before);
check('署名不正のときは状態も残さない', $store->stateOf('01TESTEVENT0000000000000020') === '');
list($st6) = $handler->handle('GET', array(), '');
check('POST以外 → 405', $st6 === 405);
$bad = '{"events": "x"}';
list($st7) = post($handler, $bad, sign($bad));
check('形式がおかしい本文 → 400', $st7 === 400);
$verify = body(array());
list($st8) = post($handler, $verify, sign($verify));
check('LINEの接続確認(イベント0件) → 200', $st8 === 200);

echo "[重複受信]\n";
$b = body(array(textEvent('01TESTEVENT0000000000000030', '【対応】架空の重複テスト')));
post($handler, $b, sign($b));
post($handler, $b, sign($b));
$re = body(array(textEvent('01TESTEVENT0000000000000030', '【対応】架空の重複テスト', GROUP, array('deliveryContext' => array('isRedelivery' => true)))));
list($st) = post($handler, $re, sign($re));
$n = count(array_filter($sheets->rows, function ($r) {
    return $r[3] === '01TESTEVENT0000000000000030';
}));
check('同じイベントIDが3回届いても1行だけ', $n === 1, (string) $n);
check('再送には200を返す', $st === 200);
$b = body(array(textEvent('01TESTEVENT0000000000000031', '【対応】架空A'), textEvent('01TESTEVENT0000000000000031', '【対応】架空A')));
post($handler, $b, sign($b));
$n = count(array_filter($sheets->rows, function ($r) {
    return $r[3] === '01TESTEVENT0000000000000031';
}));
check('1回の送信の中に同じIDが2つあっても1行だけ', $n === 1);

echo "[書き込み失敗]\n";
$sheets->mode = 'fail';
$b = body(array(textEvent('01TESTEVENT0000000000000040', '【対応】架空の失敗テスト')));
list($st) = post($handler, $b, sign($b));
check('書き込めなかったら 500(完了扱いにしない)', $st === 500, (string) $st);
check('状態は done にならない', $store->stateOf('01TESTEVENT0000000000000040') !== 'done');
$sheets->mode = 'ok';
list($st) = post($handler, $b, sign($b));
$n = count(array_filter($sheets->rows, function ($r) {
    return $r[3] === '01TESTEVENT0000000000000040';
}));
check('再送で書き込まれる(1行)', $st === 200 && $n === 1);

echo "[書けたのに応答が来なかった場合]\n";
$sheets->mode = 'fail_after';
$b = body(array(textEvent('01TESTEVENT0000000000000041', '【対応】架空の応答なしテスト')));
list($st) = post($handler, $b, sign($b));
check('応答が来なければ 500', $st === 500);
$sheets->mode = 'ok';
list($st) = post($handler, $b, sign($b));
$n = count(array_filter($sheets->rows, function ($r) {
    return $r[3] === '01TESTEVENT0000000000000041';
}));
check('再送ではシートを確認して、二重に書かない', $st === 200 && $n === 1, (string) $n);

echo "[確認もできない場合]\n";
$sheets->mode = 'fail_after';
$b = body(array(textEvent('01TESTEVENT0000000000000042', '【対応】架空の確認失敗テスト')));
post($handler, $b, sign($b));
$sheets->mode = 'read_fail';
list($st) = post($handler, $b, sign($b));
check('シートを確認できなければ書かずに 500', $st === 500);
$sheets->mode = 'ok';
post($handler, $b, sign($b));
$n = count(array_filter($sheets->rows, function ($r) {
    return $r[3] === '01TESTEVENT0000000000000042';
}));
check('確認できるようになったら、二重に書かない', $n === 1);

echo "[複数のイベントが混ざった送信]\n";
$before = count($sheets->rows);
$b = body(array(
    textEvent('01TESTEVENT0000000000000050', '【対応】架空1'),
    textEvent('01TESTEVENT0000000000000051', '雑談'),
    textEvent('01TESTEVENT0000000000000052', '【対応】架空2', OTHER),
    textEvent('01TESTEVENT0000000000000053', '【対応】架空3'),
));
post($handler, $b, sign($b));
check('対象の2件だけ記録', count($sheets->rows) === $before + 2);

echo "[数式にならない]\n";
$b = body(array(textEvent('01TESTEVENT0000000000000060', '【対応】=HYPERLINK("http://example.invalid","x")')));
post($handler, $b, sign($b));
check('本文はそのまま文字列として渡す', end($sheets->rows)[1] === '=HYPERLINK("http://example.invalid","x")');

echo "[ログに本文・シークレットを書かない]\n";
$all = implode("\n", $log->lines);
$leaks = array();
foreach (array('雨樋', '架空', '屋根', '雑談', 'HYPERLINK', SECRET, GROUP, OTHER, 'Utestuser') as $w) {
    if (strpos($all, $w) !== false) {
        $leaks[] = $w;
    }
}
check('ログに本文・シークレット・グループID・ユーザーIDがない', $leaks === array(), implode(',', $leaks));
check('ログに処理結果は残る', strpos($all, 'recorded') !== false && strpos($all, 'reason=bad_signature') !== false);

echo "[参加したグループIDの確認モード]\n";
$joinLog = new MemLogger();
$h2 = new WebhookHandler(SECRET, new EventFilter(array(GROUP), '【対応】'), $store, function () use ($sheets) {
    return $sheets;
}, $joinLog, true);
$b = body(array(array('type' => 'join', 'webhookEventId' => '01TESTEVENT0000000000000070', 'timestamp' => 1764000000000, 'source' => array('type' => 'group', 'groupId' => OTHER))));
post($h2, $b, sign($b));
check('確認モードのときだけ、参加したグループIDをログに出す', strpos(implode("\n", $joinLog->lines), OTHER) !== false);

// 後片付け
array_map('unlink', glob($tmp . '/events/*') ?: array());
@rmdir($tmp . '/events');
@rmdir($tmp);

echo "\n単体試験: {$pass} 件成功 / {$fail} 件失敗\n";
exit($fail === 0 ? 0 : 1);
