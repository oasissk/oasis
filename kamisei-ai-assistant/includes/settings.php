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
		'widget_title'    => '簡単お困りごと診断',
		'post_type'       => 'post',
		'category'        => 0,
		'article_cats'    => array(),
		'notify_email'    => get_option( 'admin_email' ),
		'phone'           => '',
		'reservation_url' => '',
		'privacy_url'     => '',
		'greeting'        => 'こんにちは!屋根・雨漏り・外壁などのお困りごとをお聞かせください。',
		'extra_prompt'    => '',
		'rate_limit'      => 20,
		'synonyms'        => "バルコニー,ベランダ,陸屋根,屋上\n雨漏り,雨漏れ,水漏れ\n瓦,屋根瓦,瓦屋根\nスカイライトチューブ,天窓,トップライト\n外壁,サイディング,モルタル\n防水,防水工事,防水塗装\n台風,強風,風災\nスレート,カラーベスト,コロニアル",
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
		'model'           => sanitize_text_field( $in['model'] ?? '' ) ?: kaia_defaults()['model'],
		'company_name'    => sanitize_text_field( $in['company_name'] ?? '' ),
		'widget_title'    => sanitize_text_field( $in['widget_title'] ?? '' ) ?: '簡単お困りごと診断',
		'post_type'       => sanitize_key( $in['post_type'] ?? 'post' ),
		'category'        => absint( $in['category'] ?? 0 ),
		'article_cats'    => array_values( array_filter( array_map( 'absint', (array) ( $in['article_cats'] ?? array() ) ) ) ),
		'notify_email'    => sanitize_email( $in['notify_email'] ?? '' ),
		'phone'           => sanitize_text_field( $in['phone'] ?? '' ),
		'reservation_url' => esc_url_raw( $in['reservation_url'] ?? '' ),
		'privacy_url'     => esc_url_raw( $in['privacy_url'] ?? '' ),
		'greeting'        => sanitize_text_field( $in['greeting'] ?? '' ),
		'extra_prompt'    => sanitize_textarea_field( $in['extra_prompt'] ?? '' ),
		'rate_limit'      => max( 1, (int) ( $in['rate_limit'] ?? 20 ) ),
		'synonyms'        => sanitize_textarea_field( $in['synonyms'] ?? '' ),
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
		<?php if ( ! $o['category'] ) : ?>
			<div class="notice notice-warning"><p>「施工事例のカテゴリー」が未設定です。このままでは施工事例が紹介されません。</p></div>
		<?php endif; ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'kaia' ); ?>
			<table class="form-table" role="presentation">
				<tr><th>チャットを表示</th><td><label><input type="checkbox" name="<?php echo esc_attr( $f( 'enabled' ) ); ?>" value="1" <?php checked( $o['enabled'] ); ?>> 有効にする</label></td></tr>
				<tr><th>現在のモード</th><td><strong><?php echo kaia_api_key() ? 'AIチャット' : '検索のみ(APIキー未設定)'; ?></strong>
					<p class="description">APIキーを入れるとAIチャットに切り替わります。未設定のあいだは、キーワード検索と問い合わせフォームだけで動きます(無料)。</p></td></tr>
				<tr><th>Anthropic APIキー</th><td>
					<input type="password" class="regular-text" autocomplete="off" name="<?php echo esc_attr( $f( 'api_key' ) ); ?>" placeholder="<?php echo $o['api_key'] ? '設定済み(変更する場合のみ入力)' : 'sk-ant-...'; ?>">
					<p class="description">wp-config.php に <code>define( 'KAIA_API_KEY', '...' );</code> と書けば、こちらより優先されます(推奨)。</p></td></tr>
				<tr><th>モデル</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $f( 'model' ) ); ?>" value="<?php echo esc_attr( $o['model'] ); ?>"></td></tr>
				<tr><th>会社名</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $f( 'company_name' ) ); ?>" value="<?php echo esc_attr( $o['company_name'] ); ?>"></td></tr>
				<tr><th>チャットの名前</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $f( 'widget_title' ) ); ?>" value="<?php echo esc_attr( $o['widget_title'] ); ?>"></td></tr>
				<tr><th>施工事例の投稿タイプ</th><td>
					<select name="<?php echo esc_attr( $f( 'post_type' ) ); ?>">
						<?php foreach ( $types as $t ) : ?>
							<option value="<?php echo esc_attr( $t->name ); ?>" <?php selected( $o['post_type'], $t->name ); ?>><?php echo esc_html( $t->label . ' (' . $t->name . ')' ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">施工事例が入っている投稿タイプを選びます。</p></td></tr>
				<tr><th>施工事例のカテゴリー</th><td>
					<?php
					wp_dropdown_categories( array(
						'name'            => $f( 'category' ),
						'selected'        => (int) $o['category'],
						'show_option_all' => '(絞り込まない)',
						'hide_empty'      => false,
						'hierarchical'    => true,
					) );
					?>
					<p class="description">施工事例が「投稿」の中にある場合、事例のカテゴリーを選びます。ブログやお知らせが事例として紹介されなくなります。</p></td></tr>
				<tr><th>参考記事のカテゴリー</th><td>
					<?php foreach ( get_categories( array( 'hide_empty' => false ) ) as $c ) : ?>
						<label style="display:inline-block;margin:0 16px 4px 0"><input type="checkbox" name="<?php echo esc_attr( $f( 'article_cats' ) ); ?>[]" value="<?php echo esc_attr( $c->term_id ); ?>" <?php checked( in_array( $c->term_id, (array) $o['article_cats'], true ) ); ?>> <?php echo esc_html( $c->name ); ?> (<?php echo (int) $c->count; ?>)</label>
					<?php endforeach; ?>
					<p class="description">お悩みに関係する解説記事(リフォーム解説、お客様の声など)のカテゴリー。事例の補足として紹介されます。ブログやお知らせは選ばないでください。</p></td></tr>
				<tr><th>通知先メール</th><td><input type="email" class="regular-text" name="<?php echo esc_attr( $f( 'notify_email' ) ); ?>" value="<?php echo esc_attr( $o['notify_email'] ); ?>"></td></tr>
				<tr><th>電話番号</th><td><input type="text" class="regular-text" name="<?php echo esc_attr( $f( 'phone' ) ); ?>" value="<?php echo esc_attr( $o['phone'] ); ?>"></td></tr>
				<tr><th>現地調査の予約ページURL</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $f( 'reservation_url' ) ); ?>" value="<?php echo esc_attr( $o['reservation_url'] ); ?>"></td></tr>
				<tr><th>プライバシーポリシーURL</th><td><input type="url" class="regular-text" name="<?php echo esc_attr( $f( 'privacy_url' ) ); ?>" value="<?php echo esc_attr( $o['privacy_url'] ); ?>"></td></tr>
				<tr><th>最初のあいさつ</th><td><input type="text" class="large-text" name="<?php echo esc_attr( $f( 'greeting' ) ); ?>" value="<?php echo esc_attr( $o['greeting'] ); ?>"></td></tr>
				<tr><th>追加の指示</th><td><textarea class="large-text" rows="5" name="<?php echo esc_attr( $f( 'extra_prompt' ) ); ?>"><?php echo esc_textarea( $o['extra_prompt'] ); ?></textarea>
					<p class="description">対応エリア、得意な工事、料金の考え方など、AIに伝えておきたいことを書きます。</p></td></tr>
				<tr><th>言い換え(検索用)</th><td><textarea class="large-text" rows="7" name="<?php echo esc_attr( $f( 'synonyms' ) ); ?>"><?php echo esc_textarea( $o['synonyms'] ); ?></textarea>
					<p class="description">同じ意味の言葉を、1行にカンマ区切りで書きます。どれか1つで検索すると、ほかの言葉の記事も見つかります(例: バルコニー,ベランダ)。</p></td></tr>
				<tr><th>1時間あたりの上限(1人)</th><td><input type="number" min="1" name="<?php echo esc_attr( $f( 'rate_limit' ) ); ?>" value="<?php echo esc_attr( $o['rate_limit'] ); ?>"> 回<p class="description">API利用料の暴走を防ぎます。</p></td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php kaia_render_search_test(); ?>
	</div>
	<?php
}

