<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	register_post_type( 'kaia_lead', array(
		'labels'       => array( 'name' => 'AIチャットの問い合わせ', 'singular_name' => '問い合わせ' ),
		'public'       => false,
		'show_ui'      => true,
		'menu_icon'    => 'dashicons-format-chat',
		'supports'     => array( 'title', 'editor' ),
		'capabilities' => array( 'create_posts' => 'do_not_allow' ),
		'map_meta_cap' => true,
	) );
} );

/**
 * 問い合わせを保存し、担当者にメールする。
 */
function kaia_save_lead( $name, $contact, $summary, $transcript ) {
	$body = "お名前: {$name}\n連絡先: {$contact}\n\n■ 要望のまとめ\n{$summary}\n\n■ 会話ログ\n{$transcript}";

	$id = wp_insert_post( array(
		'post_type'    => 'kaia_lead',
		'post_status'  => 'private',
		'post_title'   => $name . ' 様',
		'post_content' => $body,
	) );

	$to = kaia_get( 'notify_email' );
	if ( $to ) {
		wp_mail( $to, '[AIチャット] ' . $name . ' 様から相談がありました', $body );
	}
	return $id;
}
