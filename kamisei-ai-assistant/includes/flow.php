<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ヒアリングの質問フロー(検索のみモード)。
 * kw: 事例・記事の検索に使う言葉(タグ名に合わせる) / next: 次の質問ID('free'で自由記入) / urgent: 緊急扱い
 * code: 計測用の分類コード(表示には使わない)。文言を変えても同じ意味ならコードは変えない。
 * フィルター 'kaia_flow' で差し替え可能。
 */
function kaia_flow() {
	$house = array( 'code' => 'house', 'key' => '築年数', 'q' => '建物の築年数はどのくらいですか?', 'opts' => array(
		array( 'code' => 'lt10', 'label' => '10年未満', 'next' => 'timing' ),
		array( 'code' => 'y10_20', 'label' => '10〜20年', 'next' => 'timing' ),
		array( 'code' => 'y20_30', 'label' => '20〜30年', 'next' => 'timing' ),
		array( 'code' => 'gt30', 'label' => '30年以上', 'next' => 'timing' ),
		array( 'code' => 'unknown', 'label' => 'わからない', 'next' => 'timing' ),
	) );

	$flow = array(
		'start'   => array( 'code' => 'q1', 'key' => 'お困りごと', 'q' => 'どんなことでお困りですか?', 'opts' => array(
			array( 'code' => 'leak', 'label' => '雨漏りしている', 'kw' => array( '雨漏り' ), 'next' => 'leak' ),
			array( 'code' => 'skylight_leak', 'label' => '天窓から雨漏りしている', 'kw' => array( '天窓', '雨漏り修理' ), 'next' => 'skylight' ),
			array( 'code' => 'damage', 'label' => '台風・地震のあとが心配', 'kw' => array( '台風' ), 'next' => 'damage' ),
			array( 'code' => 'roof', 'label' => '屋根の修理・葺き替えを考えている', 'kw' => array( '屋根修理', '屋根工事' ), 'next' => 'roof' ),
			array( 'code' => 'wall', 'label' => '外壁・防水が気になる', 'kw' => array( '外壁工事', '防水工事' ), 'next' => 'wall' ),
			array( 'code' => 'gutter', 'label' => '雨樋(あまどい)が気になる', 'kw' => array( '雨樋' ), 'next' => 'gutter' ),
			array( 'code' => 'unknown', 'label' => 'よくわからない', 'kw' => array( '屋根修理' ), 'next' => 'house' ),
		) ),
		'leak'    => array( 'code' => 'leak', 'key' => '雨漏りの状況', 'q' => '雨漏りの状況を教えてください。', 'opts' => array(
			array( 'code' => 'now', 'label' => '今まさに漏れている', 'urgent' => true, 'kw' => array( '雨漏り修理' ), 'next' => 'house' ),
			array( 'code' => 'rain_only', 'label' => '雨の日だけ漏れる', 'kw' => array( '雨漏り調査' ), 'next' => 'house' ),
			array( 'code' => 'stain', 'label' => '天井や壁にシミがある', 'kw' => array( '雨漏り調査' ), 'next' => 'house' ),
			array( 'code' => 'recur', 'label' => '修理したがまた漏れた', 'kw' => array( '雨漏り修理' ), 'next' => 'house' ),
		) ),
		'damage'  => array( 'code' => 'damage', 'key' => '被害の内容', 'q' => 'どんな被害が気になりますか?', 'opts' => array(
			array( 'code' => 'tile', 'label' => '瓦がずれた・割れた', 'kw' => array( '瓦屋根', '屋根部分修理' ), 'next' => 'house' ),
			array( 'code' => 'blown', 'label' => '屋根材が飛んだ・めくれた', 'kw' => array( '屋根部分修理' ), 'next' => 'house' ),
			array( 'code' => 'gutter_metal', 'label' => '雨樋・板金が外れた', 'kw' => array( '雨樋', '屋根部分修理' ), 'next' => 'house' ),
			array( 'code' => 'worry', 'label' => '見た目は変わらないが不安', 'kw' => array( '屋根修理' ), 'next' => 'house' ),
		) ),
		'skylight' => array( 'code' => 'skylight', 'key' => '天窓の状況', 'q' => '天窓のどのあたりから漏れていますか?', 'opts' => array(
			array( 'code' => 'now', 'label' => '今まさに漏れている', 'urgent' => true, 'kw' => array( '天窓' ), 'next' => 'house' ),
			array( 'code' => 'stain', 'label' => '天窓のまわりの天井にシミがある', 'kw' => array( '天窓' ), 'next' => 'house' ),
			array( 'code' => 'seal', 'label' => 'ガラスのまわり・パッキンが傷んでいる', 'kw' => array( '天窓' ), 'next' => 'house' ),
			array( 'code' => 'replace', 'label' => '天窓の交換・撤去も考えている', 'kw' => array( '天窓' ), 'next' => 'house' ),
			array( 'code' => 'unknown', 'label' => 'よくわからない', 'kw' => array( '天窓' ), 'next' => 'house' ),
		) ),
		'gutter'  => array( 'code' => 'gutter', 'key' => '雨樋の状況', 'q' => '雨樋はどのような状態ですか?', 'opts' => array(
			array( 'code' => 'off', 'label' => '外れた・傾いている', 'kw' => array( '雨樋' ), 'next' => 'house' ),
			array( 'code' => 'crack', 'label' => '割れている・穴があいている', 'kw' => array( '雨樋' ), 'next' => 'house' ),
			array( 'code' => 'overflow', 'label' => '雨の日にあふれる・詰まっている', 'kw' => array( '雨樋' ), 'next' => 'house' ),
			array( 'code' => 'storm', 'label' => '台風・雪で壊れた', 'kw' => array( '雨樋', '台風' ), 'next' => 'house' ),
			array( 'code' => 'old', 'label' => '古くなったので交換したい', 'kw' => array( '雨樋' ), 'next' => 'house' ),
		) ),
		'roof'    => array( 'code' => 'roof', 'key' => '今の屋根', 'q' => '今の屋根の種類はわかりますか?', 'opts' => array(
			array( 'code' => 'tile', 'label' => '瓦屋根', 'kw' => array( '瓦屋根' ), 'next' => 'house' ),
			array( 'code' => 'slate', 'label' => 'スレート(カラーベスト)', 'kw' => array( 'スレート屋根' ), 'next' => 'house' ),
			array( 'code' => 'metal', 'label' => '金属屋根', 'kw' => array( '屋根工事' ), 'next' => 'house' ),
			array( 'code' => 'unknown', 'label' => 'わからない', 'next' => 'house' ),
		) ),
		'wall'    => array( 'code' => 'wall', 'key' => '外壁・防水', 'q' => 'どのような状態ですか?', 'opts' => array(
			array( 'code' => 'crack', 'label' => '外壁にひび割れがある', 'kw' => array( '外壁工事' ), 'next' => 'house' ),
			array( 'code' => 'peel', 'label' => '塗装がはがれている', 'kw' => array( '外壁工事' ), 'next' => 'house' ),
			array( 'code' => 'waterproof', 'label' => 'ベランダ・屋上の防水', 'kw' => array( '防水工事' ), 'next' => 'house' ),
		) ),
		'house'   => $house,
		'timing'  => array( 'code' => 'timing', 'key' => 'ご希望', 'q' => 'ご希望の時期は?', 'opts' => array(
			array( 'code' => 'asap', 'label' => 'できるだけ早く', 'next' => 'free' ),
			array( 'code' => 'm1_3', 'label' => '1〜3か月以内', 'next' => 'free' ),
			array( 'code' => 'info', 'label' => 'まずは情報収集・見積りだけ', 'next' => 'free' ),
		) ),
	);
	return apply_filters( 'kaia_flow', $flow );
}
