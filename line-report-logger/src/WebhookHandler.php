<?php
declare(strict_types=1);

namespace LineReportLogger;

/**
 * LINE Webhook を受け取って処理する。
 * 1. 元のリクエスト本文と X-Line-Signature で署名を検証する(失敗したら何も書き込まない)
 * 2. 各イベントを判定し、対象だけをシートに1行追加する(イベントIDで二重記録を防ぐ)
 * 3. 書き込みに失敗したイベントが1つでもあれば 500 を返す(LINEの再送を受けて、もう一度書き込む)
 */
final class WebhookHandler
{
    /** @var string */
    private $channelSecret;
    /** @var EventFilter */
    private $filter;
    /** @var EventStore */
    private $store;
    /** @var callable(): SheetsWriter */
    private $sheetsFactory;
    /** @var Logger */
    private $log;
    /** @var bool */
    private $logJoinGroupId;

    /** @param callable(): SheetsWriter $sheetsFactory シートへの接続は、記録対象があるときだけ作る */
    public function __construct(string $channelSecret, EventFilter $filter, EventStore $store, callable $sheetsFactory, Logger $log, bool $logJoinGroupId = false)
    {
        $this->channelSecret = $channelSecret;
        $this->filter = $filter;
        $this->store = $store;
        $this->sheetsFactory = $sheetsFactory;
        $this->log = $log;
        $this->logJoinGroupId = $logJoinGroupId;
    }

    /**
     * @param array<string,string> $headers ヘッダー名は小文字
     * @return array{0:int,1:string} [HTTPステータス, 応答本文]
     */
    public function handle(string $method, array $headers, string $rawBody): array
    {
        if ($method !== 'POST') {
            return array(405, '');
        }
        if (!Signature::verify($rawBody, $headers['x-line-signature'] ?? null, $this->channelSecret)) {
            $this->log->info('rejected', array('reason' => 'bad_signature'));
            return array(401, '');
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data) || !isset($data['events']) || !is_array($data['events'])) {
            $this->log->info('rejected', array('reason' => 'bad_body'));
            return array(400, '');
        }

        $failed = 0;
        $sheets = null;
        foreach ($data['events'] as $event) {
            // 招待されたグループのIDを確認するためのモード(設定時だけ有効にする)
            if ($this->logJoinGroupId && is_array($event) && ($event['type'] ?? '') === 'join'
                && ($event['source']['type'] ?? '') === 'group' && is_string($event['source']['groupId'] ?? null)) {
                $this->log->info('joined_group', array('group_id' => $event['source']['groupId']));
            }

            $r = $this->filter->classify($event);
            if (!$r['accept']) {
                $this->log->info('skipped', array('event' => $r['eventId'], 'reason' => $r['reason']));
                continue;
            }
            try {
                if ($sheets === null) {
                    $sheets = ($this->sheetsFactory)();
                }
                $result = $this->store->writeOnce($r['eventId'], $r['row'], $sheets);
                $this->log->info('recorded', array('event' => $r['eventId'], 'result' => $result));
            } catch (\Throwable $e) {
                $failed++;
                $this->log->info('write_failed', array('event' => $r['eventId'], 'error' => $e->getMessage()));
            }
        }

        // 1件でも書き込めなかったら 500(完了扱いにしない)。LINE側で「Webhookの再送」を有効にしておく。
        return $failed > 0 ? array(500, '') : array(200, '');
    }
}
