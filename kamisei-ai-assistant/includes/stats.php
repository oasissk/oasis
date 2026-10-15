<?php
/**
 * 利用状況の記録と管理画面での表示。
 * 個人情報(お名前・連絡先・住所・自由記入の文章)は記録しない。
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KAIA_DB_VER', '1' );

/** 画面から送られてくる記録の種類(検索・問い合わせ・AIの会話はサーバー側で記録する) */
function kaia_client_event_types() {
	return array( 'open', 'choice', 'done', 'form', 'card' );
}

function kaia_events_table() {
	global $wpdb;
	return $wpdb->prefix . 'kaia_events';
}

// テーブル作成(上書きアップロードでは有効化処理が走らないため、バージョンを見て作る)
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
		PRIMARY KEY  (id),
		KEY type_created (type,created_at)
	) " . $wpdb->get_charset_collate() . ';' );
	update_option( 'kaia_db_ver', KAIA_DB_VER, false );
} );

/** 1件記録する。 */
function kaia_log_event( $type, $label = '', $step = '' ) {
	global $wpdb;
	$wpdb->insert( kaia_events_table(), array(
		'created_at' => current_time( 'mysql' ),
		'type'       => substr( sanitize_key( $type ), 0, 20 ),
		'step'       => mb_substr( sanitize_text_field( (string) $step ), 0, 50 ),
		'label'      => mb_substr( sanitize_text_field( (string) $label ), 0, 100 ),
	) );
	// 古い記録(400日より前)をときどき片付ける
	if ( 1 === wp_rand( 1, 200 ) ) {
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . kaia_events_table() . ' WHERE created_at < %s', wp_date( 'Y-m-d H:i:s', time() - 400 * DAY_IN_SECONDS ) ) );
	}
}

// 画面からの記録の受け口
add_action( 'rest_api_init', function () {
	register_rest_route( 'kaia/v1', '/event', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => function ( WP_REST_Request $req ) {
			$type = (string) $req->get_param( 'type' );
			if ( ! in_array( $type, kaia_client_event_types(), true ) ) {
				return new WP_Error( 'kaia_bad', 'bad type', array( 'status' => 400 ) );
			}
			$ok = kaia_rate_check( 'event', 10 );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
			kaia_log_event( $type, (string) $req->get_param( 'label' ), (string) $req->get_param( 'step' ) );
			return array( 'ok' => true );
		},
	) );
} );

// ---- 管理画面「利用状況」

add_action( 'admin_menu', function () {
	add_submenu_page( 'edit.php?post_type=kaia_lead', '利用状況', '利用状況', 'edit_others_posts', 'kaia-stats', 'kaia_render_stats' );
} );

