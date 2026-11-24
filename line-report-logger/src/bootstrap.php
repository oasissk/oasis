<?php
declare(strict_types=1);

namespace LineReportLogger;

require_once __DIR__ . '/Signature.php';
require_once __DIR__ . '/EventFilter.php';
require_once __DIR__ . '/SheetsWriter.php';
require_once __DIR__ . '/GoogleSheetsClient.php';
require_once __DIR__ . '/EventStore.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/WebhookHandler.php';

/**
 * 設定ファイル(公開フォルダの外に置く)を読み、処理の部品を組み立てる。
 * 設定ファイルの場所: 環境変数 LRL_CONFIG、なければ ../config/config.php
 *
 * @return WebhookHandler
 */
function build_handler(?string $configFile = null): WebhookHandler
{
    $configFile = $configFile ?? (getenv('LRL_CONFIG') ?: dirname(__DIR__) . '/config/config.php');
    if (!is_readable($configFile)) {
        throw new \RuntimeException('config file not found');
    }
    /** @var array<string,mixed> $c */
    $c = require $configFile;
    foreach (array('channel_secret', 'allowed_group_ids', 'service_account_key_file', 'spreadsheet_id', 'sheet_name', 'data_dir') as $k) {
        if (!isset($c[$k]) || $c[$k] === '' || $c[$k] === array()) {
            throw new \RuntimeException('config missing: ' . $k);
        }
    }
    $log = new Logger(isset($c['log_file']) && $c['log_file'] !== '' ? (string) $c['log_file'] : null);
    $sheetsFactory = function () use ($c): SheetsWriter {
        return new GoogleSheetsClient(
            (string) $c['service_account_key_file'],
            (string) $c['spreadsheet_id'],
            (string) $c['sheet_name'],
            (string) ($c['sheets_api_base'] ?? 'https://sheets.googleapis.com')
        );
    };
    return new WebhookHandler(
        (string) $c['channel_secret'],
        new EventFilter((array) $c['allowed_group_ids'], (string) ($c['marker'] ?? '【対応】')),
        new EventStore((string) $c['data_dir']),
        $sheetsFactory,
        $log,
        !empty($c['log_join_group_id'])
    );
}
