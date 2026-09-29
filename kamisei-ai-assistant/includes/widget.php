<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! kaia_get( 'enabled' ) ) {
		return;
	}
	$quick = array_slice( array_filter( array_map( 'trim', preg_split( '/[\r\n,、]+/u', (string) kaia_get( 'quick_keywords' ) ) ) ), 0, 10 );

	wp_enqueue_style( 'kaia', KAIA_URL . 'assets/chat.css', array(), KAIA_VERSION );
	wp_enqueue_script( 'kaia', KAIA_URL . 'assets/chat.js', array(), KAIA_VERSION, true );
	wp_localize_script( 'kaia', 'KAIA', array(
		'mode'        => kaia_api_key() ? 'ai' : 'search',
		'endpoint'    => esc_url_raw( rest_url( 'kaia/v1/' ) ),
		'greeting'    => kaia_get( 'greeting' ),
		'company'     => kaia_get( 'company_name' ),
		'phone'       => kaia_get( 'phone' ),
		'reservation' => kaia_get( 'reservation_url' ),
		'privacy'     => kaia_get( 'privacy_url' ),
		'quick'       => array_values( $quick ),
	) );
} );
