<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 施工事例をキーワード検索し、AIとカード表示の両方で使う形式で返す。
 */
function kaia_search_cases( $keywords, $limit = 3 ) {
	$post_type = kaia_get( 'post_type' );
	$terms     = preg_split( '/[\s、,,・]+/u', trim( (string) $keywords ), -1, PREG_SPLIT_NO_EMPTY );
	$terms     = array_slice( $terms, 0, 5 );
	$scores    = array();

	foreach ( $terms as $term ) {
		$q = new WP_Query( array(
			'post_type'      => $post_type,
			'post_status'    => 'publish',
			's'              => $term,
			'posts_per_page' => 10,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		foreach ( $q->posts as $id ) {
			$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + 1;
		}
	}

	if ( ! $scores ) {
		return array();
	}
	arsort( $scores );

	$out = array();
	foreach ( array_slice( array_keys( $scores ), 0, $limit ) as $id ) {
		$out[] = array(
			'id'      => $id,
			'title'   => get_the_title( $id ),
			'url'     => get_permalink( $id ),
			'summary' => wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $id ) ), 60, '…' ),
			'image'   => (string) get_the_post_thumbnail_url( $id, 'medium' ),
		);
	}
	return $out;
}
