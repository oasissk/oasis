<?php
declare(strict_types=1);

namespace LineReportLogger;

/**
 * イベントIDごとの処理状況を記録し、二重記録を防ぐ。
 *
 * - イベントIDごとにロックファイルを排他ロックするので、同じIDが同時に届いても、書き込みは1つずつ順番に行われる。
 * - 書き込みに成功したときだけ「done」にする。失敗したら「done」にしない(LINEの再送で、もう一度書き込める)。
 * - 書き込みの結果が分からない(途中で通信が切れた等)ときは「pending」が残る。次に同じIDが届いたら、
 *   シートのイベントID列を確認してから、書くかどうかを決める。
 * - 保存するのはイベントIDのハッシュと状態だけ。投稿本文は保存しない。
 */
final class EventStore
{
    public const WRITTEN = 'written';
    public const DUPLICATE = 'duplicate';

    /** @var string */
    private $dir;

    public function __construct(string $dataDir)
    {
        $this->dir = rtrim($dataDir, '/') . '/events';
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('cannot create data directory');
        }
    }

    /**
     * @param array<int,string> $row
     * @return string self::WRITTEN | self::DUPLICATE (失敗時は例外)
     */
    public function writeOnce(string $eventId, array $row, SheetsWriter $sheets): string
    {
        $base = $this->dir . '/' . hash('sha256', $eventId);
        $lock = fopen($base . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('cannot open lock file');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('cannot lock');
            }
            $state = is_file($base . '.state') ? trim((string) file_get_contents($base . '.state')) : '';
            if ($state === 'done') {
                return self::DUPLICATE;
            }
            if ($state === 'pending' && $sheets->hasEventId($eventId)) {
                // 前回の書き込みは、結果が分からないまま終わったが、実際にはシートに入っていた
                $this->setState($base, 'done');
                return self::DUPLICATE;
            }
            $this->setState($base, 'pending');
            $sheets->appendRow($row);        // 失敗したら例外 → done にしない
            $this->setState($base, 'done');
            return self::WRITTEN;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** テスト・点検用: イベントIDの状態('' / pending / done) */
    public function stateOf(string $eventId): string
    {
        $f = $this->dir . '/' . hash('sha256', $eventId) . '.state';
        return is_file($f) ? trim((string) file_get_contents($f)) : '';
    }

    private function setState(string $base, string $state): void
    {
        $tmp = $base . '.state.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $state) === false || !rename($tmp, $base . '.state')) {
            @unlink($tmp);
            throw new \RuntimeException('cannot save state');
        }
    }
}
