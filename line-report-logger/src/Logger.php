<?php
declare(strict_types=1);

namespace LineReportLogger;

/**
 * 記録(ログ)は、決められた項目だけを書く。
 * 投稿本文・署名・シークレット・トークン・グループIDは書かない(join の確認モードを除く)。
 */
class Logger
{
    private const ALLOWED_KEYS = array('event', 'result', 'reason', 'status', 'events', 'error', 'group_id');

    /** @var string|null */
    private $file;

    public function __construct(?string $file = null)
    {
        $this->file = $file;
    }

    /** @param array<string,string|int> $fields */
    public function info(string $what, array $fields = array()): void
    {
        $parts = array(gmdate('Y-m-d\TH:i:s\Z'), $what);
        foreach ($fields as $k => $v) {
            if (in_array($k, self::ALLOWED_KEYS, true)) {
                $parts[] = $k . '=' . preg_replace('/[^0-9A-Za-z_.:\/ -]/', '?', (string) $v);
            }
        }
        $this->write(implode(' ', $parts));
    }

    protected function write(string $line): void
    {
        if ($this->file !== null) {
            @file_put_contents($this->file, $line . "\n", FILE_APPEND | LOCK_EX);
        } else {
            error_log('[line-report-logger] ' . $line);
        }
    }
}
