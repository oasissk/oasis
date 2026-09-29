<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'kaia/v1', '/chat', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'kaia_rest_chat',
	) );
} );

function kaia_rest_chat( WP_REST_Request $req ) {
	if ( ! kaia_get( 'enabled' ) ) {
		return new WP_Error( 'kaia_off', '現在ご利用いただけません。', array( 'status' => 503 ) );
	}

	// 簡易レート制限(IPごと・1時間)
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$tkey  = 'kaia_rl_' . md5( $ip );
	$count = (int) get_transient( $tkey );
	if ( $count >= (int) kaia_get( 'rate_limit' ) ) {
		return new WP_Error( 'kaia_rate', '短時間にご利用が集中しています。お電話でもご相談いただけます。', array( 'status' => 429 ) );
	}
	set_transient( $tkey, $count + 1, HOUR_IN_SECONDS );

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
	if ( ! $messages || 'user' !== end( $messages )['role'] ) {
		return new WP_Error( 'kaia_bad', '入力が正しくありません。', array( 'status' => 400 ) );
	}
	// 先頭は必ず user から始める
	while ( $messages && 'user' !== $messages[0]['role'] ) {
		array_shift( $messages );
	}

	$res = kaia_chat( $messages );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( $res->get_error_code(), '申し訳ありません、うまく応答できませんでした。お電話でもご相談いただけます。', array( 'status' => 502 ) );
	}
	return $res;
}
