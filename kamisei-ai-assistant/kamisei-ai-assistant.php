<?php
/**
 * Plugin Name: Kamisei AI Assistant
 * Description: 施工事例をもとにお客様の要望をヒアリングし、近い事例の提案と担当者への引き継ぎを行うAIチャット。
 * Version: 0.3.7
 * Requires PHP: 7.4
 * Text Domain: kamisei-ai-assistant
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KAIA_VERSION', '0.3.7' );
define( 'KAIA_DIR', plugin_dir_path( __FILE__ ) );
define( 'KAIA_URL', plugin_dir_url( __FILE__ ) );
define( 'KAIA_OPTION', 'kaia_settings' );

require_once KAIA_DIR . 'includes/settings.php';
require_once KAIA_DIR . 'includes/cases.php';
require_once KAIA_DIR . 'includes/leads.php';
require_once KAIA_DIR . 'includes/claude.php';
require_once KAIA_DIR . 'includes/flow.php';
require_once KAIA_DIR . 'includes/rest.php';
require_once KAIA_DIR . 'includes/widget.php';
