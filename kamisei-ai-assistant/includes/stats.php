<?php
/**
 * 利用状況の記録と管理画面での表示。
 *
 * 記録の版:
 *  ver=1 … v0.4 までの記録(旧集計)。ページごとの「開く」、ルートなし、検索文そのもの等。参考扱いで、新しい記録とは合算しない。
 *  ver=2 … v0.5 からの記録。セッション番号・ルート(diag/search/ai)・分類コード・テスト区分つき。
 *
 * 個人情報(お名前・連絡先・住所・相談本文)と、自由入力の文章(診断の自由記入・検索文)は記録しない。
 * 画面から届く記録は、決められたコードだけを受け付ける。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KAIA_DB_VER', '2' );

function kaia_events_table() {
	global $wpdb;
	return $wpdb->prefix . 'kaia_events';
}

// テーブル作成・列の追加(上書きアップロードでは有効化処理が走らないため、バージョンを見て行う)。
// 既存の行は消さない。追加した列の既定値により、既存の行は ver=1(旧集計)になる。
add_action( 'plugins_loaded', function () {
	if ( get_option( 'kaia_db_ver' ) === KAIA_DB_VER ) {
		return;
	}
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( 'CREATE TABLE ' . kaia_events_table() . " (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		created_at datetime NOT NULL,
		type varchar(20) NOT NULL,
		step varchar(50) NOT NULL DEFAULT '',
		label varchar(100) NOT NULL DEFAULT '',
		ver tinyint(3) unsigned NOT NULL DEFAULT 1,
		sid varchar(16) NOT NULL DEFAULT '',
		route varchar(10) NOT NULL DEFAULT '',
		is_test tinyint(1) unsigned NOT NULL DEFAULT 0,
		PRIMARY KEY  (id),
		KEY type_created (type,created_at),
		KEY ver_created (ver,created_at)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'kaia_db_ver', KAIA_DB_VER, false );
} );

/**
 * 1件記録する。
 * $extra: ver(既定2) / sid / route / is_test
 * 古い記録の自動削除はしない(保存期間は別途決める)。
 */
function kaia_log_event( $type, $label = '', $step = '', $extra = array() ) {
	global $wpdb;
	$wpdb->insert( kaia_events_table(), array(
		'created_at' => current_time( 'mysql' ),
		'type'       => substr( sanitize_key( $type ), 0, 20 ),
		'step'       => mb_substr( sanitize_text_field( (string) $step ), 0, 50 ),
		'label'      => mb_substr( sanitize_text_field( (string) $label ), 0, 100 ),
		'ver'        => isset( $extra['ver'] ) ? (int) $extra['ver'] : 2,
		'sid'        => kaia_clean_sid( $extra['sid'] ?? '' ),
		'route'      => kaia_clean_route( $extra['route'] ?? '' ),
		'is_test'    => empty( $extra['is_test'] ) ? 0 : 1,
	) );
}

function kaia_clean_sid( $sid ) {
	$sid = (string) $sid;
	return preg_match( '/^[a-z0-9]{8,16}$/', $sid ) ? $sid : '';
}

function kaia_clean_route( $route ) {
	return in_array( $route, array( 'diag', 'search', 'ai' ), true ) ? $route : '';
}

/** リクエストから、セッション番号・テスト区分を取り出す(検索・問い合わせでも使う)。 */
function kaia_req_meta( WP_REST_Request $req, $route = '' ) {
	return array(
		'ver'     => 2,
		'sid'     => kaia_clean_sid( $req->get_param( 'sid' ) ),
		'route'   => kaia_clean_route( '' !== $route ? $route : (string) $req->get_param( 'route' ) ),
		'is_test' => $req->get_param( 'test' ) ? 1 : 0,
	);
}

/**
 * 検索文から分類コードを決める(検索文そのものは記録しない)。
 * 上から順に、最初に当てはまったものを使う。
 */
