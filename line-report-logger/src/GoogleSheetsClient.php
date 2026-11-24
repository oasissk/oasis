<?php
declare(strict_types=1);

namespace LineReportLogger;

/**
 * Google Sheets API への書き込み(サービスアカウント認証)。
 * - 権限は spreadsheets スコープだけを要求する。シートはこのサービスアカウントにだけ共有する。
 * - 値は valueInputOption=RAW で送る(投稿本文が「=」で始まっても数式として扱われず、文字列のまま入る)。
 * - エラー時は HTTP ステータスだけを例外にし、応答本文や投稿本文はメッセージに含めない。
 */
final class GoogleSheetsClient implements SheetsWriter
{
    private const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

    /** @var array<string,string> */
    private $key;
    /** @var string */
    private $spreadsheetId;
    /** @var string */
    private $sheetName;
    /** @var string */
    private $apiBase;
    /** @var string|null */
    private $token = null;
    /** @var int */
    private $tokenExp = 0;

    public function __construct(string $keyFile, string $spreadsheetId, string $sheetName, string $apiBase = 'https://sheets.googleapis.com')
    {
        $json = is_readable($keyFile) ? file_get_contents($keyFile) : false;
        $key = $json !== false ? json_decode($json, true) : null;
        if (!is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            throw new \RuntimeException('service account key file is missing or invalid');
        }
        $this->key = array(
            'client_email' => (string) $key['client_email'],
            'private_key'  => (string) $key['private_key'],
            'token_uri'    => (string) ($key['token_uri'] ?? 'https://oauth2.googleapis.com/token'),
        );
        $this->spreadsheetId = $spreadsheetId;
        $this->sheetName = $sheetName;
        $this->apiBase = rtrim($apiBase, '/');
    }

    public function appendRow(array $row): void
    {
        $url = $this->valuesUrl('A:D') . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS';
        $values = array(array_map('strval', array_values($row)));
        $this->request('POST', $url, json_encode(array('values' => $values), JSON_UNESCAPED_UNICODE), 'append');
    }

    public function hasEventId(string $eventId): bool
    {
        $res = $this->request('GET', $this->valuesUrl('D:D') . '?majorDimension=COLUMNS', null, 'read');
        $col = isset($res['values'][0]) && is_array($res['values'][0]) ? $res['values'][0] : array();
        return in_array($eventId, $col, true);
    }

    private function valuesUrl(string $cols): string
    {
        $range = "'" . str_replace("'", "''", $this->sheetName) . "'!" . $cols;
        return $this->apiBase . '/v4/spreadsheets/' . rawurlencode($this->spreadsheetId) . '/values/' . rawurlencode($range);
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $url, ?string $body, string $what): array
    {
        $headers = array('Authorization: Bearer ' . $this->accessToken());
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json; charset=utf-8';
        }
        list($code, $resBody) = self::http($method, $url, $headers, $body);
        if ($code < 200 || $code >= 300) {
            throw new \RuntimeException('sheets ' . $what . ' failed: HTTP ' . $code);
        }
        $data = json_decode((string) $resBody, true);
        return is_array($data) ? $data : array();
    }

    /** サービスアカウントの鍵で署名したJWTを使って、アクセストークンをもらう(このリクエスト内だけ保持)。 */
    private function accessToken(): string
    {
        if ($this->token !== null && time() < $this->tokenExp - 60) {
            return $this->token;
        }
        $now = time();
        $b64 = function (string $s): string {
            return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        };
        $unsigned = $b64(json_encode(array('alg' => 'RS256', 'typ' => 'JWT'))) . '.' . $b64(json_encode(array(
            'iss'   => $this->key['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $this->key['token_uri'],
            'iat'   => $now,
            'exp'   => $now + 3600,
        )));
        $sig = '';
        $pkey = openssl_pkey_get_private($this->key['private_key']);
        if ($pkey === false || !openssl_sign($unsigned, $sig, $pkey, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('could not sign token request');
        }
        $form = http_build_query(array(
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $unsigned . '.' . $b64($sig),
        ));
        list($code, $resBody) = self::http('POST', $this->key['token_uri'], array('Content-Type: application/x-www-form-urlencoded'), $form);
        $data = json_decode((string) $resBody, true);
        if ($code !== 200 || !is_array($data) || empty($data['access_token'])) {
            throw new \RuntimeException('token request failed: HTTP ' . $code);
        }
        $this->token = (string) $data['access_token'];
        $this->tokenExp = $now + (int) ($data['expires_in'] ?? 3600);
        return $this->token;
    }

    /**
     * @param string[] $headers
     * @return array{0:int,1:string}
     */
    private static function http(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 15,
        ));
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $res = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($res === false) {
            return array(0, ''); // 通信できなかった(タイムアウト等)。結果不明として扱う
        }
        return array($code, (string) $res);
    }
}
