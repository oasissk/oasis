<?php
// 設定ファイルの見本。コピーして config.php を作り、公開フォルダの外に置く(Gitには入れない)。
// 実際の値(シークレット・ID)は、このファイルには書かない。
return array(
    // LINE Developers の「チャネル基本設定」にあるチャネルシークレット
    'channel_secret' => '',

    // 記録してよいテストグループのID(C で始まる文字列)。空なら何も記録しない
    'allowed_group_ids' => array(
        // 'Cxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    ),

    // 記録の目印(本文の先頭)
    'marker' => '【対応】',

    // Google のサービスアカウントの鍵(JSON)。公開フォルダの外に置き、権限は 600 にする
    'service_account_key_file' => '/home/USER/line-report-logger/secret/service-account.json',

    // テスト用スプレッドシートのID(URL の /d/ と /edit の間)とシート名
    'spreadsheet_id' => '',
    'sheet_name'     => '対応記録',

    // イベントIDの処理状況を置くフォルダ(公開フォルダの外。権限 700)
    'data_dir' => '/home/USER/line-report-logger/data',

    // ログの出力先(空ならサーバーのエラーログ)。本文やシークレットは書かない
    'log_file' => '/home/USER/line-report-logger/data/app.log',

    // true にすると、招待されたグループのIDをログに書く(設定のときだけ使い、終わったら false に戻す)
    'log_join_group_id' => false,
);
