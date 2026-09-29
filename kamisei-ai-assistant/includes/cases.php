<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** 設定の「言い換え」を [[a,b,c], ...] に変換する。 */
function kaia_synonym_groups() {
	$groups = array();
	foreach ( preg_split( '/\R/u', (string) kaia_get( 'synonyms' ), -1, PREG_SPLIT_NO_EMPTY ) as $line ) {
		$g = array_values( array_filter( array_map( 'trim', preg_split( '/[,、]/u', $line ) ) ) );
		if ( count( $g ) > 1 ) {
			$groups[] = $g;
		}
	}
	return $groups;
}

/** サイトのタグ名の一覧(文章の中から検索語を拾うのに使う)。1時間キャッシュ。 */
function kaia_vocab() {
	$v = get_transient( 'kaia_vocab' );
	if ( false !== $v ) {
		return $v;
	}
	$names = get_terms( array( 'taxonomy' => 'post_tag', 'hide_empty' => true, 'fields' => 'names', 'number' => 3000 ) );
	$names = is_wp_error( $names ) ? array() : array_values( array_unique( array_filter( $names, function ( $n ) {
		return mb_strlen( $n ) >= 2 && mb_strlen( $n ) <= 20;
	} ) ) );
	set_transient( 'kaia_vocab', $names, HOUR_IN_SECONDS );
	return $names;
}

/**
 * 入力(キーワードや自由記入の文章)から、重み付きの検索語を作る。
 * 2 = 入力そのもの・文中に見つかった語 / 1 = 言い換え・ひらがなカタカナ違い
 *
 * @return array<string,int>
 */
function kaia_build_terms( $input ) {
	$input   = trim( (string) $input );
	$primary = array();

	// 1) 空白区切りの短い語はそのまま使う(長い文は語として使わない)
	foreach ( preg_split( '/[\s、,,・]+/u', $input, -1, PREG_SPLIT_NO_EMPTY ) as $tok ) {
		if ( mb_strlen( $tok ) <= 12 ) {
			$primary[] = $tok;
		}
	}
	// 2) 文中にタグ名や言い換え語が含まれていれば拾う(長い語を優先)
	$known = kaia_vocab();
	foreach ( kaia_synonym_groups() as $g ) {
		$known = array_merge( $known, $g );
	}
	usort( $known, function ( $a, $b ) {
		return mb_strlen( $b ) - mb_strlen( $a );
	} );
	foreach ( $known as $name ) {
		if ( false !== mb_stripos( $input, $name ) ) {
			$primary[] = $name;
		}
	}
	$primary = array_slice( array_values( array_unique( $primary ) ), 0, 6 );

	$terms = array();
	foreach ( $primary as $p ) {
		$terms[ $p ] = 2;
	}
	// 3) 言い換え
	foreach ( kaia_synonym_groups() as $g ) {
		if ( array_intersect( array_map( 'mb_strtolower', $g ), array_map( 'mb_strtolower', $primary ) ) ) {
			foreach ( $g as $alt ) {
				$terms[ $alt ] = $terms[ $alt ] ?? 1;
			}
		}
	}
	// 4) ひらがな⇔カタカナ
	foreach ( $primary as $p ) {
		if ( ! preg_match( '/^[\p{Hiragana}\p{Katakana}ー]+$/u', $p ) ) {
			continue; // 漢字を含む語は変換しない
		}
		foreach ( array( 'C', 'c' ) as $mode ) {
			$alt = mb_convert_kana( $p, $mode );
			if ( $alt !== $p ) {
				$terms[ $alt ] = $terms[ $alt ] ?? 1;
			}
		}
	}
	return array_slice( $terms, 0, 8, true );
}

/** 選んだカテゴリーとその子カテゴリーのID。 */
function kaia_scope_ids( array $cat_ids ) {
	$ids = array_map( 'absint', $cat_ids );
	foreach ( $cat_ids as $id ) {
		$kids = get_term_children( (int) $id, 'category' );
		if ( ! is_wp_error( $kids ) ) {
			$ids = array_merge( $ids, $kids );
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * 施工事例・記事を検索する。タイトル・本文に加え、タグ名・カテゴリー名にもヒットさせる。
 * $unrestricted = true はテスト用(カテゴリーで絞らない)。
 */
function kaia_search_posts( $input, array $cat_ids, $kind, $limit = 3, $unrestricted = false ) {
	if ( ! $cat_ids && ! $unrestricted ) {
		return array(); // カテゴリー未設定なら何も紹介しない(ブログ等の混入防止)
	}
	$terms = kaia_build_terms( $input );
	if ( ! $terms ) {
		return array();
	}

	$post_type = kaia_get( 'post_type' );
	$base      = array(
		'post_type'      => $post_type,
		'post_status'    => 'publish',
		'posts_per_page' => 20,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	);
	if ( ! $unrestricted ) {
		$base['cat'] = implode( ',', array_map( 'absint', $cat_ids ) ); // 子カテゴリーも含む
	}
	$taxes  = array_intersect( array( 'category', 'post_tag' ), get_object_taxonomies( $post_type ) );
	$scores = array();

	foreach ( $terms as $term => $w ) {
		$q = new WP_Query( $base + array( 's' => $term ) ); // タイトル・本文・抜粋
		foreach ( $q->posts as $id ) {
			$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + $w;
		}
		if ( $w >= 2 ) {                                      // 入力した言葉がタイトルにある記事は最優先
			$q = new WP_Query( $base + array( 's' => $term, 'search_columns' => array( 'post_title' ) ) );
			foreach ( $q->posts as $id ) {
				$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + 10;
			}
		}
		foreach ( $taxes as $tax ) {                          // タグ名・カテゴリー名
			$ids = get_terms( array( 'taxonomy' => $tax, 'name__like' => $term, 'fields' => 'ids', 'hide_empty' => true ) );
			if ( is_wp_error( $ids ) || ! $ids ) {
				continue;
			}
			$q = new WP_Query( $base + array(
				'tax_query' => array( array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => $ids ) ),
			) );
			foreach ( $q->posts as $id ) {
				$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + $w;
			}
		}
	}
	if ( ! $scores ) {
		return array();
	}
	arsort( $scores );

	$out = array();
	foreach ( array_slice( array_keys( $scores ), 0, $limit, true ) as $id ) {
		$out[] = array(
			'id'      => $id,
			'kind'    => $kind,
			'score'   => $scores[ $id ],
			'title'   => get_the_title( $id ),
			'url'     => get_permalink( $id ),
			'summary' => wp_trim_words( wp_strip_all_tags( get_post_field( 'post_content', $id ) ), 60, '…' ),
			'image'   => (string) get_the_post_thumbnail_url( $id, 'medium' ),
		);
	}
	return $out;
}

function kaia_case_cats() {
	$cat = (int) kaia_get( 'category' );
	return $cat ? array( $cat ) : array();
}

function kaia_search_cases( $input ) {
	return kaia_search_posts( $input, kaia_case_cats(), 'case', 3 );
}

function kaia_search_articles( $input ) {
	return kaia_search_posts( $input, (array) kaia_get( 'article_cats' ), 'article', 2 );
}
