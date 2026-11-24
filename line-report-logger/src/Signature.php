<?php
declare(strict_types=1);

namespace LineReportLogger;

/**
 * LINE Webhook の署名検証。
 * X-Line-Signature = base64( HMAC-SHA256( チャネルシークレット, 受け取ったリクエスト本文そのもの ) )
 */
final class Signature
{
    public static function verify(string $rawBody, ?string $header, string $channelSecret): bool
    {
        if ($channelSecret === '' || $header === null || $header === '') {
            return false;
        }
        $expected = base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true));
        return hash_equals($expected, trim($header));
    }
}
