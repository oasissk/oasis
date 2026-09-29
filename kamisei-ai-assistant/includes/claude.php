<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function kaia_api_key() {
	return defined( 'KAIA_API_KEY' ) ? KAIA_API_KEY : kaia_get( 'api_key' );
}

function kaia_system_prompt() {
	$o = kaia_get();
	$p = "あなたは{$o['company_name']}(屋根・雨漏り・外壁・防水などの工事を行う会社)のWebサイト上の相談アシスタントです。\n"
		. "役割: お客様の要望を丁寧にヒアリングし、近い施工事例を紹介し、必要なら担当者へつなぐこと。\n\n"
		. "進め方:\n"
		. "1. 次の順で、必ず1回に1問だけ聞く: ①お困りごと(雨漏り・台風被害・屋根や外壁の劣化など)②具体的な症状といつからか ③屋根の種類(瓦・スレート・金属)④築年数 ⑤ご希望の時期。答えやすいよう、毎回2〜4個の選択肢の例を添える。今まさに雨漏りしている場合は、屋根に登らないよう伝え、お急ぎなら電話をすすめる。\n"
		. "2. 症状と築年数が分かったら search_cases ツールで近い施工事例を探し、紹介する。事例は画面にカード表示されるので、本文でURLを繰り返さなくてよい。\n"
		. "   補足として、お悩みに関する解説記事があれば search_articles ツールで探し、「こちらの記事も参考になります」と1〜2件添える(事例の代わりにはしない)。\n"
		. "3. お客様が相談・現地調査を希望したら、お名前と連絡先(電話かメール)を尋ね、個人情報の取り扱いに同意を得たうえで request_handoff ツールを呼ぶ。\n\n"
		. "守ること:\n"
		. "- 返答は短く、やさしい日本語で。\n"
		. "- 金額は断定しない。事例に書かれた金額があっても「あくまで目安で、現地調査後に確定します」と添える。\n"
		. "- 事例や会社情報にないこと(対応エリア、工期、保証など)は推測せず、担当者に確認する旨を伝える。\n"
		. "- 工事と無関係な話題には答えない。\n";
	if ( $o['phone'] ) {
		$p .= "- お急ぎの場合の電話番号: {$o['phone']}\n";
	}
	if ( $o['reservation_url'] ) {
		$p .= "- 現地調査の予約ページ: {$o['reservation_url']}\n";
	}
	if ( $o['extra_prompt'] ) {
		$p .= "\n会社からの追加情報:\n{$o['extra_prompt']}\n";
	}
	return $p;
}

function kaia_tools() {
	return array(
		array(
			'name'         => 'search_cases',
			'description'  => '施工事例をキーワードで検索する。場所や工事内容(例: 雨漏り 瓦屋根 修理)を渡す。',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array( 'keywords' => array( 'type' => 'string', 'description' => '空白区切りのキーワード' ) ),
				'required'   => array( 'keywords' ),
			),
		),
		array(
			'name'         => 'search_articles',
			'description'  => 'お悩みや工事に関する解説記事(お客様の声、リフォーム解説など)をキーワードで検索する。施工事例ではない。',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array( 'keywords' => array( 'type' => 'string', 'description' => '空白区切りのキーワード' ) ),
				'required'   => array( 'keywords' ),
			),
		),
		array(
			'name'         => 'request_handoff',
			'description'  => 'お客様の同意を得たうえで、担当者に相談内容を引き継ぐ。名前・連絡先・同意が揃うまで呼ばない。',
			'input_schema' => array(
				'type'       => 'object',
				'properties' => array(
					'name'    => array( 'type' => 'string' ),
					'contact' => array( 'type' => 'string', 'description' => '電話番号またはメールアドレス' ),
					'summary' => array( 'type' => 'string', 'description' => '要望のまとめ(場所・築年数・困りごと・予算感など)' ),
					'consent' => array( 'type' => 'boolean', 'description' => '個人情報の取り扱いに同意したか' ),
				),
				'required'   => array( 'name', 'contact', 'summary', 'consent' ),
			),
		),
	);
}

/**
 * 会話を1ターン進める。ツール呼び出しはサーバー側で処理する。
 *
 * @return array|WP_Error { reply: string, cases: array, handoff: bool }
 */
function kaia_chat( array $messages ) {
	$key = kaia_api_key();
	if ( ! $key ) {
		return new WP_Error( 'kaia_no_key', 'APIキーが未設定です。' );
	}

	$cases   = array();
	$handoff = false;
	$said    = array();

	for ( $i = 0; $i < 4; $i++ ) {
		$res = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 45,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => kaia_get( 'model' ),
				'max_tokens' => 1024,
				'system'     => kaia_system_prompt(),
				'tools'      => kaia_tools(),
				'messages'   => $messages,
			) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== wp_remote_retrieve_response_code( $res ) || empty( $data['content'] ) ) {
			return new WP_Error( 'kaia_api', 'AIとの通信に失敗しました。' );
		}

		$text = '';
		$uses = array();
		foreach ( $data['content'] as $block ) {
			if ( 'text' === $block['type'] ) {
				$text .= $block['text'];
			} elseif ( 'tool_use' === $block['type'] ) {
				$uses[] = $block;
			}
		}

		if ( '' !== trim( $text ) ) {
			$said[] = trim( $text );
		}
		if ( 'tool_use' !== ( $data['stop_reason'] ?? '' ) || ! $uses ) {
			return array( 'reply' => implode( "\n", $said ), 'cases' => kaia_rank_results( array_values( $cases ) ), 'handoff' => $handoff );
		}

		$messages[] = array( 'role' => 'assistant', 'content' => $data['content'] );
		$results    = array();
		foreach ( $uses as $use ) {
			$in = $use['input'] ?? array();
			if ( in_array( $use['name'], array( 'search_cases', 'search_articles' ), true ) ) {
				$found = kaia_rank_results( 'search_cases' === $use['name'] ? kaia_search_cases( $in['keywords'] ?? '', 5 ) : kaia_search_articles( $in['keywords'] ?? '', 5 ), 3 );
				foreach ( $found as $c ) {
					$cases[ $c['id'] ] = $c;
				}
				$out = $found ? wp_json_encode( array_map( function ( $c ) {
					return array( 'title' => $c['title'], 'summary' => $c['summary'] );
				}, $found ), JSON_UNESCAPED_UNICODE ) : '該当する事例は見つかりませんでした。';
			} elseif ( 'request_handoff' === $use['name'] ) {
				if ( empty( $in['consent'] ) || empty( $in['name'] ) || empty( $in['contact'] ) ) {
					$out = '同意・お名前・連絡先が揃っていないため送信しませんでした。';
				} else {
					kaia_save_lead(
						sanitize_text_field( $in['name'] ),
						sanitize_text_field( $in['contact'] ),
						sanitize_textarea_field( $in['summary'] ?? '' ),
						kaia_transcript( $messages )
					);
					$handoff = true;
					$out     = '担当者に引き継ぎました。';
				}
			} else {
				$out = '不明なツールです。';
			}
			$results[] = array( 'type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $out );
		}
		$messages[] = array( 'role' => 'user', 'content' => $results );
	}

	return new WP_Error( 'kaia_loop', '応答を生成できませんでした。' );
}

function kaia_transcript( array $messages ) {
	$lines = array();
	foreach ( $messages as $m ) {
		if ( is_string( $m['content'] ) ) {
			$lines[] = ( 'user' === $m['role'] ? 'お客様: ' : 'AI: ' ) . $m['content'];
		}
	}
	return implode( "\n", $lines );
}