/** 設定画面の「検索テスト」: 何が、なぜ出る/出ないかを確認する。 */
function kaia_render_search_test() {
	$q = isset( $_GET['kaia_test'] ) ? sanitize_text_field( wp_unslash( $_GET['kaia_test'] ) ) : '';
	?>
	<hr>
	<h2>検索テスト</h2>
	<p>お客様が入力しそうな言葉や文章を入れて、どんな事例・記事が出るかを確認できます。(上の設定を保存してから試してください)</p>
	<form method="get" action="">
		<input type="hidden" name="page" value="kaia">
		<input type="text" class="regular-text" name="kaia_test" value="<?php echo esc_attr( $q ); ?>" placeholder="例: バルコニー / 2階のバルコニーから雨漏りしている">
		<?php submit_button( 'テスト', 'secondary', '', false ); ?>
	</form>
	<?php
	if ( '' === $q ) {
		return;
	}
	$terms = kaia_build_terms( $q );
	echo '<p><strong>使った検索語:</strong> ';
	foreach ( $terms as $t => $w ) {
		echo esc_html( $t ) . ( 2 === $w ? '' : '(言い換え)' ) . ' / ';
	}
	echo '</p>';

	$list = function ( $title, $rows ) {
		echo '<h3>' . esc_html( $title ) . '</h3>';
		if ( ! $rows ) {
			echo '<p>該当なし</p>';
			return;
		}
		echo '<ol>';
		foreach ( $rows as $r ) {
			echo '<li><a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $r['title'] ) . '</a> <small>(点数 ' . (int) $r['score'] . ')</small></li>';
		}
		echo '</ol>';
	};
	$list( '施工事例として出るもの', kaia_search_cases( $q ) );
	$list( '参考記事として出るもの', kaia_search_articles( $q ) );

	// カテゴリーで絞らない場合(なぜ出ないかの手がかり)
	$scope = array_merge( kaia_scope_ids( kaia_case_cats() ), kaia_scope_ids( (array) kaia_get( 'article_cats' ) ) );
	echo '<h3>参考: カテゴリーで絞らずに探した場合(上位10件)</h3>';
	$all = kaia_search_posts( $q, array(), 'case', 10, true );
	if ( ! $all ) {
		echo '<p>サイト全体でも見つかりません。記事のタイトル・本文・タグに、この言葉(や言い換え)が含まれていない可能性があります。「言い換え」に追加してみてください。</p>';
		return;
	}
	echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>記事</th><th>カテゴリー</th><th>紹介の対象</th></tr></thead><tbody>';
	foreach ( $all as $r ) {
		$cids  = wp_get_post_categories( $r['id'] );
		$names = wp_list_pluck( wp_get_post_categories( $r['id'], array( 'fields' => 'all' ) ), 'name' );
		$in    = (bool) array_intersect( $cids, $scope );
		echo '<tr><td><a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $r['title'] ) . '</a></td><td>' . esc_html( implode( '、', $names ) ) . '</td><td>' . ( $in ? '○ 対象' : '× 対象外のカテゴリー' ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p class="description">「× 対象外」に見たい事例が並んでいる場合は、上の「施工事例のカテゴリー」「参考記事のカテゴリー」にそのカテゴリーを追加してください。</p>';
}
