<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! kaia_get( 'enabled' ) ) {
		return;
	}
	wp_enqueue_style( 'kaia', KAIA_URL . 'assets/chat.css', array(), KAIA_VERSION );
	wp_enqueue_script( 'kaia', KAIA_URL . 'assets/chat.js', array(), KAIA_VERSION, true );
	wp_localize_script( 'kaia', 'KAIA', array(
		'mode'        => kaia_api_key() ? 'ai' : 'search',
		'endpoint'    => esc_url_raw( rest_url( 'kaia/v1/' ) ),
		'greeting'    => kaia_get( 'greeting' ),
		'company'     => kaia_get( 'company_name' ),
		'title'       => kaia_get( 'widget_title' ),
		'phone'       => kaia_get( 'phone' ),
		'reservation' => kaia_get( 'reservation_url' ),
		'privacy'     => kaia_get( 'privacy_url' ),
		'flow'        => kaia_flow(),
	) );
} );

// Autoptimize などでまとめられると、設定値(KAIA)より先に読み込まれて動かなくなることがあるため除外する
add_filter( 'autoptimize_filter_js_exclude', function ( $exclude ) {
	$mine = 'kamisei-ai-assistant/assets/chat.js';
	return is_array( $exclude ) ? array_merge( $exclude, array( $mine ) ) : trim( (string) $exclude . ', ' . $mine, ', ' );
} );
