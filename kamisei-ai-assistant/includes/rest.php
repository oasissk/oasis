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
function kaia_rate_check() {
	if ( ! kaia_get( 'enabled' ) ) {
		return new WP_Error( 'kaia_off', '現在ご利用いただけません。', array( 'status' => 503 ) );
	}
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$tkey  = 'kaia_rl_' . md5( $ip );
	$count = (int) get_transient( $tkey );
	if ( $count >= (int) kaia_get( 'rate_limit' ) ) {
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

	$res = kaia_chat( $messages );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $res->get_error_code(), '申し訳ありません、うまく応答できませんでした。お電話でもご相談いただけます。', array( 'status' => 502 ) );
	}
	return $res;
}

/** 検索のみモード: AIを使わずキーワードで事例・記事を返す。 */
function kaia_rest_search( WP_REST_Request $req ) {
	$ok = kaia_rate_check();
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$kw = mb_substr( sanitize_text_field( (string) $req->get_param( 'keywords' ) ), 0, 100 )
		. ' ' . mb_substr( sanitize_text_field( (string) $req->get_param( 'text' ) ), 0, 500 );
	$kw = trim( $kw );
	if ( '' === $kw ) {
		return new WP_Error( 'kaia_bad', 'キーワードを入力してください。', array( 'status' => 400 ) );
	}
	return array(
		'cases' => array_merge( kaia_search_cases( $kw ), kaia_search_articles( $kw ) ),
	);
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
	$message = mb_substr( sanitize_textarea_field( (string) $req->get_param( 'message' ) ), 0, 1000 );
	if ( '' === $name || '' === $contact || ! $req->get_param( 'consent' ) ) {
		return new WP_Error( 'kaia_bad', 'お名前・ご連絡先を入力し、同意にチェックを入れてください。', array( 'status' => 400 ) );
	}
	kaia_save_lead( $name, $contact, $message, '(検索のみモードの問い合わせフォームから)' );
	return array( 'ok' => true );
}
