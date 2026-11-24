<?php
declare(strict_types=1);

namespace LineReportLogger;

/**
 * シートへの書き込み口。本番は GoogleSheetsClient、テストは偽物を使う。
 */
interface SheetsWriter
{
    /**
     * 1行追加する。失敗したら例外を投げる(成功時だけ正常に戻る)。
     * @param array<int,string> $row
     */
    public function appendRow(array $row): void;

    /** イベントID列にこのIDがすでにあるか(結果が分からない書き込みの確認用)。失敗したら例外。 */
    public function hasEventId(string $eventId): bool;
}