function kaia_search_categories() {
	return array(
		'skylight'   => array( 'label' => '天窓', 'words' => array( '天窓', 'トップライト', 'スカイライト' ) ),
		'gutter'     => array( 'label' => '雨樋', 'words' => array( '雨樋', '雨どい', '雨とい', 'あまどい', '軒樋', '竪樋', 'とい' ) ),
		'leak'       => array( 'label' => '雨漏り', 'words' => array( '雨漏', '雨もり', 'あまもり', '水漏れ', 'シミ', 'しみ' ) ),
		'waterproof' => array( 'label' => '防水・ベランダ', 'words' => array( '防水', 'ベランダ', 'バルコニー', '屋上', '陸屋根' ) ),
		'wall'       => array( 'label' => '外壁', 'words' => array( '外壁', 'サイディング', 'モルタル', 'ひび', 'クラック', '塗装' ) ),
		'damage'     => array( 'label' => '台風・地震', 'words' => array( '台風', '地震', '強風', '風災', '雪' ) ),
		'roof'       => array( 'label' => '屋根', 'words' => array( '屋根', '瓦', 'かわら', 'スレート', 'カラーベスト', 'コロニアル', '葺き替え', '板金', '漆喰', '棟' ) ),
	);
}

function kaia_search_category( $text ) {
	$text = (string) $text;
	foreach ( kaia_search_categories() as $code => $c ) {
		foreach ( $c['words'] as $w ) {
			if ( false !== mb_stripos( $text, $w ) ) {
				return $code;
			}
		}
	}
	return 'other';
}

/** 診断の質問・選択肢のコード表 [step_code => [option_code => label]] */
function kaia_flow_codes() {
	$out = array();
	foreach ( kaia_flow() as $step ) {
		if ( empty( $step['code'] ) ) {
			continue;
		}
		$out[ $step['code'] ] = array( 'key' => $step['key'], 'opts' => array() );
		foreach ( $step['opts'] as $o ) {
			if ( ! empty( $o['code'] ) ) {
				$out[ $step['code'] ]['opts'][ $o['code'] ] = $o['label'];
			}
		}
	}
	return $out;
}

