<?php
/**
 * バックアップの作成と保存先への配置
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Backup {
	public const string CLEANUP_HOOK = 'sp_cleanup_download';
	public const string MANIFEST = 'sub-pod-manifest.json';
	public const string DATABASE = 'database.sql';
	public const string CONFIG = 'wp-config.php';
	public const int FORMAT = 1;
	public const string RESTORE_NOTICE_OPTION = 'sp_restore_notice';

	private const string PENDING_OPTION = 'sp_pending_download';
	private const string OFFERED_OPTION = 'sp_download_offered';
	private const string OWNER_OPTION = 'sp_download_owner';
	private const string ERROR_OPTION = 'sp_last_error';
	private const string SKIPPED_OPTION = 'sp_skipped_links';
	private const string LOG_OPTION = 'sp_last_log';
	private const int MAX_SKIPPED_NAMES = 100;
	private const int LOCK_TRIES = 5;

	/** @var resource|null */
	private static $lock = null;

	private int $file_count = 0;
	private int $skipped_links = 0;

	/** @var string[] */
	private array $skipped_names = array();

	/** @var array<string, true> 書庫内の名前。別の形で入れるので、そのままは入れないファイル */
	private array $skip_names = array();

	public static function register_hooks(): void {
		add_filter( 'cp_before_static_generation', array( self::class, 'on_static_generation' ), 10, 2 );
		add_action( self::CLEANUP_HOOK, array( self::class, 'cleanup' ) );
	}

	/**
	 * Carry Pod の静的化の直前に呼ばれる
	 *
	 * Carry Pod は true 以外が返ると静的化を止める。失敗したときは理由が分かるよう WP_Error を返す。
	 * 手動の静的化では $context に 'manual' が渡る。
	 * 暗号化パスワードがないときは、バックアップだけ飛ばして静的化は続ける(管理画面の通知で伝える)。
	 */
	public static function on_static_generation( mixed $proceed, mixed $context = 'auto' ): mixed {
		if ( true !== $proceed || ! SP_Settings::get_settings()['cp_backup'] ) {
			return $proceed;
		}

		$destinations = SP_Settings::destinations( 'manual' !== $context );
		if ( ! $destinations || null === SP_Settings::password() ) {
			return $proceed;
		}

		// ログを取得できるのは Sub Pod の画面から実行したバックアップだけ
		self::clear_log();

		try {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$result = ( new self() )->run( $destinations );
			delete_option( self::ERROR_OPTION );
			// Carry Pod がログに残す
			do_action( 'sp_backup_completed', $result );
			return $proceed;
		} catch ( Throwable $e ) {
			$message = 'バックアップに失敗したため、静的化を中止しました: ' . $e->getMessage();
			self::set_last_error( $message );
			return new WP_Error( 'sp_backup_failed', $message );
		}
	}

	/**
	 * 前回のバックアップに含められなかったリンク
	 *
	 * @return array{count: int, names: string[], objects?: string[]}|null
	 */
	public static function skipped_links(): ?array {
		$skipped = get_option( self::SKIPPED_OPTION );
		return is_array( $skipped ) ? $skipped : null;
	}

	public static function last_log(): string {
		return (string) get_option( self::LOG_OPTION, '' );
	}

	public static function save_log( string $log ): void {
		update_option( self::LOG_OPTION, $log, false );
	}

	public static function clear_log(): void {
		delete_option( self::LOG_OPTION );
	}

	public static function log_url(): string {
		return add_query_arg(
			array(
				'action'   => 'sp_download_log',
				'_wpnonce' => wp_create_nonce( 'sp_download_log' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	public static function restore_notice(): string {
		return (string) get_option( self::RESTORE_NOTICE_OPTION, '' );
	}

	public static function clear_restore_notice(): void {
		delete_option( self::RESTORE_NOTICE_OPTION );
	}

	public static function last_error(): string {
		return (string) get_option( self::ERROR_OPTION, '' );
	}

	public static function set_last_error( string $message ): void {
		update_option( self::ERROR_OPTION, $message, false );
	}

	public static function clear_last_error(): void {
		delete_option( self::ERROR_OPTION );
	}

	/**
	 * 同時実行を防ぐ
	 *
	 * ファイルロックなので、処理が途中で落ちても OS が解放する。
	 */
	public static function acquire_lock(): void {
		if ( self::$lock ) {
			return;
		}
		// 実行中かどうかの確認や片付けと一瞬ぶつかっただけで諦めないよう、少しだけ待つ
		for ( $try = 0; $try < self::LOCK_TRIES; $try++ ) {
			if ( $try > 0 ) {
				usleep( 200000 );
			}
			$path   = SP_Settings::work_dir() . '/lock';
			$handle = @fopen( $path, 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $handle ) {
				continue;
			}
			if ( flock( $handle, LOCK_EX | LOCK_NB ) ) {
				clearstatcache( true, $path );
				$stat = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				// 解放時に消されたファイルを掴んでいたらロックになっていないので、開き直す
				if ( $stat && fstat( $handle )['ino'] === $stat['ino'] ) {
					self::$lock = $handle;
					return;
				}
			}
			fclose( $handle );
		}
		throw new RuntimeException( '別のバックアップまたは復元が実行中です。' );
	}

	public static function release_lock(): void {
		if ( self::$lock ) {
			SP_Settings::remove_work_root_if_empty();
			flock( self::$lock, LOCK_UN );
			fclose( self::$lock );
			self::$lock = null;
		}
	}

	public static function is_running(): bool {
		$path = SP_Settings::work_root() . '/lock';
		if ( ! SP_Settings::is_work_root_safe() || ! is_file( $path ) ) {
			return false;
		}
		$handle = @fopen( $path, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $handle ) {
			return false;
		}
		// 確認するだけなので共有ロックにする。排他だと、同時に始まった処理を「実行中」で止めてしまう
		$free = flock( $handle, LOCK_SH | LOCK_NB );
		fclose( $handle );
		return ! $free;
	}

	/**
	 * バックアップを作って保存先へ置く
	 *
	 * @param string[]      $destinations download / directory / r2
	 * @param callable|null $progress     fn( string $message, int $percent ): void
	 * @param callable|null $tick         長い処理の途中で定期的に呼ぶ
	 * @return array{name: string, size: int, file_count: int, skipped_links: int, skipped_names: string[], skipped_objects: string[]}
	 */
	public function run( array $destinations, ?callable $progress = null, ?callable $tick = null ): array {
		$settings = SP_Settings::get_settings();

		$stages = array(
			'database' => SP_Database::is_sqlite() ? 0 : 10,
			'archive'  => 60,
			'verify'   => 10,
			'encrypt'  => 5,
		);
		if ( in_array( 'directory', $destinations, true ) ) {
			$stages['directory'] = 5;
		}
		if ( in_array( 'r2', $destinations, true ) ) {
			$stages['r2'] = 30;
		}
		$tracker = new SP_Progress( $progress, $tick, $stages );
		$tick    = array( $tracker, 'tick' );

		$password = SP_Settings::password();
		if ( null === $password ) {
			throw new RuntimeException( '暗号化パスワードが設定されていないか、読み取れません。設定画面で入力してください。' );
		}
		$r2_secret = null;
		if ( in_array( 'r2', $destinations, true ) ) {
			$r2_secret = SP_Settings::decrypt_secret( $settings['r2_secret_key'] );
			if ( null === $r2_secret ) {
				throw new RuntimeException( 'R2 の設定を読み取れませんでした。設定画面でもう一度入力してください。' );
			}
		}

		self::acquire_lock();
		$job = SP_Settings::work_root() . '/backup';
		try {
			// 強制終了されても、暗号化前のファイルを残し続けない
			self::schedule_cleanup();
			SP_Settings::remove_work( 'backup' );
			$job = SP_Settings::work_dir( 'backup' );

			$archive = $job . '/' . self::file_name();
			$this->create_archive( $archive, $tracker );

			// どの保存先にも暗号化したものだけを置く。暗号化前のファイルはすぐ消す
			$tracker->begin( 'encrypt', '暗号化しています', (int) filesize( $archive ) );
			$encrypted = $archive . '.enc';
			SP_Crypto::encrypt_file( $archive, $encrypted, $password, $tick );
			unlink( $archive );
			$name = basename( $encrypted );
			$size = (int) filesize( $encrypted );

			if ( in_array( 'directory', $destinations, true ) ) {
				$tracker->begin( 'directory', '保存先ディレクトリへコピーしています', $size );
				$this->copy_to_directory( $encrypted, $settings['directory'], $name, $tick );
			}

			if ( in_array( 'r2', $destinations, true ) ) {
				$tracker->begin( 'r2', 'R2 へ送信しています', $size );
				$r2 = new SP_R2( $settings['r2_account_id'], $settings['r2_bucket'], $settings['r2_access_key'], $r2_secret );
				$r2->upload( $encrypted, $name, $tick );
			}

			if ( in_array( 'download', $destinations, true ) ) {
				$this->prepare_download( $encrypted, $name );
			}

			$objects = SP_Database::is_sqlite() ? array() : SP_Database::unsupported_objects();
			if ( $this->skipped_links > 0 || $objects ) {
				update_option(
					self::SKIPPED_OPTION,
					array(
						'count'   => $this->skipped_links,
						'names'   => $this->skipped_names,
						'objects' => $objects,
					),
					false
				);
			} else {
				delete_option( self::SKIPPED_OPTION );
			}

			return array(
				'name'            => $name,
				'size'            => $size,
				'file_count'      => $this->file_count,
				'skipped_links'   => $this->skipped_links,
				'skipped_names'   => $this->skipped_names,
				'skipped_objects' => $objects,
			);
		} finally {
			SP_Settings::remove_directory( $job );
			self::release_lock();
		}
	}

	private function create_archive( string $archive, SP_Progress $tracker ): void {
		global $wpdb;

		$tick = array( $tracker, 'tick' );

		$job         = dirname( $archive );
		$tables      = array();
		$sqlite      = SP_Database::is_sqlite();
		$sqlite_name = '';
		if ( ! $sqlite ) {
			$tracker->begin( 'database', 'データベースを書き出しています', count( SP_Database::tables() ) );
			$tables = SP_Database::dump( $job . '/' . self::DATABASE, $tick );
		}

		$content_outside = ! self::is_inside( WP_CONTENT_DIR, ABSPATH );

		$total = SP_Restore::tree_size( untrailingslashit( ABSPATH ) ) + ( $sqlite ? 0 : (int) filesize( $job . '/' . self::DATABASE ) );
		if ( $content_outside ) {
			$total += SP_Restore::tree_size( untrailingslashit( WP_CONTENT_DIR ) );
		}
		$tracker->begin( 'archive', 'ファイルを圧縮しています', $total );
		$writer = new SP_Tar_Writer( $archive, $tick );

		if ( ! $sqlite ) {
			$writer->add_file( self::DATABASE, $job . '/' . self::DATABASE );
			unlink( $job . '/' . self::DATABASE );
		}

		$config = SP_Settings::config_path();
		if ( null === $config || ! $writer->add_file( self::CONFIG, $config ) ) {
			throw new RuntimeException( 'wp-config.php を読み取れませんでした。' );
		}

		if ( $sqlite ) {
			$sqlite_name = $this->add_sqlite_snapshot( $writer, $job, $tick );
		}

		$this->add_tree( $writer, untrailingslashit( ABSPATH ), 'files', $tick );
		if ( $content_outside ) {
			$this->add_tree( $writer, untrailingslashit( WP_CONTENT_DIR ), 'content', $tick );
		}

		// manifest は最後に書く。これがあれば最後まで書き切ったファイルだと分かる
		$writer->add_string(
			self::MANIFEST,
			(string) wp_json_encode(
				array(
					'format'         => self::FORMAT,
					'content_hash'   => $writer->content_hash(),
					'plugin_version' => SP_VERSION,
					'created_at'     => gmdate( 'c' ),
					'engine'         => $sqlite ? 'sqlite' : 'mysql',
					'table_prefix'   => $wpdb->prefix,
					'tables'         => $tables,
					'sqlite_file'    => $sqlite_name,
					'home'           => get_option( 'home' ),
					'siteurl'        => get_option( 'siteurl' ),
					'abspath'        => untrailingslashit( ABSPATH ),
					'content_dir'    => untrailingslashit( WP_CONTENT_DIR ),
					'file_count'     => $this->file_count,
					'skipped_links'  => $this->skipped_links,
					'skipped_names'  => $this->skipped_names,
				)
			)
		);
		$writer->close();

		$tracker->begin( 'verify', '圧縮ファイルを確認しています', $total );
		SP_Tar_Writer::verify_complete( $archive, $tick );
	}

	/**
	 * SQLite のデータベースファイルを、整合性の取れたコピーにして追加する
	 *
	 * @return string 書庫内の名前
	 */
	private function add_sqlite_snapshot( SP_Tar_Writer $writer, string $job, ?callable $tick ): string {
		if ( ! defined( 'FQDB' ) ) {
			throw new RuntimeException( 'データベースファイルの場所が分かりません。' );
		}

		$real = realpath( FQDB );
		if ( false === $real ) {
			throw new RuntimeException( 'データベースファイルが見つかりません。' );
		}
		$real = wp_normalize_path( $real );

		$roots = array(
			'files'   => wp_normalize_path( (string) realpath( ABSPATH ) ),
			'content' => wp_normalize_path( (string) realpath( WP_CONTENT_DIR ) ),
		);
		$name = '';
		foreach ( $roots as $prefix => $root ) {
			if ( '' !== $root && str_starts_with( $real, trailingslashit( $root ) ) ) {
				$name = $prefix . '/' . substr( $real, strlen( trailingslashit( $root ) ) );
				break;
			}
		}
		if ( '' === $name ) {
			throw new RuntimeException( 'データベースファイルがサイトの外にあるので、バックアップできません。' );
		}

		$snapshot = $job . '/sqlite-snapshot';
		SP_Database::snapshot_sqlite( FQDB, $snapshot );
		if ( ! $writer->add_file( $name, $snapshot ) ) {
			throw new RuntimeException( 'データベースファイルをバックアップできませんでした。' );
		}
		++$this->file_count;
		unlink( $snapshot );
		if ( $tick ) {
			$tick();
		}

		foreach ( array( '', '-wal', '-shm', '-journal' ) as $suffix ) {
			$this->skip_names[ $name . $suffix ] = true;
		}
		return $name;
	}

	/**
	 * フォルダの中身を追加する
	 *
	 * リンクの先はサイトの外や循環の可能性があるのでたどらず、件数だけ数える。
	 */
	private function add_tree( SP_Tar_Writer $writer, string $dir, string $prefix, ?callable $tick ): void {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			throw new RuntimeException( 'フォルダを読み取れませんでした: ' . $dir );
		}

		// 空のフォルダも、プラグインやテーマが存在を前提にしていることがあるので残す
		if ( 2 === count( $entries ) && str_contains( $prefix, '/' ) ) {
			self::assert_restorable( $prefix, $dir );
			$writer->add_directory( $prefix, $dir );
			return;
		}

		$work = realpath( SP_Settings::work_root() );
		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			$name = $prefix . '/' . $entry;

			if ( is_link( $path ) ) {
				// サイトの外へのリンクになっている wp-content の中身は、別に追加している
				if ( realpath( $path ) === realpath( WP_CONTENT_DIR ) ) {
					continue;
				}
				++$this->skipped_links;
				if ( count( $this->skipped_names ) < self::MAX_SKIPPED_NAMES ) {
					$this->skipped_names[] = str_starts_with( $name, 'files/' ) ? substr( $name, strlen( 'files/' ) ) : 'wp-content/' . substr( $name, strlen( 'content/' ) );
				}
			} elseif ( is_dir( $path ) ) {
				if ( false === $work || realpath( $path ) !== $work ) {
					$this->add_tree( $writer, $path, $name, $tick );
				}
			} elseif ( is_file( $path ) && 'files/' . self::CONFIG !== $name && ! isset( $this->skip_names[ $name ] ) && ! SP_Settings::is_install_guard( $path ) ) {
				self::assert_restorable( $name, $path );
				if ( $writer->add_file( $name, $path ) ) {
					++$this->file_count;
				}
				if ( $tick ) {
					$tick();
				}
			}
		}
	}

	/** 取れても戻せないものは、バックアップが必要になってからでは手遅れなので今止める */
	private static function assert_restorable( string $name, string $path ): void {
		try {
			SP_Restore::assert_safe_name( $name );
		} catch ( RuntimeException ) {
			throw new RuntimeException( '復元できない名前のファイルがあるので中止しました: ' . $path );
		}
	}

	private function copy_to_directory( string $archive, string $directory, string $name, ?callable $tick ): void {
		$checked = SP_Settings::validate_directory( $directory );
		if ( is_wp_error( $checked ) ) {
			throw new RuntimeException( $checked->get_error_message() );
		}

		$part = $checked . '/' . $name . '.part';

		// コピーの途中でも他のユーザーに読まれないよう、作る時点で本人だけの権限にする
		$umask = umask( 0077 );
		$out   = fopen( $part, 'xb' );
		umask( $umask );
		$in = fopen( $archive, 'rb' );

		$copied = false !== $out && false !== $in;
		$bytes  = 0;
		while ( $copied && ! feof( $in ) ) {
			$data    = (string) fread( $in, 1048576 );
			$copied  = fwrite( $out, $data ) === strlen( $data );
			$bytes  += strlen( $data );
			if ( $tick ) {
				$tick( strlen( $data ) );
			}
		}
		$copied = $copied && (int) filesize( $archive ) === $bytes;
		if ( $in ) {
			fclose( $in );
		}
		if ( $out ) {
			$copied = fclose( $out ) && $copied;
		}

		if ( ! $copied || ! rename( $part, $checked . '/' . $name ) ) {
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new RuntimeException( '保存先ディレクトリへのコピーに失敗しました。空き容量を確認してください。' );
		}
	}

	private function prepare_download( string $archive, string $name ): void {
		self::delete_pending_download();

		$dir = SP_Settings::work_dir( 'download' );
		if ( ! rename( $archive, $dir . '/' . $name ) ) {
			throw new RuntimeException( 'ダウンロードの準備に失敗しました。' );
		}
		update_option( self::PENDING_OPTION, $name, false );
		update_option( self::OWNER_OPTION, get_current_user_id(), false );

		// 管理画面が開かれないまま残らないよう、後でも片付ける
		self::schedule_cleanup();
	}

	public static function pending_download(): ?string {
		$name = (string) get_option( self::PENDING_OPTION, '' );
		if ( '' === $name || basename( $name ) !== $name || ! SP_Settings::is_work_root_safe() ) {
			return null;
		}
		$path = SP_Settings::work_root() . '/download/' . $name;
		return is_file( $path ) ? $path : null;
	}

	public static function delete_pending_download(): void {
		SP_Settings::remove_work( 'download' );
		self::forget_pending_download();
		self::sweep();
	}

	/** ファイルは残したまま、ダウンロード待ちではなくする */
	public static function forget_pending_download(): void {
		delete_option( self::PENDING_OPTION );
		delete_option( self::OFFERED_OPTION );
		delete_option( self::OWNER_OPTION );
	}

	/** 送り終えたファイルだけを消す。送っている間に次のバックアップができていても、そちらは消さない */
	public static function remove_sent_download( string $path ): void {
		@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rmdir( dirname( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		self::sweep();
	}

	/**
	 * ダウンロードの案内を出してよいかを返す
	 *
	 * 案内は一度だけ。次の画面でもまだ残っていれば、サイトの秘密を含むファイルなので消す。
	 */
	public static function offer_download(): bool {
		if ( null === self::pending_download() || self::is_running() ) {
			return false;
		}
		// 実行した本人にだけ案内する。ほかの管理者の画面表示で使い切らないようにする
		$owner = (int) get_option( self::OWNER_OPTION, 0 );
		if ( 0 !== $owner && get_current_user_id() !== $owner ) {
			return false;
		}
		// ブロックエディターの画面には通知が表示されない
		$screen = get_current_screen();
		if ( $screen && $screen->is_block_editor() ) {
			return false;
		}
		if ( get_option( self::OFFERED_OPTION ) ) {
			self::delete_pending_download();
			return false;
		}
		update_option( self::OFFERED_OPTION, 1, false );
		return true;
	}

	/** 予定の時刻に、残っているダウンロード待ちと作業ファイルを片付ける */
	public static function cleanup(): void {
		$pending = self::pending_download();
		if ( null !== $pending && filemtime( $pending ) < time() - HOUR_IN_SECONDS ) {
			self::delete_pending_download();
		}
		self::sweep();

		// まだ新しくて消さなかったものは、次の予定で片付ける
		if ( null !== self::pending_download() || is_dir( SP_Settings::work_root() . '/upload' ) ) {
			self::schedule_cleanup();
		}
	}

	/**
	 * 強制終了などで残った作業ファイルを片付ける
	 *
	 * アップロード中のものを消さないよう、$force でなければ古いものだけを消す。
	 * 復元の一時ファイルは元のサイトの唯一のコピーかもしれないので、ここでは触らない。
	 */
	public static function sweep( bool $force = false ): void {
		if ( self::$lock || ! SP_Settings::is_work_root_safe() || ! is_dir( SP_Settings::work_root() ) ) {
			return;
		}
		// 実行中のものを消さないよう、ロックを取れたときだけ片付ける
		try {
			self::acquire_lock();
		} catch ( RuntimeException ) {
			return;
		}
		SP_Settings::remove_work( 'backup' );

		// 追記してもフォルダの更新日時は変わらないので、アップロード中かはファイルで見る
		$upload = SP_Settings::work_root() . '/upload';
		$latest = is_file( SP_Restore::upload_path() ) ? SP_Restore::upload_path() : $upload;
		if ( is_dir( $upload ) && ( $force || (int) filemtime( $latest ) < time() - HOUR_IN_SECONDS ) ) {
			SP_Settings::remove_work( 'upload' );
		}

		// 送信の途中で落ちて、ダウンロード待ちでなくなったファイル
		if ( null === self::pending_download() ) {
			SP_Settings::remove_work( 'download' );
		}

		if ( null === SP_Settings::leftover_path() ) {
			SP_Settings::remove_install_guard();
			SP_Settings::remove_work( 'restore' );
		}
		self::release_lock();
	}

	public static function schedule_cleanup(): void {
		wp_schedule_single_event( time() + HOUR_IN_SECONDS + MINUTE_IN_SECONDS, self::CLEANUP_HOOK );
	}

	public static function download_url(): string {
		// wp_nonce_url() は & をエスケープして返すので、JS から遷移する URL には使えない
		return add_query_arg(
			array(
				'action'   => 'sp_download',
				'_wpnonce' => wp_create_nonce( 'sp_download' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	private static function file_name(): string {
		// 保存後に書き換えられた値でもパスに入らないよう、使う時にも確かめる
		$name = (string) SP_Settings::get_settings()['backup_name'];
		if ( ! preg_match( SP_Settings::BACKUP_NAME_PATTERN, $name ) ) {
			$name = (string) preg_replace( '/[^a-z0-9.-]+/', '-', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		}
		return ( '' === $name ? 'site' : $name ) . '-' . wp_date( 'Ymd-His' ) . '.tar.gz';
	}

	private static function is_inside( string $path, string $parent ): bool {
		$path   = trailingslashit( wp_normalize_path( realpath( $path ) ?: $path ) );
		$parent = trailingslashit( wp_normalize_path( realpath( $parent ) ?: $parent ) );
		return str_starts_with( $path, $parent );
	}
}
