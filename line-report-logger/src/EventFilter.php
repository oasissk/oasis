<?php
declare(strict_types=1);

namespace LineReportLogger;

/**
 * 1件のイベントを記録対象にするか判定し、対象ならシートの1行を作る。
 * 対象: 許可したグループの、テキストメッセージで、先頭が目印(既定「【対応】」)のもの。
 * それ以外(対象外グループ・個別チャット・画像・スタンプ・ファイル・目印なし等)は保存しない。
 */
final class EventFilter
{
    /** @var string[] */
    private $allowedGroupIds;
    /** @var string */
    private $marker;

    /** @param string[] $allowedGroupIds */
    public function __construct(array $allowedGroupIds, string $marker)
    {
        $this->allowedGroupIds = array_values(array_filter(array_map('trim', $allowedGroupIds), 'strlen'));
        $this->marker = $marker;
    }

    /**
     * @param mixed $event
     * @return array{accept: bool, reason: string, eventId: string, row: array<int,string>}
     */
    public function classify($event): array
    {
        $eventId = is_array($event) && isset($event['webhookEventId']) && is_string($event['webhookEventId'])
            ? $event['webhookEventId'] : '';
        $no = function (string $reason) use ($eventId): array {
            return array('accept' => false, 'reason' => $reason, 'eventId' => $eventId, 'row' => array());
        };

        if (!is_array($event)) {
            return $no('not_an_event');
        }
        if (($event['type'] ?? '') !== 'message') {
            return $no('not_a_message');
        }
        $source = $event['source'] ?? array();
        if (!is_array($source) || ($source['type'] ?? '') !== 'group' || !is_string($source['groupId'] ?? null)) {
            return $no('not_a_group');
        }
        // 許可リストが空なら何も記録しない(設定漏れで全グループを拾わないように)
        if (!in_array($source['groupId'], $this->allowedGroupIds, true)) {
            return $no('group_not_allowed');
        }
        $message = $event['message'] ?? array();
        if (!is_array($message) || ($message['type'] ?? '') !== 'text' || !is_string($message['text'] ?? null)) {
            return $no('not_text'); // 画像・動画・音声・ファイル・スタンプ・位置情報など
        }
        if ($eventId === '' || !preg_match('/^[0-9A-Za-z_-]{8,64}$/', $eventId)) {
            return $no('no_event_id');
        }

        // 先頭の空白・改行だけは無視して、目印で始まるか見る
        $text = preg_replace('/^[\s\x{3000}]+/u', '', $message['text']);
        if ($text === null || $this->marker === '' || strncmp($text, $this->marker, strlen($this->marker)) !== 0) {
            return $no('no_marker');
        }
        $content = trim(preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', substr($text, strlen($this->marker))) ?? '');
        if ($content === '') {
            return $no('empty_content');
        }

        return array(
            'accept'  => true,
            'reason'  => 'ok',
            'eventId' => $eventId,
            'row'     => array(
                self::jst($event['timestamp'] ?? null), // 受信日時(日本時間)
                $content,                              // 対応内容(目印を除いた本文)
                '未対応',                               // ステータス
                $eventId,                              // イベントID
            ),
        );
    }

    /** LINE のイベント時刻(ミリ秒)を日本時間の文字列にする。無ければ現在時刻。 */
    public static function jst($timestampMs): string
    {
        $sec = is_int($timestampMs) || (is_string($timestampMs) && ctype_digit($timestampMs))
            ? intdiv((int) $timestampMs, 1000) : time();
        $dt = new \DateTimeImmutable('@' . $sec);
        return $dt->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d H:i:s');
    }
}
