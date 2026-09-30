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
 * 2 = 入力そのもの・文中に見つかった語 / 1 = 言い換え・ひらがなカタカナ違い(それぞれ $scale 倍)
 *
 * @return array<string,int>
 */
function kaia_build_terms( $input, $scale = 1 ) {
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
		$terms[ $p ] = 2 * $scale;
	}
	// 3) 言い換え
	foreach ( kaia_synonym_groups() as $g ) {
		if ( array_intersect( array_map( 'mb_strtolower', $g ), array_map( 'mb_strtolower', $primary ) ) ) {
			foreach ( $g as $alt ) {
				$terms[ $alt ] = $terms[ $alt ] ?? $scale;
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
				$terms[ $alt ] = $terms[ $alt ] ?? $scale;
			}
		}
	}
	return array_slice( $terms, 0, 8, true );
}

/**
 * 診断で選んだ言葉($keywords)と、お客様が自分で書いた文章($text)から検索語を作る。
 * 自分で書いた言葉のほうが具体的なので、2倍の重みにする。
 */
function kaia_terms_for( $keywords, $text = '' ) {
	$terms = kaia_build_terms( $keywords, 1 );
	foreach ( kaia_build_terms( $text, 2 ) as $t => $w ) {
		$terms[ $t ] = max( $terms[ $t ] ?? 0, $w );
	}
	arsort( $terms );
	return array_slice( $terms, 0, 10, true );
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
	$terms = is_array( $input ) ? $input : kaia_build_terms( $input );
	if ( ! $terms ) {
		return array();
	}

	// 同じ検索は1時間キャッシュ(記事・タグ・設定が変わると番号が変わり自動で作り直す)
	$ckey   = 'kaia_s_' . md5( wp_json_encode( array( $terms, $cat_ids, $kind, $limit, $unrestricted, kaia_get( 'post_type' ), kaia_cache_ver() ) ) );
	$cached = get_transient( $ckey );
	if ( is_array( $cached ) ) {
		return $cached;
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
	$taxes  = array_values( array_intersect( array( 'category', 'post_tag' ), get_object_taxonomies( $post_type ) ) );
	$scores = array();
	$best   = array(); // 当たった語の最大の重み
	$tbest  = array(); // タイトルに入っていた語の最大の重み

	foreach ( $terms as $term => $w ) {
		if ( $w >= 2 ) {                                      // タイトル・本文・抜粋(重みの低い言い換えは省略して軽くする)
			$q = new WP_Query( $base + array( 's' => $term ) );
			foreach ( $q->posts as $id ) {
				$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + $w;
				$best[ $id ]   = max( $best[ $id ] ?? 0, $w );
			}
		}
		if ( $taxes ) {                                       // タグ名・カテゴリー名
			$ids = get_terms( array( 'taxonomy' => $taxes, 'name__like' => $term, 'fields' => 'ids', 'hide_empty' => true ) );
			if ( ! is_wp_error( $ids ) && $ids ) {
				// タグ/カテゴリーのOR条件は入れ子にする(外側はANDのまま、'cat' の絞り込みを保つ)
				$or = array( 'relation' => 'OR' );
				foreach ( $taxes as $tax ) {
					$or[] = array( 'taxonomy' => $tax, 'field' => 'term_id', 'terms' => $ids );
				}
				$q = new WP_Query( $base + array( 'tax_query' => array( $or ) ) );
				foreach ( $q->posts as $id ) {
					$scores[ $id ] = ( $scores[ $id ] ?? 0 ) + $w;
					$best[ $id ]   = max( $best[ $id ] ?? 0, $w );
				}
			}
		}
	}

	// 入力した言葉がタイトルに入っている記事は最優先
	// (重み2の語で+10。お客様が自分で書いた語は重み4なので+20)
	foreach ( array_keys( $scores ) as $id ) {
		$title = get_the_title( $id );
		foreach ( $terms as $t => $w ) {
			if ( $w >= 2 && false !== mb_stripos( $title, $t ) ) {
				$scores[ $id ] += 5 * $w;
				$tbest[ $id ]   = max( $tbest[ $id ] ?? 0, $w );
			}
		}
	}

	if ( ! $scores ) {
		set_transient( $ckey, array(), HOUR_IN_SECONDS );
		return array();
	}
	// 並べ方: ①タイトルに入っていた語の重み ②当たった語の重み ③点数
	$ids = array_keys( $scores );
	usort( $ids, function ( $a, $b ) use ( $scores, $best, $tbest ) {
		return array( $tbest[ $b ] ?? 0, $best[ $b ] ?? 0, $scores[ $b ] ) <=> array( $tbest[ $a ] ?? 0, $best[ $a ] ?? 0, $scores[ $a ] );
	} );

	$out = array();
	foreach ( array_slice( $ids, 0, $limit ) as $id ) {
		$out[] = array(
			'id'      => $id,
			'kind'    => $kind,
			'score'   => $scores[ $id ],
			'rank'    => array( $tbest[ $id ] ?? 0, $best[ $id ] ?? 0, $scores[ $id ] ),
			'title'   => get_the_title( $id ),
			'url'     => get_permalink( $id ),
			'summary' => kaia_summary( $id ),
			'image'   => (string) get_the_post_thumbnail_url( $id, 'medium' ),
		);
	}
	set_transient( $ckey, $out, HOUR_IN_SECONDS );
	return $out;
}

function kaia_case_cats() {
	$cat = (int) kaia_get( 'category' );
	return $cat ? array( $cat ) : array();
}

function kaia_search_cases( $input, $limit = 3 ) {
	return kaia_search_posts( $input, kaia_case_cats(), 'case', $limit );
}

function kaia_search_articles( $input, $limit = 2 ) {
	return kaia_search_posts( $input, (array) kaia_get( 'article_cats' ), 'article', $limit );
}

/**
 * 施工事例・参考記事をまとめて、点数の高い順に並べる。
 * 一番よく合うものと比べて点数が低すぎるもの(関係の薄いもの)は外す。
 */
function kaia_rank_results( array $rows, $limit = 5 ) {
	if ( ! $rows ) {
		return array();
	}
	usort( $rows, function ( $a, $b ) {
		return $b['rank'] <=> $a['rank'];
	} );
	$min  = 3; // ほとんど関係ないもの(点数1〜2)は出さない
	$out  = array();
	$seen = array();
	foreach ( $rows as $r ) {
		if ( $r['score'] < $min || isset( $seen[ $r['id'] ] ) ) {
			continue;
		}
		// タイトルに言葉が入った記事があるときは、本文にしか出てこない弱いものは外す
		if ( $rows[0]['rank'][0] > 0 && 0 === $r['rank'][0] && $r['score'] < $rows[0]['score'] * 0.4 ) {
			continue;
		}
		$seen[ $r['id'] ] = true;
		$out[]            = $r;
	}
	// 1件もしきい値を超えない場合でも、一番上だけは出す
	return array_slice( $out ?: array( $rows[0] ), 0, $limit );
}

/** 本文から表示用の短い要約を作る(ショートコード・タグ・&nbsp; などを除く)。 */
function kaia_summary( $id ) {
	$text = strip_shortcodes( (string) get_post_field( 'post_content', $id ) );
	$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = trim( preg_replace( '/[\s\x{00A0}\x{3000}]+/u', ' ', $text ) );
	return wp_trim_words( $text, 60, '…' );
}

/** 検索キャッシュの番号。記事・タグ・設定が変わったら更新する。 */
function kaia_cache_ver() {
	return (int) get_option( 'kaia_cache_ver', 1 );
}

function kaia_bump_cache() {
	static $done = false;
	if ( ! $done ) {
		$done = true;
		update_option( 'kaia_cache_ver', kaia_cache_ver() + 1, false );
	}
}
foreach ( array( 'save_post_post', 'deleted_post', 'trashed_post', 'set_object_terms', 'edited_term', 'created_term', 'delete_term', 'update_option_kaia_settings' ) as $kaia_hook ) {
	add_action( $kaia_hook, 'kaia_bump_cache' );
}