/**
 * 画面からの記録の受け口。
 * ver=2: 決められた種類・コードだけ受け付ける。
 * それ以外(更新前の画面がブラウザに残っている場合など)は旧集計(ver=1)として受け付ける。
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'kaia/v1', '/event', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'kaia_rest_event',
	) );
} );

function kaia_rest_event( WP_REST_Request $req ) {
	$type = (string) $req->get_param( 'type' );
	$bad  = new WP_Error( 'kaia_bad', 'bad event', array( 'status' => 400 ) );

	if ( 2 !== (int) $req->get_param( 'v' ) ) {
		// 旧画面からの記録(参考扱い)
		if ( ! in_array( $type, array( 'open', 'choice', 'done', 'form', 'card' ), true ) ) {
			return $bad;
		}
		$ok = kaia_rate_check( 'event', 10 );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		kaia_log_event( $type, (string) $req->get_param( 'label' ), (string) $req->get_param( 'step' ), array( 'ver' => 1 ) );
		return array( 'ok' => true );
	}

	$meta = kaia_req_meta( $req );
	$step = (string) $req->get_param( 'step' );
	$code = (string) $req->get_param( 'code' );
	if ( '' === $meta['sid'] ) {
		return $bad;
	}

	switch ( $type ) {
		case 'open':
			$step = '';
			$code = '';
			$meta['route'] = '';
			break;
		case 'answer':     // 診断の回答: step=質問コード, code=選択肢コード
		case 'complete':   // 診断の完了: step='', code=最初の質問の選択肢コード(症状)
			$codes = kaia_flow_codes();
			if ( 'complete' === $type ) {
				$step = '';
				if ( ! isset( $codes['q1']['opts'][ $code ] ) ) {
					return $bad;
				}
			} elseif ( ! isset( $codes[ $step ]['opts'][ $code ] ) ) {
				return $bad;
			}
			$meta['route'] = 'diag';
			break;
		case 'article':    // 記事のクリック: code=投稿ID, step=表示順
			$pid = absint( $code );
			if ( ! $pid || 'publish' !== get_post_status( $pid ) ) {
				return $bad;
			}
			$code = (string) $pid;
			$step = (string) min( 20, max( 1, absint( $step ) ) );
			if ( ! in_array( $meta['route'], array( 'diag', 'search' ), true ) ) {
				return $bad;
			}
			break;
		case 'consult':    // 「担当者に相談する」を押した
			$step = '';
			$code = '';
			if ( ! in_array( $meta['route'], array( 'diag', 'search' ), true ) ) {
				return $bad;
			}
			break;
		default:
			return $bad;
	}

	$ok = kaia_rate_check( 'event', 10 );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	kaia_log_event( $type, $code, $step, $meta );
	return array( 'ok' => true );
}

// ---- 管理画面「利用状況」

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=kaia_lead', '利用状況', '利用状況', 'edit_others_posts', 'kaia-stats', 'kaia_render_stats' );
} );

/** 期間の指定を読み取る。既定は直近14日。 */
function kaia_stats_range() {
	$today = wp_date( 'Y-m-d' );
	$valid = function ( $d ) {
		return is_string( $d ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? $d : '';
	};
	$preset = isset( $_GET['preset'] ) ? absint( $_GET['preset'] ) : 0;
	$from   = $valid( isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : '' );
	$to     = $valid( isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : '' );
	if ( $preset || ! $from || ! $to ) {
		$days = in_array( $preset, array( 7, 14, 28, 90 ), true ) ? $preset : 14;
		$to   = $today;
		$from = wp_date( 'Y-m-d', strtotime( $today . ' 12:00:00' ) - ( $days - 1 ) * DAY_IN_SECONDS );
	}
	if ( $from > $to ) {
		list( $from, $to ) = array( $to, $from );
	}
	$days = (int) round( ( strtotime( $to ) - strtotime( $from ) ) / DAY_IN_SECONDS ) + 1;
	return array( $from, $to, $days );
}

function kaia_render_stats() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	global $wpdb;
	$t = kaia_events_table();

	list( $from, $to, $days ) = kaia_stats_range();
	$with_test = ! empty( $_GET['with_test'] );
	$s = $from . ' 00:00:00';
	$e = $to . ' 23:59:59';
	// 新しい記録(ver=2)の共通条件
	$w2 = $wpdb->prepare( "ver = 2 AND created_at BETWEEN %s AND %s", $s, $e ) . ( $with_test ? '' : ' AND is_test = 0' );

	$num  = function ( $sql ) use ( $wpdb ) {
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore
	};
	// [回数, セッション数]
	$pair = function ( $cond ) use ( $num, $t, $w2 ) {
		return array(
			$num( "SELECT COUNT(*) FROM {$t} WHERE {$w2} AND {$cond}" ),
			$num( "SELECT COUNT(DISTINCT sid) FROM {$t} WHERE {$w2} AND {$cond}" ),
		);
	};

	$open      = $pair( "type = 'open'" );
	$d_first   = $pair( "type = 'answer' AND step = 'q1'" );
	$d_done    = $pair( "type = 'complete'" );
	$d_article = $pair( "type = 'article' AND route = 'diag'" );
	$d_consult = $pair( "type = 'consult' AND route = 'diag'" );
	$d_inq     = $pair( "type = 'inquiry_ok' AND route = 'diag'" );
	$s_search  = $pair( "type = 'search'" );
	$s_zero    = $pair( "type = 'search' AND step = 'zero'" );
	$s_article = $pair( "type = 'article' AND route = 'search'" );
	$s_consult = $pair( "type = 'consult' AND route = 'search'" );
	$s_inq     = $pair( "type = 'inquiry_ok' AND route = 'search'" );
	$ai_inq    = $pair( "type = 'inquiry_ok' AND route = 'ai'" );
	$neither   = $num( "SELECT COUNT(DISTINCT sid) FROM {$t} WHERE {$w2} AND type = 'open' AND sid NOT IN (SELECT sid FROM {$t} WHERE {$w2} AND type IN ('answer','search'))" );
	$both      = $num( "SELECT COUNT(DISTINCT sid) FROM {$t} WHERE {$w2} AND type = 'answer' AND sid IN (SELECT sid FROM {$t} WHERE {$w2} AND type = 'search')" );
	$tests     = $num( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE ver = 2 AND is_test = 1 AND created_at BETWEEN %s AND %s", $s, $e ) );
	$v2_first  = $wpdb->get_var( "SELECT MIN(created_at) FROM {$t} WHERE ver = 2" ); // phpcs:ignore

	// 点検用
	$leads_v2 = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_kaia_v' AND m.meta_value = '2'
		 LEFT JOIN {$wpdb->postmeta} tm ON tm.post_id = p.ID AND tm.meta_key = '_kaia_test'
		 WHERE p.post_type = 'kaia_lead' AND p.post_date BETWEEN %s AND %s" . ( $with_test ? '' : " AND ( tm.meta_value IS NULL OR tm.meta_value <> '1' )" ),
		$s, $e
	) );
	$inq_all       = $d_inq[0] + $s_inq[0] + $ai_inq[0];
	// (HAVING を使わない書き方にしている: 試験環境のSQLiteでも本番のMySQLでも同じ結果になるように)
	$multi_open    = $num( "SELECT COUNT(DISTINCT a.sid) FROM {$t} a WHERE " . str_replace( array( 'ver ', 'created_at ', 'is_test ' ), array( 'a.ver ', 'a.created_at ', 'a.is_test ' ), $w2 ) . " AND a.type = 'open' AND (SELECT COUNT(*) FROM {$t} b WHERE b.ver = 2 AND b.type = 'open' AND b.sid = a.sid AND b.created_at BETWEEN '{$s}' AND '{$e}') > 1" );
	$done_no_first = $num( "SELECT COUNT(DISTINCT sid) FROM {$t} WHERE {$w2} AND type = 'complete' AND sid NOT IN (SELECT sid FROM {$t} WHERE {$w2} AND type = 'answer' AND step = 'q1')" );
	$inq_no_cons   = $num( "SELECT COUNT(DISTINCT sid) FROM {$t} WHERE {$w2} AND type = 'inquiry_ok' AND route IN ('diag','search') AND sid NOT IN (SELECT sid FROM {$t} WHERE {$w2} AND type = 'consult')" );
	$no_sid        = $num( "SELECT COUNT(*) FROM {$t} WHERE {$w2} AND sid = ''" );

	$base = $open[1];
	$pct  = function ( $n ) use ( $base ) {
		return $base ? round( 100 * $n / $base, 1 ) . '%' : '-';
	};
	$row = function ( $label, $p, $note = '' ) use ( $pct ) {
		echo '<tr><td>' . esc_html( $label ) . ( $note ? '<br><small class="description">' . esc_html( $note ) . '</small>' : '' ) . '</td><td class="num">' . (int) $p[0] . '</td><td class="num">' . (int) $p[1] . '</td><td class="num">' . esc_html( $pct( $p[1] ) ) . '</td></tr>';
	};
	$table_head = '<table class="widefat striped kaia-t"><thead><tr><th>段階</th><th class="num">回数</th><th class="num">セッション数</th><th class="num">開いたセッションに対する割合</th></tr></thead><tbody>';
	$rank_table = function ( $title, $rows, $label_cb ) {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $rows ) {
			echo '<p class="description">この期間の記録はありません</p>';
			return;
		}
		$max = max( array_map( function ( $r ) {
			return (int) $r->c;
		}, $rows ) );
		echo '<table class="widefat striped kaia-t"><tbody>';
		foreach ( $rows as $r ) {
			$w = $max ? round( 100 * $r->c / $max ) : 0;
			echo '<tr><td style="width:50%">' . esc_html( $label_cb( $r ) ) . '</td><td><div style="background:#2271b1;height:12px;border-radius:3px;width:' . (int) $w . '%;min-width:2px"></div></td><td class="num" style="width:60px">' . (int) $r->c . '</td></tr>';
		}
		echo '</tbody></table>';
	};
	$codes = kaia_flow_codes();
	$cats  = kaia_search_categories();
	$url   = admin_url( 'edit.php?post_type=kaia_lead&page=kaia-stats' );
	?>
	<div class="wrap">
		<style>.kaia-t{max-width:860px}.kaia-t .num{text-align:right;white-space:nowrap}.kaia-box{background:#fff;border:1px solid #c3c4c7;padding:10px 14px;max-width:832px;margin:10px 0}</style>
		<h1>利用状況</h1>

		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>" class="kaia-box">
			<input type="hidden" name="post_type" value="kaia_lead"><input type="hidden" name="page" value="kaia-stats">
			<label>開始日 <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
			<label>終了日 <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label>
			<label style="margin-left:12px"><input type="checkbox" name="with_test" value="1" <?php checked( $with_test ); ?>> 管理者・テストの記録を含める</label>
			<?php submit_button( '集計', 'primary', '', false ); ?>
			<p style="margin:8px 0 0">直近:
				<?php foreach ( array( 7, 14, 28, 90 ) as $d ) : ?>
					<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'preset' => $d, 'with_test' => $with_test ? 1 : null ), $url ) ); ?>"><?php echo (int) $d; ?>日</a>
				<?php endforeach; ?>
			</p>
		</form>

		<div class="kaia-box">
			<strong>集計期間: <?php echo esc_html( $from . ' 〜 ' . $to ); ?>(<?php echo (int) $days; ?>日間)</strong>
			/ 管理者・テストの記録: <?php echo $with_test ? '含める' : '除外(' . (int) $tests . '件を除外)'; ?><br>
			新しい計測の開始: <?php echo $v2_first ? esc_html( substr( $v2_first, 0, 16 ) ) : 'まだ記録がありません'; ?>
			<?php if ( $v2_first && $from . ' 00:00:00' < $v2_first ) : ?>
				<br><span style="color:#b32d2e">この期間の一部は、新しい計測を始める前です。その部分は下の数字に含まれません(旧集計は一番下に参考として分けています)。</span>
			<?php endif; ?>
			<p class="description" style="margin:6px 0 0">「回数」は操作の回数、「セッション数」はブラウザのタブ単位の利用数です(人数とは一致しません)。お名前・連絡先・住所・相談本文・自由記入・検索文は記録していません。</p>
		</div>

		<h2>全体</h2>
		<?php echo $table_head; // phpcs:ignore ?>
			<?php $row( 'チャットを開いた', $open, '1ページにつき最初の1回を記録' ); ?>
			<tr><td>開いたが、診断にも検索にも進まなかった</td><td class="num">-</td><td class="num"><?php echo (int) $neither; ?></td><td class="num"><?php echo esc_html( $pct( $neither ) ); ?></td></tr>
			<tr><td>診断と検索の両方を使った</td><td class="num">-</td><td class="num"><?php echo (int) $both; ?></td><td class="num"><?php echo esc_html( $pct( $both ) ); ?></td></tr>
		</tbody></table>

		<h2>診断ルート</h2>
		<?php echo $table_head; // phpcs:ignore ?>
			<?php $row( '診断を始めた(最初の質問に回答)', $d_first, '「最初からやり直す」も回数に含む' ); ?>
			<?php $row( '診断を完了した', $d_done ); ?>
			<?php $row( '参考記事・事例をクリック', $d_article ); ?>
			<?php $row( '「担当者に相談する」を押した', $d_consult ); ?>
			<?php $row( '問い合わせの送信に成功', $d_inq, 'サーバーで保存できたときだけ記録' ); ?>
		</tbody></table>

		<h2>記事検索ルート</h2>
		<?php echo $table_head; // phpcs:ignore ?>
			<?php $row( '検索した', $s_search ); ?>
			<?php $row( 'うち、結果が0件', $s_zero ); ?>
			<?php $row( '記事・事例をクリック', $s_article ); ?>
			<?php $row( '「担当者に相談する」を押した', $s_consult ); ?>
			<?php $row( '問い合わせの送信に成功', $s_inq ); ?>
		</tbody></table>
		<?php if ( $ai_inq[0] ) : ?>
			<p>AIチャット経由の送信成功: <?php echo (int) $ai_inq[0]; ?></p>
		<?php endif; ?>

		<h2>診断で選ばれた答え</h2>
		<?php
		foreach ( $codes as $scode => $st ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT label, COUNT(*) AS c FROM {$t} WHERE {$w2} AND type = 'answer' AND step = %s GROUP BY label ORDER BY c DESC", $scode ) ); // phpcs:ignore
			$rank_table( $st['key'], $rows, function ( $r ) use ( $st ) {
				return isset( $st['opts'][ $r->label ] ) ? $st['opts'][ $r->label ] : $r->label;
			} );
		}
		?>

		<h2>検索の分類</h2>
		<p class="description">検索文そのものは記録していません。入力に含まれる言葉から、決まった分類に振り分けています。</p>
		<?php
		$rows = $wpdb->get_results( "SELECT label, COUNT(*) AS c FROM {$t} WHERE {$w2} AND type = 'search' GROUP BY label ORDER BY c DESC" ); // phpcs:ignore
		$rank_table( '分類別の検索回数', $rows, function ( $r ) use ( $cats ) {
			return isset( $cats[ $r->label ] ) ? $cats[ $r->label ]['label'] : 'その他・分類できない';
		} );
		?>

		<h2>クリックされた記事・事例</h2>
		<?php
		foreach ( array( 'diag' => '診断ルート', 'search' => '記事検索ルート' ) as $rt => $rname ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT label, COUNT(*) AS c FROM {$t} WHERE {$w2} AND type = 'article' AND route = %s GROUP BY label ORDER BY c DESC LIMIT 20", $rt ) ); // phpcs:ignore
			$rank_table( $rname, $rows, function ( $r ) {
				$title = get_the_title( (int) $r->label );
				return '' !== $title ? $title : '(記事ID ' . $r->label . ')';
			} );
		}
		?>

		<h2>計測の点検</h2>
		<table class="widefat striped kaia-t">
			<thead><tr><th>点検項目</th><th class="num">値</th><th>正常な状態</th></tr></thead>
			<tbody>
				<tr><td>送信成功の記録数 / 同じ期間に保存された問い合わせ数(新しい計測分)</td><td class="num"><?php echo (int) $inq_all . ' / ' . (int) $leads_v2; ?></td><td>同じ数</td></tr>
				<tr><td>「開く」が2回以上あるセッション(別ページで開き直した場合)</td><td class="num"><?php echo (int) $multi_open; ?></td><td>多すぎなければ問題なし</td></tr>
				<tr><td>最初の回答がないのに完了しているセッション</td><td class="num"><?php echo (int) $done_no_first; ?></td><td>0</td></tr>
				<tr><td>相談ボタンを押さずに送信成功しているセッション</td><td class="num"><?php echo (int) $inq_no_cons; ?></td><td>0</td></tr>
				<tr><td>セッション番号のない記録</td><td class="num"><?php echo (int) $no_sid; ?></td><td>0</td></tr>
			</tbody>
		</table>

		<h2>旧集計(参考)</h2>
		<details class="kaia-box">
			<summary>v0.4 までの記録を見る(数え方が違うため、上の数字とはつなげて比べないでください)</summary>
			<?php
			$w1 = $wpdb->prepare( 'ver = 1 AND created_at BETWEEN %s AND %s', $s, $e );
			$c1 = function ( $cond ) use ( $num, $t, $w1 ) {
				return $num( "SELECT COUNT(*) FROM {$t} WHERE {$w1} AND {$cond}" );
			};
			?>
			<p class="description">旧集計は、ページを移動するたびに「開く」を数え、管理者のテストも区別できず、相談ボタンは診断と検索が混ざっています。検索文の内容は表示していません(記録は削除していません)。</p>
			<table class="widefat striped kaia-t"><tbody>
				<tr><td>チャットを開いた</td><td class="num"><?php echo (int) $c1( "type = 'open'" ); ?></td></tr>
				<tr><td>最初の質問に回答</td><td class="num"><?php echo (int) $c1( $wpdb->prepare( "type = 'choice' AND step = %s", $codes['q1']['key'] ?? 'お困りごと' ) ); ?></td></tr>
				<tr><td>診断を最後まで進めた</td><td class="num"><?php echo (int) $c1( "type = 'done'" ); ?></td></tr>
				<tr><td>「担当者に相談する」を押した(診断・検索の合計)</td><td class="num"><?php echo (int) $c1( "type = 'form'" ); ?></td></tr>
				<tr><td>問い合わせを送った</td><td class="num"><?php echo (int) $c1( "type = 'inquiry'" ); ?></td></tr>
				<tr><td>検索した回数(検索文は非表示)</td><td class="num"><?php echo (int) $c1( "type = 'search'" ); ?></td></tr>
				<tr><td>記事・事例のクリック</td><td class="num"><?php echo (int) $c1( "type = 'card'" ); ?></td></tr>
			</tbody></table>
		</details>
	</div>
	<?php
}

/** 最初の質問のキー(旧集計で使う) */
function kaia_first_step_key() {
	$flow = kaia_flow();
	return isset( $flow['start']['key'] ) ? $flow['start']['key'] : '';
}
