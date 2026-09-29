<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! kaia_get( 'enabled' ) || ! kaia_api_key() ) {
		return;
	}
	wp_enqueue_style( 'kaia', KAIA_URL . 'assets/chat.css', array(), KAIA_VERSION );
	wp_enqueue_script( 'kaia', KAIA_URL . 'assets/chat.js', array(), KAIA_VERSION, true );
	wp_localize_script( 'kaia', 'KAIA', array(
		'endpoint'   => esc_url_raw( rest_url( 'kaia/v1/chat' ) ),
		'greeting'   => kaia_get( 'greeting' ),
		'company'    => kaia_get( 'company_name' ),
		'phone'      => kaia_get( 'phone' ),
		'reservation' => kaia_get( 'reservation_url' ),
		'privacy'    => kaia_get( 'privacy_url' ),
	) );
} );
