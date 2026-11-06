<?php
/**
 * 設定と秘密情報、作業用フォルダの管理
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Settings {
	private const string OPTION = 'sp_settings';
	private const string CIPHER = 'aes-256-gcm';
	private const int MIN_PASSWORD_LENGTH = 12;
	private const string INSTALL_GUARD_MARK = 'Sub Pod install guard';
	private const string BETA_PASSWORD_HASH = '3f330cbb065e912aaa5611806f1b0183298ccf065d29931a85426b46802832d4';

	private static ?string $work_root = null;

	public static function get_settings(): array {
		$settings = wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'dest_download'  => true,
				'dest_directory' => false,
				'dest_r2'        => false,
				'cp_backup'      => true,
				'directory'      => '',
				'r2_account_id'  => '',
				'r2_bucket'      => '',
				'r2_access_key'  => '',
				'r2_secret_key'  => '',
				'password'       => '',
			)
		);
		// 1.0 では R2 の設定の中に持っていた
		if ( '' === $settings['password'] && ! empty( $settings['r2_password'] ) ) {
			$settings['password'] = $settings['r2_password'];
		}
		unset( $settings['r2_password'] );
		return $settings;
	}

	/** 暗号化パスワード。未設定か、ソルトが変わって読み出せなければ null */
	public static function password(): ?string {
		return self::decrypt_secret( self::get_settings()['password'] );
	}

	/**
	 * 設定を検証して保存する
	 *
	 * R2 のシークレットと暗号化パスワードは、空のまま送られたら今の値を残す。
	 * 暗号化パスワードは打ち間違えると誰も復元できなくなるので、確認欄と一致したときだけ受け付ける。
	 */
	public static function save( array $input ): true|WP_Error {
		$current = self::get_settings();

		$settings = array(
			'dest_download'  => ! empty( $input['dest_download'] ),
			'dest_directory' => ! empty( $input['dest_directory'] ),
			'dest_r2'        => ! empty( $input['dest_r2'] ),
			// Carry Pod がないサイトでは欄を出さないので、今の値を残す
			'cp_backup'      => defined( 'CP_VERSION' ) ? ! empty( $input['cp_backup'] ) : $current['cp_backup'],
			'directory'      => self::from_terminal_form( trim( (string) ( $input['directory'] ?? '' ) ) ),
			'r2_account_id'  => strtolower( trim( (string) ( $input['r2_account_id'] ?? '' ) ) ),
			'r2_bucket'      => trim( (string) ( $input['r2_bucket'] ?? '' ) ),
			'r2_access_key'  => trim( (string) ( $input['r2_access_key'] ?? '' ) ),
			'r2_secret_key'  => $current['r2_secret_key'],
			'password'       => $current['password'],
		);

		if ( ! $settings['dest_download'] && ! $settings['dest_directory'] && ! $settings['dest_r2'] ) {
			return new WP_Error( 'no_destination', '保存先を1つ以上選んでください。' );
		}

		// 閉じている欄の入力で保存が止まらないよう、選んだ保存先だけを確かめる
		if ( $settings['dest_directory'] ) {
			$directory = self::validate_directory( $settings['directory'] );
			if ( is_wp_error( $directory ) ) {
				return $directory;
			}
			$settings['directory'] = $directory;
		}

		$secret = (string) ( $input['r2_secret_key'] ?? '' );
		if ( '' !== $secret ) {
			$settings['r2_secret_key'] = self::encrypt_secret( $secret );
		}

		$password = (string) ( $input['password'] ?? '' );
		if ( '' !== $password ) {
			if ( (string) ( $input['password_confirm'] ?? '' ) !== $password ) {
				return new WP_Error( 'password_mismatch', '暗号化パスワードと確認用の入力が一致しません。' );
			}
			if ( mb_strlen( $password, 'UTF-8' ) < self::MIN_PASSWORD_LENGTH ) {
				return new WP_Error( 'weak_password', '暗号化パスワードは' . self::MIN_PASSWORD_LENGTH . '文字以上にしてください。' );
			}
			// 同じ文字や同じ並びの繰り返しだけでできているもの(aaaa…、abcabc…)
			if ( preg_match( '/^(.+?)\1+$/us', $password ) ) {
				return new WP_Error( 'weak_password', '暗号化パスワードに、同じ文字や並びの繰り返しは使えません。' );
			}
			$settings['password'] = self::encrypt_secret( $password );
		}
		if ( null === self::decrypt_secret( $settings['password'] ) ) {
			return new WP_Error( 'password', 'バックアップの暗号化パスワードを入力してください。' );
		}

		if ( $settings['dest_r2'] ) {
			if ( ! preg_match( '/^[a-f0-9]{32}$/D', $settings['r2_account_id'] ) ) {
				return new WP_Error( 'r2_account', 'R2 のアカウント ID が正しくありません。' );
			}
			if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $settings['r2_bucket'] ) ) {
				return new WP_Error( 'r2_bucket', 'R2 のバケット名が正しくありません。' );
			}
			if ( ! preg_match( '/^[A-Za-z0-9]+$/D', $settings['r2_access_key'] ) ) {
				return new WP_Error( 'r2_access_key', 'R2 のアクセスキー ID が正しくありません。' );
			}
			if ( '' === $settings['r2_secret_key'] ) {
				return new WP_Error( 'r2_secret', 'R2 のシークレットアクセスキーを入力してください。' );
			}
		}

		update_option( self::OPTION, $settings, false );
		return true;
	}

	/**
	 * 実行に使う保存先を返す
	 *
	 * CP の自動実行にはダウンロードする人がいないので、ダウンロードを除く。
	 *
	 * @return string[] download / directory / r2
	 */
	public static function destinations( bool $automatic = false ): array {
		$settings     = self::get_settings();
		$destinations = array();
		if ( $settings['dest_download'] && ! $automatic ) {
			$destinations[] = 'download';
		}
		if ( $settings['dest_directory'] ) {
			$destinations[] = 'directory';
		}
		if ( $settings['dest_r2'] ) {
			$destinations[] = 'r2';
		}
		return $destinations;
	}

	/**
	 * 別の環境へ復元した設定から、元の環境と同じ場所へ送る保存先を外す
	 *
	 * テスト用のサイトへ復元すると、次のバックアップが本番と同じ場所へ届いてしまう。
	 *
	 * @return string|null 外すものがなければ null
	 */
	public static function settings_for_new_environment( string $stored ): ?string {
		$settings = is_serialized( $stored ) ? @unserialize( $stored, array( 'allowed_classes' => false ) ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! is_array( $settings ) || ( empty( $settings['dest_directory'] ) && empty( $settings['dest_r2'] ) ) ) {
			return null;
		}
		$settings['dest_directory'] = false;
		$settings['dest_r2']        = false;
		$settings['dest_download']  = true;
		return serialize( $settings ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
	}

	/**
	 * 保存先ディレクトリを検証する
	 *
	 * WordPress の中に置くと URL で取得されたり静的サイトと一緒に公開されたりするので、外だけを認める。
	 */
	public static function validate_directory( string $path ): string|WP_Error {
		if ( '' === $path ) {
			return new WP_Error( 'directory_empty', '保存先ディレクトリを入力してください。' );
		}
		if ( str_contains( $path, "\0" ) || str_contains( $path, '..' ) ) {
			return new WP_Error( 'directory_invalid', '保存先ディレクトリに使えない文字が含まれています。' );
		}
		if ( ! path_is_absolute( $path ) ) {
			return new WP_Error( 'directory_relative', '保存先ディレクトリは絶対パスで入力してください。' );
		}

		$target = self::normalize_path( $path );
		foreach ( array_filter( array( ABSPATH, WP_CONTENT_DIR, self::config_path() ? dirname( self::config_path() ) : '' ) ) as $site_path ) {
			$site = self::normalize_path( $site_path );
			if ( $target === $site || str_starts_with( $target, $site . '/' ) || str_starts_with( $site, $target . '/' ) ) {
				return new WP_Error( 'directory_inside', '保存先ディレクトリは WordPress の外を指定してください。' );
			}
		}

		// Carry Pod は出力のたびに出力先を空にするので、重なるとバックアップが消える
		$carry_pod = (array) get_option( 'cp_settings', array() );
		foreach ( array( 'local_enabled' => 'local_output_path', 'git_local_enabled' => 'git_local_work_dir' ) as $enabled => $key ) {
			if ( empty( $carry_pod[ $enabled ] ) || empty( $carry_pod[ $key ] ) ) {
				continue;
			}
			$output = self::normalize_path( (string) $carry_pod[ $key ] );
			if ( $target === $output || str_starts_with( $target, $output . '/' ) || str_starts_with( $output, $target . '/' ) ) {
				return new WP_Error( 'directory_carry_pod', '保存先ディレクトリは Carry Pod の出力先と重ならない場所を指定してください。' );
			}
		}

		if ( ! is_dir( $path ) && ! wp_mkdir_p( $path ) ) {
			return new WP_Error( 'directory_create', '保存先ディレクトリを作成できませんでした。' );
		}
		if ( ! wp_is_writable( $path ) ) {
			return new WP_Error( 'directory_write', '保存先ディレクトリに書き込めません。' );
		}

		return untrailingslashit( $path );
	}

	/**
	 * ターミナルからコピーした形(引用符、\ でのエスケープ)を、実際のパスへ戻す
	 *
	 * そのまま使うと、\ を名前に含む別のフォルダを作ってしまう。Windows では \ は区切りなので触らない。
	 */
	private static function from_terminal_form( string $path ): string {
		// そのままの名前のフォルダがあれば、\ も名前の一部(保存済みの値を保存し直したときなど)
		if ( 'Windows' === PHP_OS_FAMILY || is_dir( $path ) ) {
			return $path;
		}
		if ( preg_match( "/^'(.*)'$/", $path, $quoted ) ) {
			return str_replace( "'\\''", "'", $quoted[1] );
		}
		if ( preg_match( '/^"(.*)"$/', $path, $quoted ) ) {
			return (string) preg_replace( '/\\\\(["\\\\$`])/', '$1', $quoted[1] );
		}
		return (string) preg_replace( '/\\\\(.)/', '$1', $path );
	}

	/** まだ存在しないフォルダでも、存在する親までたどってシンボリックリンクを解決する */
	private static function normalize_path( string $path ): string {
		$missing = '';
		while ( false === realpath( $path ) && dirname( $path ) !== $path ) {
			$missing = '/' . basename( $path ) . $missing;
			$path    = dirname( $path );
		}
		$path = untrailingslashit( wp_normalize_path( ( realpath( $path ) ?: $path ) . $missing ) );
		return in_array( PHP_OS_FAMILY, array( 'Darwin', 'Windows' ), true ) ? strtolower( $path ) : $path;
	}

	public static function config_path(): ?string {
		foreach ( array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ) as $path ) {
			if ( is_file( $path ) ) {
				return $path;
			}
		}
		return null;
	}

	public static function encrypt_secret( string $plain ): string {
		$nonce  = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, self::CIPHER, self::secret_key(), OPENSSL_RAW_DATA, $nonce, $tag );
		return base64_encode( $nonce . $tag . $cipher );
	}

	public static function decrypt_secret( string $stored ): ?string {
		$raw = base64_decode( $stored, true );
		if ( false === $raw || strlen( $raw ) < 28 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), self::CIPHER, self::secret_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? null : $plain;
	}

	private static function secret_key(): string {
		return hash( 'sha256', 'sub-pod|' . wp_salt( 'secure_auth' ) . wp_salt( 'auth' ), true );
	}

	/**
	 * 公開されない作業用フォルダを返す
	 *
	 * 通常は OS の一時フォルダを使う。WordPress Studio の一時フォルダはメモリ上にあって
	 * 大きいファイルに向かないので、推測できない名前で wp-content の中に作る。
	 */
	public static function work_root(): string {
		// 復元中にオプションが変わっても、同じリクエストの間は同じ場所を指し続ける
		self::$work_root ??= self::find_work_root();
		return self::$work_root;
	}

	private static function find_work_root(): string {
		// 予備の場所に作業用フォルダがあれば、実行環境の判定が変わっても(Studio の WP-CLI など)見失わない
		$name = (string) get_option( 'sp_work_fallback' );
		if ( ! preg_match( '/^\.sub-pod-work-[A-Za-z0-9]{32}$/D', $name ) ) {
			$name = '';
		}
		if ( '' !== $name && is_dir( trailingslashit( WP_CONTENT_DIR ) . $name ) ) {
			return trailingslashit( WP_CONTENT_DIR ) . $name;
		}

		$temp = untrailingslashit( sys_get_temp_dir() );
		if ( ! self::is_playground() && wp_is_writable( $temp ) ) {
			// ソルトを変えても中の一時ファイルを見失わないよう、サイトの場所だけで決める
			// 他のユーザーから守るのは名前ではなく、work_dir() の権限と所有者の確認
			// 先頭の . は、Carry Pod の追加ファイルに親フォルダが指定されても静的サイトに出さないため
			return $temp . '/.sub-pod-' . substr( hash( 'sha256', ABSPATH ), 0, 32 );
		}

		if ( '' === $name ) {
			$name = '.sub-pod-work-' . wp_generate_password( 32, false );
			update_option( 'sp_work_fallback', $name, false );
		}
		return trailingslashit( WP_CONTENT_DIR ) . $name;
	}

	public static function is_playground(): bool {
		return false !== stripos( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ), 'php.wasm' );
	}

	/**
	 * 作業用フォルダが自分のものか確かめる
	 *
	 * 一時フォルダは他のユーザーからも見えるので、他人が先に作ったものやリンクは使わない。
	 * 読むだけの処理や片付けも含めて、中に触る処理はすべてここを通す。
	 */
	public static function is_work_root_safe(): bool {
		$root = self::work_root();
		if ( is_link( $root ) ) {
			return false;
		}
		return ! file_exists( $root ) || ! function_exists( 'posix_getuid' ) || fileowner( $root ) === posix_getuid();
	}

	/**
	 * 作業用フォルダの中を消す。$name を省くとフォルダごと消す
	 */
	public static function remove_work( string $name = '' ): void {
		if ( self::is_work_root_safe() ) {
			self::remove_directory( '' === $name ? self::work_root() : self::work_root() . '/' . $name );
		}
	}

	/**
	 * 中身が何も残っていなければ、作業用フォルダごと消す
	 */
	public static function remove_work_root_if_empty(): void {
		if ( ! self::is_work_root_safe() || ! is_dir( self::work_root() ) ) {
			return;
		}
		$own     = array( 'lock', '.htaccess', 'index.php' );
		$entries = array_diff( scandir( self::work_root() ) ?: array( 'unreadable' ), array( '.', '..' ), $own );
		if ( ! $entries ) {
			foreach ( $own as $name ) {
				@unlink( self::work_root() . '/' . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
			@rmdir( self::work_root() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * 作業用フォルダの中のサブフォルダを用意する
	 */
	public static function work_dir( string $name = '' ): string {
		$root = self::work_root();
		if ( ! is_dir( $root ) ) {
			if ( ! mkdir( $root, 0700, true ) && ! is_dir( $root ) ) {
				throw new RuntimeException( '作業用フォルダを作成できませんでした。' );
			}
		}
		if ( ! self::is_work_root_safe() ) {
			throw new RuntimeException( '作業用フォルダを安全に使えません。' );
		}
		// 公開される場所にあるときの守り。片付けと重なって欠けても、ここで作り直す
		if ( str_starts_with( $root, untrailingslashit( WP_CONTENT_DIR ) ) ) {
			foreach ( array( '.htaccess' => "Require all denied\n", 'index.php' => "<?php\n" ) as $file => $content ) {
				if ( ! file_exists( $root . '/' . $file ) ) {
					file_put_contents( $root . '/' . $file, $content );
				}
			}
		}

		$dir = '' === $name ? $root : $root . '/' . $name;
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
			throw new RuntimeException( '作業用フォルダを作成できませんでした。' );
		}
		return $dir;
	}

	/**
	 * 完了しなかった復元の、元のサイトのデータを含む一時ファイルの場所を返す
	 */
	public static function leftover_path(): ?string {
		if ( ! self::is_work_root_safe() ) {
			return null;
		}
		$job = self::work_root() . '/restore';
		return is_dir( $job . '/rollback' ) || is_file( $job . '/rollback.sql' ) ? $job : null;
	}

	/**
	 * インストール画面と更新画面を塞ぐファイルの場所
	 *
	 * WordPress はこれらの画面を開くときに最初に読み込む。どちらもメンテナンスモードの対象外なので、
	 * テーブルがない間に開かれると、誰でも管理者を作れてしまう。
	 */
	public static function install_guard_path(): string {
		return trailingslashit( WP_CONTENT_DIR ) . 'install.php';
	}

	public static function is_install_guard( string $path ): bool {
		return 'install.php' === basename( $path ) && is_file( $path ) && str_contains( (string) file_get_contents( $path, false, null, 0, 256 ), self::INSTALL_GUARD_MARK );
	}

	public static function write_install_guard(): bool {
		// 塞ぐのはインストール画面と更新画面だけ。ほかのプラグインのテーブル作成などでも読み込まれるので、それは止めない
		$guard = "<?php\n// " . self::INSTALL_GUARD_MARK . ": removed when the restore ends. If a restore was cut off, put the site back and delete this file.\n"
			. "if ( wp_installing() ) {\n"
			. "\tif ( ! headers_sent() ) {\n\t\theader( 'HTTP/1.1 503 Service Unavailable' );\n\t\theader( 'Content-Type: text/html; charset=utf-8' );\n\t}\n"
			. "\texit( '<!doctype html><meta charset=\"utf-8\"><title>メンテナンス中</title><p>このサイトは復元の途中です。しばらく経っても終わらないときは、サイトを元に戻してから wp-content/install.php を削除してください。</p>' );\n"
			. "}\n";
		return false !== file_put_contents( self::install_guard_path(), $guard );
	}

	/**
	 * 塞ぐファイルを消す
	 *
	 * @param bool $restore_original 退避しておいた、もともとの install.php を戻す
	 */
	public static function remove_install_guard( bool $restore_original = true ): void {
		$guard = self::install_guard_path();
		if ( self::is_install_guard( $guard ) ) {
			@unlink( $guard ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$original = self::work_root() . '/restore/original-install.php';
		if ( $restore_original && self::is_work_root_safe() && is_file( $original ) && ! file_exists( $guard ) ) {
			@rename( $original, $guard ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/** リンクの先はたどらず、リンク自体だけを消す */
	public static function remove_directory( string $dir ): void {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) ?: array() as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::remove_directory( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	public static function is_beta_mode_enabled(): bool {
		return (bool) get_transient( 'sp_beta_channel' );
	}

	/**
	 * @return bool|WP_Error 成功時true、失敗時false、レート制限時WP_Error
	 */
	public static function enable_beta_mode( string $password ): bool|WP_Error {
		$attempts_key = 'sp_beta_attempts_' . get_current_user_id();
		$attempts     = (int) get_transient( $attempts_key );
		if ( $attempts >= 5 ) {
			return new WP_Error( 'rate_limit', 'ログイン試行回数が超過しました。10分後に再試行してください。' );
		}

		if ( hash_equals( self::BETA_PASSWORD_HASH, hash( 'sha256', $password ) ) ) {
			delete_transient( $attempts_key );
			set_transient( 'sp_beta_channel', true, DAY_IN_SECONDS );
			return true;
		}

		set_transient( $attempts_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	public static function disable_beta_mode(): void {
		delete_transient( 'sp_beta_channel' );
		delete_transient( 'sp_github_release_cache_beta' );
	}
}