function kaia_render_stats() {
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		return;
	}
	global $wpdb;
	$t = kaia_events_table();

	// リセット
	if ( isset( $_POST['kaia_reset'] ) && current_user_can( 'manage_options' ) && check_admin_referer( 'kaia_reset_stats' ) ) {
		$wpdb->query( "TRUNCATE TABLE {$t}" ); // phpcs:ignore
		echo '<div class="notice notice-success"><p>記録をリセットしました。</p></div>';
	}

	$days    = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
	$periods = array( 7 => '7日', 30 => '30日', 90 => '90日', 0 => '全期間' );
	if ( ! isset( $periods[ $days ] ) ) {
		$days = 30;
	}
	$since = $days ? wp_date( 'Y-m-d 00:00:00', time() - ( $days - 1 ) * DAY_IN_SECONDS ) : '1970-01-01 00:00:00';

	$count = function ( $type ) use ( $wpdb, $t, $since ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE type = %s AND created_at >= %s", $type, $since ) ); // phpcs:ignore
	};
	$rank = function ( $type, $step = null, $limit = 30 ) use ( $wpdb, $t, $since ) {
		if ( null === $step ) {
			return $wpdb->get_results( $wpdb->prepare( "SELECT label, COUNT(*) AS c FROM {$t} WHERE type = %s AND created_at >= %s GROUP BY label ORDER BY c DESC LIMIT %d", $type, $since, $limit ) ); // phpcs:ignore
		}
		return $wpdb->get_results( $wpdb->prepare( "SELECT label, COUNT(*) AS c FROM {$t} WHERE type = %s AND step = %s AND created_at >= %s GROUP BY label ORDER BY c DESC LIMIT %d", $type, $step, $since, $limit ) ); // phpcs:ignore
	};
	$table = function ( $title, $rows, $empty = 'まだ記録がありません' ) {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html( $empty ) . '</p>';
			return;
		}
		$max = max( array_map( function ( $r ) {
			return (int) $r->c;
		}, $rows ) );
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $rows as $r ) {
			$w = $max ? round( 100 * $r->c / $max ) : 0;
			echo '<tr><td style="width:45%">' . esc_html( '' !== $r->label ? $r->label : '(なし)' ) . '</td><td><div style="background:#2271b1;height:12px;border-radius:3px;width:' . (int) $w . '%;min-width:2px"></div></td><td style="width:60px;text-align:right">' . (int) $r->c . '</td></tr>';
		}
		echo '</tbody></table>';
	};

	$opened  = $count( 'open' );
	$started = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE type = 'choice' AND step = %s AND created_at >= %s", kaia_first_step_key(), $since ) ); // phpcs:ignore
	$done    = $count( 'done' );
	$form    = $count( 'form' );
	$inq     = $count( 'inquiry' );
	$chats   = $count( 'chat' );
	$pct     = function ( $a, $b ) {
		return $b ? round( 100 * $a / $b ) . '%' : '-';
	};
	?>
	<div class="wrap">
		<h1>利用状況</h1>
		<p>
			<?php foreach ( $periods as $d => $label ) : ?>
				<a class="button <?php echo $d === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'days', $d ) ); ?>"><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</p>
		<p class="description">お名前・連絡先・住所・自由記入の文章は記録していません。管理者が試した操作も数に含まれます(公開前に下のボタンでリセットできます)。</p>

		<h2>全体の流れ</h2>
		<table class="widefat striped" style="max-width:760px">
			<thead><tr><th>段階</th><th style="text-align:right">回数</th><th style="text-align:right">開いた回数に対する割合</th></tr></thead>
			<tbody>
				<tr><td>チャットを開いた</td><td style="text-align:right"><?php echo (int) $opened; ?></td><td style="text-align:right">100%</td></tr>
				<tr><td>診断を始めた(最初の質問に答えた)</td><td style="text-align:right"><?php echo (int) $started; ?></td><td style="text-align:right"><?php echo esc_html( $pct( $started, $opened ) ); ?></td></tr>
				<tr><td>診断を最後まで進めた</td><td style="text-align:right"><?php echo (int) $done; ?></td><td style="text-align:right"><?php echo esc_html( $pct( $done, $opened ) ); ?></td></tr>
				<tr><td>「担当者に相談する」を押した</td><td style="text-align:right"><?php echo (int) $form; ?></td><td style="text-align:right"><?php echo esc_html( $pct( $form, $opened ) ); ?></td></tr>
				<tr><td><strong>問い合わせを送った</strong></td><td style="text-align:right"><strong><?php echo (int) $inq; ?></strong></td><td style="text-align:right"><strong><?php echo esc_html( $pct( $inq, $opened ) ); ?></strong></td></tr>
				<?php if ( $chats ) : ?>
					<tr><td>AIチャットでのやりとり(回)</td><td style="text-align:right"><?php echo (int) $chats; ?></td><td></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<h2>診断で選ばれた答え</h2>
		<?php
		foreach ( kaia_flow() as $step ) {
			$table( $step['key'] . '(' . $step['q'] . ')', $rank( 'choice', $step['key'] ) );
		}
		?>

		<h2>検索欄に入力されたキーワード</h2>
		<?php $table( '上位30件', $rank( 'search' ) ); ?>

		<h2>クリックされた施工事例・記事</h2>
		<?php $table( '上位30件', $rank( 'card' ) ); ?>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<hr>
			<h2>記録のリセット</h2>
			<form method="post" onsubmit="return confirm('すべての利用記録を削除します。よろしいですか?(問い合わせの記録は消えません)');">
				<?php wp_nonce_field( 'kaia_reset_stats' ); ?>
				<p class="description">公開前に試した操作の記録を消すときに使います。問い合わせの記録(「AIチャットの問い合わせ」)は消えません。</p>
				<?php submit_button( '利用記録をリセット', 'delete', 'kaia_reset', false ); ?>
			</form>
		<?php endif; ?>
	</div>
	<?php
}

/** 最初の質問のキー(「診断を始めた」の数え方に使う) */
function kaia_first_step_key() {
	$flow = kaia_flow();
	return isset( $flow['start']['key'] ) ? $flow['start']['key'] : '';
}
