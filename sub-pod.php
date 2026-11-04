<?php
/**
 * Plugin Name: Sub Pod
 * Plugin URI: https://github.com/villyoshioka/SubPod
 * Description: WordPressサイト全体をバックアップ・復元するプラグイン
 * Version: 1.0.1
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.5.12
 * Author: Vill Yoshioka
 * Author URI: https://github.com/villyoshioka
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: sub-pod
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SP_VERSION', '1.0.1' );
define( 'SP_PLUGIN_FILE', __FILE__ );
define( 'SP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SP_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * メインプラグインクラス
 */
class Sub_Pod {
	private static ?self $instance = null;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// 復元中は自分自身のファイルも入れ替わるので、途中で読み込まなくて済むよう最初にすべて読む
		require_once SP_PLUGIN_DIR . 'includes/class-settings.php';
		require_once SP_PLUGIN_DIR . 'includes/class-progress.php';
		require_once SP_PLUGIN_DIR . 'includes/class-tar.php';
		require_once SP_PLUGIN_DIR . 'includes/class-crypto.php';
		require_once SP_PLUGIN_DIR . 'includes/class-database.php';
		require_once SP_PLUGIN_DIR . 'includes/class-r2.php';
		require_once SP_PLUGIN_DIR . 'includes/class-backup.php';
		require_once SP_PLUGIN_DIR . 'includes/class-restore.php';
		require_once SP_PLUGIN_DIR . 'includes/class-updater.php';
		require_once SP_PLUGIN_DIR . 'includes/class-admin.php';

		register_activation_hook( SP_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( SP_PLUGIN_FILE, [ $this, 'deactivate' ] );
		add_action( 'plugins_loaded', [ $this, 'init' ] );
	}

	public function init(): void {
		// サイト全体を置き換える作りなので、サイトネットワークでは動かさない
		if ( is_multisite() ) {
			return;
		}

		SP_Updater::get_instance();
		SP_Backup::register_hooks();

		if ( is_admin() ) {
			SP_Admin::get_instance();
		}
	}

	public function activate(): void {
		if ( is_multisite() ) {
			wp_die( 'Sub Pod はマルチサイトには対応していません。' );
		}
		update_option( 'sp_version', SP_VERSION, false );
	}

	public function deactivate(): void {
		wp_clear_scheduled_hook( SP_Backup::CLEANUP_HOOK );
		SP_Backup::delete_pending_download();
		SP_Backup::sweep( true );
		delete_transient( 'sp_beta_channel' );
		delete_transient( 'sp_github_release_cache' );
		delete_transient( 'sp_github_release_cache_beta' );
	}
}

Sub_Pod::get_instance();
