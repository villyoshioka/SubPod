<?php
/**
 * プラグインアンインストール時の処理
 *
 * 保存先に置いたバックアップは利用者のデータなので消さない。作業用フォルダだけ片付ける。
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-settings.php';

// 完了しなかった復元の一時ファイルは元のサイトの唯一のコピーかもしれないので残す
$has_leftover = null !== SP_Settings::leftover_path();
if ( $has_leftover ) {
	foreach ( array( 'upload', 'backup', 'download' ) as $name ) {
		SP_Settings::remove_work( $name );
	}
} else {
	SP_Settings::remove_install_guard();
	SP_Settings::remove_work();
}
wp_clear_scheduled_hook( 'sp_cleanup_download' );

delete_option( 'sp_settings' );
delete_option( 'sp_version' );
delete_option( 'sp_pending_download' );
delete_option( 'sp_download_offered' );
delete_option( 'sp_download_owner' );
delete_option( 'sp_restore_notice' );
delete_option( 'sp_last_error' );
delete_option( 'sp_skipped_links' );
delete_option( 'sp_last_log' );
// 再インストール後も一時ファイルを見つけられるよう、残っている間はフォルダ名を消さない
if ( ! $has_leftover ) {
	delete_option( 'sp_work_fallback' );
}
delete_transient( 'sp_beta_channel' );
delete_transient( 'sp_github_release_cache' );
delete_transient( 'sp_github_release_cache_beta' );
wp_cache_flush();
