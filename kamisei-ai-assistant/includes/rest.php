<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	foreach ( array( 'chat', 'search', 'inquiry' ) as $route ) {
		register_rest_route( 'kaia/v1', '/' . $route, array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => 'kaia_rest_' . $route,
		) );
	}
} );

/**
 * 簡易レート制限(IPごと・1時間)。超過時は WP_Error。
 */
function kaia_rate_check( $bucket = 'main', $mult = 1 ) {
	if ( ! kaia_get( 'enabled' ) ) {
		return new WP_Error( 'kaia_off', '現在ご利用いただけません。', array( 'status' => 503 ) );
	}
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$tkey  = 'kaia_rl_' . $bucket . '_' . md5( $ip );
	$count = (int) get_transient( $tkey );
	if ( $count >= (int) kaia_get( 'rate_limit' ) * $mult ) {
		return new WP_Error( 'kaia_rate', '短時間にご利用が集中しています。お電話でもご相談いただけます。', array( 'status' => 429 ) );
	}
	set_transient( $tkey, $count + 1, HOUR_IN_SECONDS );
	return true;
}

function kaia_rest_chat( WP_REST_Request $req ) {
	$ok = kaia_rate_check();
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	if ( ! kaia_api_key() ) {
		return new WP_Error( 'kaia_no_key', '現在ご利用いただけません。', array( 'status' => 503 ) );
	}

	// 履歴はテキストのみ・直近20件・1件1000文字まで受け付ける
	$in       = (array) $req->get_param( 'messages' );
	$messages = array();
	foreach ( array_slice( $in, -20 ) as $m ) {
		$role = $m['role'] ?? '';
		if ( ! in_array( $role, array( 'user', 'assistant' ), true ) || ! is_string( $m['content'] ?? null ) ) {
			continue;
		}
		$messages[] = array( 'role' => $role, 'content' => mb_substr( sanitize_textarea_field( $m['content'] ), 0, 1000 ) );
	}
	// 先頭は必ず user から始める
	while ( $messages && 'user' !== $messages[0]['role'] ) {
		array_shift( $messages );
	}
	if ( ! $messages || 'user' !== end( $messages )['role'] ) {
		return new WP_Error( 'kaia_bad', '入力が正しくありません。', array( 'status' => 400 ) );
	}

	kaia_log_event( 'chat', '', '', array( 'route' => 'ai' ) );
	$res = kaia_chat( $messages );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $res->get_error_code(), '申し訳ありません、うまく応答できませんでした。お電話でもご相談いただけます。', array( 'status' => 502 ) );
	}
	return $res;
}

/** 検索のみモード: AIを使わずキーワードで事例・記事を返す。 */
function kaia_rest_search( WP_REST_Request $req ) {
	$ok = kaia_rate_check( 'search', 5 ); // 検索は無料なので上限を緩く(共有IPのお客様への配慮)
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$kw    = mb_substr( sanitize_text_field( (string) $req->get_param( 'keywords' ) ), 0, 100 );
	$text  = mb_substr( sanitize_text_field( (string) $req->get_param( 'text' ) ), 0, 500 );
	$terms = kaia_terms_for( $kw, $text );
	if ( ! $terms ) {
		return new WP_Error( 'kaia_bad', 'キーワードを入力してください。', array( 'status' => 400 ) );
	}
	$cases = kaia_rank_results( array_merge( kaia_search_cases( $terms, 5 ), kaia_search_articles( $terms, 5 ) ) );
	// 検索欄からの検索だけを記録する(診断の結果表示のための検索は数えない)。
	// 検索文そのものは記録せず、分類コードと「結果が0件か」だけを残す。
	if ( 'manual' === $req->get_param( 'src' ) && '' !== $kw ) {
		kaia_log_event( 'search', kaia_search_category( $kw ), $cases ? 'hit' : 'zero', kaia_req_meta( $req, 'search' ) );
	}
	return array( 'cases' => $cases );
}

/** 検索のみモード: 問い合わせフォームの受付。 */
function kaia_rest_inquiry( WP_REST_Request $req ) {
	$ok = kaia_rate_check();
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	// ハニーポット(人間には見えない欄)
	if ( '' !== (string) $req->get_param( 'website' ) ) {
		return array( 'ok' => true );
	}
	$name    = mb_substr( sanitize_text_field( (string) $req->get_param( 'name' ) ), 0, 100 );
	$contact = mb_substr( sanitize_text_field( (string) $req->get_param( 'contact' ) ), 0, 200 );
	$address = mb_substr( sanitize_text_field( (string) $req->get_param( 'address' ) ), 0, 200 );
	$message = mb_substr( sanitize_textarea_field( (string) $req->get_param( 'message' ) ), 0, 1000 );
	if ( '' === $name || '' === $contact || ! $req->get_param( 'consent' ) ) {
		return new WP_Error( 'kaia_bad', 'お名前・ご連絡先を入力し、同意にチェックを入れてください。', array( 'status' => 400 ) );
	}
	$page = esc_url_raw( (string) $req->get_param( 'page' ) );
	if ( $page && wp_parse_url( $page, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		$page = ''; // 自サイト以外のURLは記録しない
	}
	// 同じ送信の二重登録を防ぐ(送信ごとに画面で作る番号)
	$token = preg_match( '/^[a-z0-9]{8,32}$/', (string) $req->get_param( 'token' ) ) ? (string) $req->get_param( 'token' ) : '';
	$tkey  = $token ? 'kaia_tok_' . md5( $token ) : '';
	if ( $tkey && get_transient( $tkey ) ) {
		return array( 'ok' => true, 'duplicate' => true );
	}
	$meta = kaia_req_meta( $req );
	$id   = kaia_save_lead( $name, $contact, $message, '', $page, $address, array(
		'_kaia_v'     => 2,
		'_kaia_route' => $meta['route'],
		'_kaia_test'  => $meta['is_test'],
	) );
	if ( ! $id ) {
		return new WP_Error( 'kaia_save', '送信を受け付けられませんでした。お手数ですがお電話でご連絡ください。', array( 'status' => 500 ) );
	}
	if ( $tkey ) {
		set_transient( $tkey, 1, DAY_IN_SECONDS );
	}
	kaia_log_event( 'inquiry_ok', '', '', $meta ); // 保存できたときだけ数える
	return array( 'ok' => true );
}
