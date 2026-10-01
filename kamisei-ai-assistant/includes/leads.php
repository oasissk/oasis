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
function kaia_save_lead( $name, $contact, $summary, $transcript = '', $page = '', $address = '' ) {
	$body = "お名前: {$name}\n連絡先: {$contact}\nご住所: " . ( '' !== $address ? $address : '(未記入)' ) . "\n受付日時: " . wp_date( 'Y年n月j日 H:i' ) . "\n";
	if ( $page ) {
		$body .= "相談したページ: {$page}\n";
	}
	$body .= "\n■ ご相談内容\n{$summary}\n";
	if ( '' !== trim( (string) $transcript ) ) {
		$body .= "\n■ AIとの会話の記録\n{$transcript}\n"; // AIチャット版のときだけ
	}

	$id = wp_insert_post( array(
		'post_type'    => 'kaia_lead',
		'post_status'  => 'private',
		'post_title'   => $name . ' 様',
		'post_content' => $body,
	) );

	$to = kaia_parse_emails( kaia_get( 'notify_email' ) );
	if ( $to ) {
		$subject = str_replace( '{name}', $name, (string) kaia_get( 'mail_subject' ) );
		$headers = array();
		if ( is_email( $contact ) ) {
			$headers[] = 'Reply-To: ' . $name . ' <' . $contact . '>'; // 返信するとお客様宛てになる
		}
		// 差出人名だけ変える(アドレスはサーバーの既定のまま。迷惑メール判定を避けるため)
		$from_name = function () {
			return (string) kaia_get( 'mail_from_name' );
		};
		add_filter( 'wp_mail_from_name', $from_name, 99 );
		try {
			wp_mail( $to, $subject, $body, $headers );
		} finally {
			remove_filter( 'wp_mail_from_name', $from_name, 99 ); // ほかのメール(Contact Form 7 など)には影響させない
		}
	}
	return $id;
}
