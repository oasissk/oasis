<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ヒアリングの質問フロー(検索のみモード)。
 * kw: 事例・記事の検索に使う言葉(タグ名に合わせる) / next: 次の質問ID('free'で自由記入) / urgent: 緊急扱い
 * フィルター 'kaia_flow' で差し替え可能。
 */
function kaia_flow() {
	$house = array( 'key' => '築年数', 'q' => '建物の築年数はどのくらいですか?', 'opts' => array(
		array( 'label' => '10年未満', 'next' => 'timing' ),
		array( 'label' => '10〜20年', 'next' => 'timing' ),
		array( 'label' => '20〜30年', 'next' => 'timing' ),
		array( 'label' => '30年以上', 'next' => 'timing' ),
		array( 'label' => 'わからない', 'next' => 'timing' ),
	) );

	$flow = array(
		'start'   => array( 'key' => 'お困りごと', 'q' => 'どんなことでお困りですか?', 'opts' => array(
			array( 'label' => '雨漏りしている', 'kw' => array( '雨漏り' ), 'next' => 'leak' ),
			array( 'label' => '台風・地震のあとが心配', 'kw' => array( '台風' ), 'next' => 'damage' ),
			array( 'label' => '屋根の修理・葺き替えを考えている', 'kw' => array( '屋根修理', '屋根工事' ), 'next' => 'roof' ),
			array( 'label' => '外壁・防水が気になる', 'kw' => array( '外壁工事', '防水工事' ), 'next' => 'wall' ),
			array( 'label' => '部屋を明るくしたい', 'kw' => array( 'スカイライトチューブ' ), 'next' => 'house' ),
			array( 'label' => 'よくわからない', 'kw' => array( '屋根修理' ), 'next' => 'house' ),
		) ),
		'leak'    => array( 'key' => '雨漏りの状況', 'q' => '雨漏りの状況を教えてください。', 'opts' => array(
			array( 'label' => '今まさに漏れている', 'urgent' => true, 'kw' => array( '雨漏り修理' ), 'next' => 'house' ),
			array( 'label' => '雨の日だけ漏れる', 'kw' => array( '雨漏り調査' ), 'next' => 'house' ),
			array( 'label' => '天井や壁にシミがある', 'kw' => array( '雨漏り調査' ), 'next' => 'house' ),
			array( 'label' => '修理したがまた漏れた', 'kw' => array( '雨漏り修理' ), 'next' => 'house' ),
		) ),
		'damage'  => array( 'key' => '被害の内容', 'q' => 'どんな被害が気になりますか?', 'opts' => array(
			array( 'label' => '瓦がずれた・割れた', 'kw' => array( '瓦屋根', '屋根部分修理' ), 'next' => 'house' ),
			array( 'label' => '屋根材が飛んだ・めくれた', 'kw' => array( '屋根部分修理' ), 'next' => 'house' ),
			array( 'label' => '雨どい・板金が外れた', 'kw' => array( '屋根修理' ), 'next' => 'house' ),
			array( 'label' => '見た目は変わらないが不安', 'kw' => array( '屋根修理' ), 'next' => 'house' ),
		) ),
		'roof'    => array( 'key' => '今の屋根', 'q' => '今の屋根の種類はわかりますか?', 'opts' => array(
			array( 'label' => '瓦屋根', 'kw' => array( '瓦屋根' ), 'next' => 'house' ),
			array( 'label' => 'スレート(カラーベスト)', 'kw' => array( 'スレート屋根' ), 'next' => 'house' ),
			array( 'label' => '金属屋根', 'kw' => array( '屋根工事' ), 'next' => 'house' ),
			array( 'label' => 'わからない', 'next' => 'house' ),
		) ),
		'wall'    => array( 'key' => '外壁・防水', 'q' => 'どのような状態ですか?', 'opts' => array(
			array( 'label' => '外壁にひび割れがある', 'kw' => array( '外壁工事' ), 'next' => 'house' ),
			array( 'label' => '塗装がはがれている', 'kw' => array( '外壁工事' ), 'next' => 'house' ),
			array( 'label' => 'ベランダ・屋上の防水', 'kw' => array( '防水工事' ), 'next' => 'house' ),
		) ),
		'house'   => $house,
		'timing'  => array( 'key' => 'ご希望', 'q' => 'ご希望の時期は?', 'opts' => array(
			array( 'label' => 'できるだけ早く', 'next' => 'free' ),
			array( 'label' => '1〜3か月以内', 'next' => 'free' ),
			array( 'label' => 'まずは情報収集・見積りだけ', 'next' => 'free' ),
		) ),
	);
	return apply_filters( 'kaia_flow', $flow );
}
