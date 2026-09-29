<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kaia_defaults() {
	return array(
		'enabled'         => 1,
		'api_key'         => '',
		'model'           => 'claude-sonnet-5-5',
		'company_name'    => '神清',
		'post_type'       => 'post',
		'notify_email'    => get_option( 'admin_email' ),
		'phone'           => '',
		'reservation_url' => '',
		'privacy_url'     => '',
		'greeting'        => 'こんにちは!リフォームのご相談をお手伝いします。どちらを直したいですか?',
		'extra_prompt'    => '',
		'rate_limit'      => 20,
	);
}

function kaia_get( $key = null ) {
	$opts = wp_parse_args( get_option( KAIA_OPTION, array() ), kaia_defaults() );
	return null === $key ? $opts : ( $opts[ $key ] ?? null );
}

add_action( 'admin_menu', function () {
	add_options_page( 'AIアシスタント', 'AIアシスタント', 'manage_options', 'kaia', 'kaia_render_settings' );
} );

add_action( 'admin_init', function () {
	register_setting( 'kaia', KAIA_OPTION, array( 'sanitize_callback' => 'kaia_sanitize' ) );
} );

function kaia_sanitize( $in ) {
	$old = kaia_get();
	$out = array(
		'enabled'         => empty( $in['enabled'] ) ? 0 : 1,
		'api_key'         => empty( $in['api_key'] ) ? $old['api_key'] : sanitize_text_field( $in['api_key'] ),
		'model'           => sanitize_text_field( $in['model'] ?? '' ),
		'company_name'    => sanitize_text_field( $in['company_name'] ?? '' ),
		'post_type'       => sanitize_key( $in['post_type'] ?? 'post' ),
		'notify_email'    => sanitize_email( $in['notify_email'] ?? '' ),
		'phone'           => sanitize_text_field( $in['phone'] ?? '' ),
		'reservation_url' => esc_url_raw( $in['reservation_url'] ?? '' ),
		'privacy_url'     => esc_url_raw( $in['privacy_url'] ?? '' ),
		'greeting'        => sanitize_text_field( $in['greeting'] ?? '' ),
		'extra_prompt'    => sanitize_textarea_field( $in['extra_prompt'] ?? '' ),
		'rate_limit'      => max( 1, (int) ( $in['rate_limit'] ?? 20 ) ),
	);
	return $out;
}

function kaia_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$o     = kaia_get();
	$types = get_post_types( array( 'public' => true ), 'objects' );
	$f     = function ( $k ) {
		return KAIA_OPTION . '[' . $k . ']';
	};
	?>
	<div class="wrap">
		<h1>AIアシスタント設定</h1>
		<form method="post" action="options.php">
			<?php settings_fields( 'kaia' ); ?>
			<table class="form-table" role="presentation">
				<tr><th>チャットを表示</th><td><label><input type="checkbox" name="<?php echo esc_attr( $f( 'enabled' ) ); ?>" value="1" <?php checked( $o['enabled'] ); ?>> 有効にする</label></td></tr>
				<tr><th>Anthropic APIキー</th><td>
					<input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $f( 'api_key' ) ); ?>" placeholder="<?php echo $o['api_key'] ? '設定済み(変更する場合のみ入力)' : 'sk-ant-...'; ?>">
					<p class="description">wp-config.php に <code>define( 'KAIA_API_KEY', '...' );</code> と書けば、こちらより優先されます(推奨)。</p></td></tr>
				<tr><th>モデル</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $f( 'model' ) ); ?>" value="<?php echo esc_attr( $o['model'] ); ?>"></td></tr>
				<tr><th>会社名</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $f( 'company_name' ) ); ?>" value="<?php echo esc_attr( $o['company_name'] ); ?>"></td></tr>
				<tr><th>施工事例の投稿タイプ</th><td>
					<select name="<?php echo esc_attr( $f( 'post_type' ) ); ?>">
						<?php foreach ( $types as $t ) : ?>
							<option value="<?php echo esc_attr( $t->name ); ?>" <?php selected( $o['post_type'], $t->name ); ?>><?php echo esc_html( $t->label . ' (' . $t->name . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">施工事例が入っている投稿タイプを選びます。</p></td></tr>
				<tr><th>通知先メール</th><td><input type="email" class="regular-text" name="<?php echo esc_attr( $f( 'notify_email' ) ); ?>" value="<?php echo esc_attr( $o['notify_email'] ); ?>"></td></tr>
				<tr><th>電話番号</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $f( 'phone' ) ); ?>" value="<?php echo esc_attr( $o['phone'] ); ?>"></td></tr>
				<tr><th>現地調査の予約ページURL</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $f( 'reservation_url' ) ); ?>" value="<?php echo esc_attr( $o['reservation_url'] ); ?>"></td></tr>
				<tr><th>プライバシーポリシーURL</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $f( 'privacy_url' ) ); ?>" value="<?php echo esc_attr( $o['privacy_url'] ); ?>"></td></tr>
				<tr><th>最初のあいさつ</th><td><input type="text" class="large-text" name="<?php echo esc_attr( $f( 'greeting' ) ); ?>" value="<?php echo esc_attr( $o['greeting'] ); ?>"></td></tr>
				<tr><th>追加の指示</th><td><textarea class="large-text" rows="5" name="<?php echo esc_attr( $f( 'extra_prompt' ) ); ?>"><?php echo esc_textarea( $o['extra_prompt'] ); ?></textarea>
					<p class="description">対応エリア、得意な工事、料金の考え方など、AIに伝えておきたいことを書きます。</p></td></tr>
				<tr><th>1時間あたりの上限(1人)</th><td><input type="number" min="1" name="<?php echo esc_attr( $f( 'rate_limit' ) ); ?>" value="<?php echo esc_attr( $o['rate_limit'] ); ?>"> 回<p class="description">API利用料の暴走を防ぎます。</p></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
