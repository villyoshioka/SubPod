<?php
/**
 * バックアップからの復元
 *
 * まず公開されない場所へ展開して検査し、問題なければ今のサイトと入れ替える。
 * 入れ替えの途中で失敗したら、一時ファイルに退避しておいた今のサイトへ戻す。
 * ログイン状態が変わっても止まらないよう、最初から最後まで 1 リクエストで処理する。
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Restore {
	private const int MAX_META_SIZE = 5242880;
	private const int DISK_MARGIN = 104857600;
	private const string MOVED_NOTICE = '別の環境へ復元したので、保存先の Cloudflare R2 と特定のディレクトリをオフにしました。使うときは Sub Pod の設定を確認してください。';
	private const int MAINTENANCE_REFRESH = 60;
	private const int GUARD_GRACE = 2;

	/** @var callable|null 長い処理の途中で定期的に呼ぶ */
	private static $heartbeat = null;

	private string $job = '';
	private bool $swapping = false;

	/** @var array<int, array{0: string, 1: string}> 元の場所 => 退避先 */
	private array $moved_out = array();

	/** @var string[] 入れ替えで置いたもの */
	private array $placed = array();

	/** @var string[] 復元前からあるテーブル */
	private array $current_tables = array();

	/** @var string[] 復元で作るテーブル */
	private array $restored_tables = array();

	private bool $database_touched = false;
	private ?string $original_config = null;
	private int $original_config_mode = 0644;
	private string $work_fallback = '';
	private int $maintenance_at = 0;
	private bool $install_guarded = false;

	/** @var string[] 元の状態に戻しても戻らないトリガー */
	private array $lost_triggers = array();
	private array $manifest = array();

	public static function upload_path(): string {
		return SP_Settings::work_root() . '/upload/upload.bin';
	}

	public static function discard_upload(): void {
		// 別のタブの失敗で、実行中の復元が使っているファイルを消さない
		if ( ! SP_Backup::is_running() ) {
			SP_Settings::remove_work( 'upload' );
		}
	}

	/** このバックアップに含まれていなかったリンクの数 */
	public function skipped_links(): int {
		return (int) ( $this->manifest['skipped_links'] ?? 0 );
	}

	/** @return string[] */
	public function skipped_names(): array {
		$names = $this->manifest['skipped_names'] ?? array();
		return is_array( $names ) ? array_values( array_filter( $names, 'is_string' ) ) : array();
	}

	public static function leftover_path(): ?string {
		return SP_Settings::leftover_path();
	}

	public static function discard_leftover(): void {
		SP_Backup::acquire_lock();
		try {
			SP_Settings::remove_install_guard();
			SP_Settings::remove_work( 'restore' );
		} finally {
			SP_Backup::release_lock();
		}
	}

	/**
	 * @param callable|null $progress fn( string $message, int $percent ): void
	 * @param callable|null $tick     長い処理の途中で定期的に呼ぶ
	 * @return string 復元後のログイン画面の URL
	 */
	public function run( string $password, ?callable $progress = null, ?callable $tick = null ): string {
		SP_Backup::acquire_lock();
		$started = false;
		try {
			// 一時ファイルは元のサイトの唯一のコピーかもしれないので、黙って消さない
			if ( null !== self::leftover_path() ) {
				throw new RuntimeException( '前回の復元の一時ファイルが残っています。画面の案内を確認してから、もう一度実行してください。' );
			}
			$started = true;
			SP_Settings::remove_work( 'restore' );
			$this->job = SP_Settings::work_dir( 'restore' );

			$upload = self::upload_path();
			if ( ! is_file( $upload ) ) {
				throw new RuntimeException( 'アップロードされたファイルが見つかりません。' );
			}

			// 暗号化されていないファイルは、すり替えられたものかもしれないので受け付けない
			if ( ! SP_Crypto::is_encrypted( $upload ) ) {
				throw new RuntimeException( '暗号化されていないファイルは復元できません。暗号化されたバックアップ(.enc)を選んでください。' );
			}
			if ( '' === $password ) {
				throw new RuntimeException( '暗号化パスワードを入力してください。' );
			}

			$sqlite    = SP_Database::is_sqlite();
			$heartbeat = function () use ( $tick ): void {
				if ( $tick ) {
					$tick();
				}
				$this->refresh_maintenance();
			};
			$tracker   = new SP_Progress(
				$progress,
				$heartbeat,
				array(
					'decrypt'  => 10,
					'extract'  => $sqlite ? 70 : 40,
					'rollback' => $sqlite ? 0 : 10,
					'import'   => $sqlite ? 0 : 30,
					'rewrite'  => $sqlite ? 20 : 5,
					'swap'     => $sqlite ? 10 : 5,
				)
			);
			$tick            = array( $tracker, 'tick' );
			self::$heartbeat = $tick;

			$tracker->begin( 'decrypt', '復号しています', (int) filesize( $upload ) );
			$archive = $this->job . '/archive.tar.gz';
			SP_Crypto::decrypt_file( $upload, $archive, $password, $tick );

			$tracker->begin( 'extract', 'ファイルを展開して検査しています', SP_Tar_Reader::estimate_size( $archive ) );
			$staging = $this->job . '/staging';
			mkdir( $staging, 0700 );
			$manifest = $this->extract_and_verify( $archive, $staging, $tick );

			$this->manifest = $manifest;

			$new_urls = array(
				'home'    => (string) get_option( 'home' ),
				'siteurl' => (string) get_option( 'siteurl' ),
			);
			$config   = $this->build_config( $staging, $manifest, $new_urls );

			if ( ! wp_is_writable( ABSPATH ) ) {
				throw new RuntimeException( 'サイトのフォルダに書き込めません。' );
			}

			$this->assert_swap_space( $staging );
			if ( 'mysql' === $manifest['engine'] ) {
				$this->assert_tables_available( $manifest['tables'] );
			}

			$this->work_fallback = (string) get_option( 'sp_work_fallback', '' );

			$this->adjust_htaccess( $staging, $manifest, $new_urls );

			if ( 'sqlite' === $manifest['engine'] ) {
				$tracker->begin( 'rewrite', 'URL を書き換えています' );
				$this->prepare_sqlite( $staging, $manifest, $new_urls, $tick );
			}

			register_shutdown_function( array( $this, 'rollback_on_shutdown' ) );

			$this->swapping = true;
			$this->start_maintenance();
			if ( 'mysql' === $manifest['engine'] ) {
				$this->restore_database( $staging, $manifest, $new_urls, $tracker );
			}

			$this->start_install_guard();
			$tracker->begin( 'swap', 'ファイルを入れ替えています' );
			$this->swap_files( $staging, $manifest );
			$this->swap_config( $config );

			// 成功したら、ダウンロード待ちも含めて作業用フォルダをすべて片付ける
			$this->swapping = false;
			self::reset_opcache();
			// 古い wp-config.php を読み込み済みのリクエストは、テーブルがないと判断してインストール画面を出す。それが終わるのを待つ
			sleep( self::GUARD_GRACE );
			$this->finish_install_guard();
			$this->end_maintenance();
			foreach ( array( 'restore', 'upload', 'backup', 'download' ) as $name ) {
				SP_Settings::remove_work( $name );
			}
			SP_Backup::release_lock();

			return trailingslashit( $new_urls['siteurl'] ) . 'wp-login.php';
		} catch ( Throwable $e ) {
			if ( $this->swapping ) {
				$this->swapping = false;
				if ( ! $this->rollback() ) {
					error_log( 'Sub Pod: 復元の巻き戻しに失敗しました。元のサイトのデータ: ' . $this->job ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
					throw new RuntimeException( '復元に失敗し、元の状態にも戻せませんでした。元のサイトのデータは一時ファイルとして次の場所に残っています: ' . $this->job . '(原因: ' . $e->getMessage() . ')' );
				}
				$lost = $this->database_touched && $this->lost_triggers ? '(トリガーは戻せませんでした: ' . implode( '、', $this->lost_triggers ) . ')' : '';
				throw new RuntimeException( '復元に失敗したので、元の状態に戻しました' . $lost . ': ' . $e->getMessage() );
			}
			if ( $started && '' !== $this->job ) {
				SP_Settings::remove_directory( $this->job );
			}
			throw $e;
		} finally {
			SP_Backup::release_lock();
		}
	}

	/**
	 * PHP の致命的エラーで処理が途切れたときも元に戻す
	 *
	 * ponytail: 電源断などプロセスごと落ちた場合は戻せない。そのときは作業用フォルダの rollback から手で戻す
	 */
	public function rollback_on_shutdown(): void {
		if ( $this->swapping ) {
			$this->swapping = false;
			if ( ! $this->rollback() ) {
				error_log( 'Sub Pod: 復元の巻き戻しに失敗しました。元のサイトのデータ: ' . $this->job ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			}
		}
	}

	/**
	 * 入れ替えの間は、ほかのリクエスト(WP-Cron や別のタブ)がサイトを動かさないようにする
	 *
	 * プロセスごと落ちても、WordPress が古いと判断して 10 分で解除される。
	 * 処理中に古いと判断されると、致命的エラーのときに元へ戻す処理まで止まるので、途中で更新し続ける。
	 */
	private function start_maintenance(): void {
		$this->maintenance_at = time();
		// 整数だと、時刻を推測するだけでメンテナンス画面を通り抜けられる(WordPress の更新時の確認用の仕組み)ので、文字列にする
		@file_put_contents( self::maintenance_path(), "<?php \$upgrading = '" . $this->maintenance_at . "';" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private function refresh_maintenance(): void {
		if ( $this->maintenance_at > 0 && time() - $this->maintenance_at >= self::MAINTENANCE_REFRESH ) {
			$this->start_maintenance();
		}
	}

	/**
	 * テーブルやファイルがない状態になる前に、インストール画面と更新画面を塞ぐ
	 *
	 * プロセスごと落ちたときも残して、閉じたままにする。もともと install.php があれば退避して戻す。
	 */
	private function start_install_guard(): void {
		if ( $this->install_guarded ) {
			return;
		}
		$path = SP_Settings::install_guard_path();
		if ( is_file( $path ) && ! SP_Settings::is_install_guard( $path ) && ! rename( $path, $this->job . '/original-install.php' ) ) {
			throw new RuntimeException( 'wp-content の install.php を退避できませんでした。' );
		}
		if ( ! SP_Settings::write_install_guard() ) {
			throw new RuntimeException( 'wp-content に書き込めません。' );
		}
		$this->install_guarded = true;
	}

	/** 復元が終わったので塞ぐのをやめて、バックアップに入っていた install.php を置く */
	private function finish_install_guard(): void {
		SP_Settings::remove_install_guard( false );
		$incoming = $this->job . '/incoming-install.php';
		if ( is_file( $incoming ) ) {
			rename( $incoming, SP_Settings::install_guard_path() );
		}
	}

	/** 塞いでいる間は置けないので、バックアップに入っていた install.php を取り分けておく */
	private function set_aside_incoming_install( string $staging ): void {
		$root    = trailingslashit( wp_normalize_path( (string) realpath( ABSPATH ) ) );
		$content = wp_normalize_path( (string) realpath( WP_CONTENT_DIR ) );
		$name    = str_starts_with( $content, $root ) ? substr( $content, strlen( $root ) ) : 'wp-content';
		foreach ( array( $staging . '/content/install.php', $staging . '/files/' . $name . '/install.php' ) as $incoming ) {
			if ( SP_Settings::is_install_guard( $incoming ) ) {
				unlink( $incoming );
			} elseif ( is_file( $incoming ) && ! rename( $incoming, $this->job . '/incoming-install.php' ) ) {
				throw new RuntimeException( 'ファイルの入れ替えに失敗しました: ' . $incoming );
			}
		}
	}

	private function end_maintenance(): void {
		$this->maintenance_at = 0;
		@unlink( self::maintenance_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * 入れ替えた PHP ファイルの古いキャッシュを捨てる
	 *
	 * 古い wp-config.php が残っていると、消したテーブルを見にいってインストール画面が出てしまう。
	 */
	private static function reset_opcache(): void {
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	private static function maintenance_path(): string {
		return ABSPATH . '.maintenance';
	}

	private function extract_and_verify( string $archive, string $staging, ?callable $tick ): array {
		$count  = 0;
		$reader = new SP_Tar_Reader( $archive, $tick, SP_Backup::MANIFEST );
		$ended  = $reader->extract(
			function ( string $name, int $size ) use ( $staging, &$count ): string {
				self::assert_safe_name( $name );

				$free = disk_free_space( $staging );
				if ( false !== $free && $free < $size + self::DISK_MARGIN ) {
					throw new RuntimeException( '空き容量が足りないので展開を中止しました。' );
				}
				if ( $size >= 0 && ( str_starts_with( $name, 'files/' ) || str_starts_with( $name, 'content/' ) ) ) {
					++$count;
				}
				return $staging . '/' . $name;
			}
		);
		$reader->close();

		// manifest は最後に読むので、確認値はここまでの内容と突き合わせられる
		$manifest_path = $staging . '/' . SP_Backup::MANIFEST;
		if ( ! $ended || ! is_file( $manifest_path ) || filesize( $manifest_path ) > self::MAX_META_SIZE ) {
			throw new RuntimeException( 'Sub Pod のバックアップではないか、途中で切れています。' );
		}

		$manifest = self::validate_manifest( (string) file_get_contents( $manifest_path ) );
		if ( $manifest['file_count'] !== $count || ! hash_equals( $manifest['content_hash'], $reader->content_hash() ) ) {
			throw new RuntimeException( 'バックアップの内容が一致しません。ファイルが壊れている可能性があります。' );
		}

		$engine = SP_Database::is_sqlite() ? 'sqlite' : 'mysql';
		if ( $manifest['engine'] !== $engine ) {
			throw new RuntimeException( 'データベースの種類が違う環境へは復元できません。' );
		}
		if ( 'mysql' === $engine && ! is_file( $staging . '/' . SP_Backup::DATABASE ) ) {
			throw new RuntimeException( 'バックアップにデータベースが含まれていません。' );
		}
		if ( 'sqlite' === $engine ) {
			$file = $manifest['sqlite_file'] ?? '';
			if ( ! is_string( $file ) || ! ( str_starts_with( $file, 'files/' ) || str_starts_with( $file, 'content/' ) ) ) {
				throw new RuntimeException( 'バックアップにデータベースが含まれていません。' );
			}
			self::assert_safe_name( $file );
			if ( ! is_file( $staging . '/' . $file ) ) {
				throw new RuntimeException( 'バックアップにデータベースが含まれていません。' );
			}
		}
		if ( ! is_file( $staging . '/' . SP_Backup::CONFIG ) || filesize( $staging . '/' . SP_Backup::CONFIG ) > self::MAX_META_SIZE ) {
			throw new RuntimeException( 'バックアップの wp-config.php が正しくありません。' );
		}

		return $manifest;
	}

	/**
	 * 展開してよい名前か確かめる
	 *
	 * サイトの外へ書き出せる名前と、決めた場所以外の名前はここで止める。
	 */
	public static function assert_safe_name( string $name ): void {
		if ( in_array( $name, array( SP_Backup::MANIFEST, SP_Backup::DATABASE, SP_Backup::CONFIG ), true ) ) {
			return;
		}

		// \ と : は Windows でだけパスの区切りになる
		$windows   = 'Windows' === PHP_OS_FAMILY;
		$forbidden = $windows ? "\\:\0" : "\0";
		$segments  = explode( '/', $name );
		$valid    = in_array( $segments[0], array( 'files', 'content' ), true ) && count( $segments ) >= 2;
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment || false !== strpbrk( $segment, $forbidden ) ) {
				$valid = false;
			}
			// Windows は末尾の空白とドットを無視するので、「.. 」のような名前が親フォルダを指しうる
			if ( $windows && rtrim( $segment, ' .' ) !== $segment ) {
				$valid = false;
			}
		}
		if ( ! $valid || 0 === strcasecmp( $name, 'files/' . SP_Backup::CONFIG ) ) {
			throw new RuntimeException( '圧縮ファイルに受け付けられない場所のファイルが含まれています。' );
		}
	}

	public static function validate_manifest( string $json ): array {
		$manifest = json_decode( $json, true );
		if ( ! is_array( $manifest ) || ( $manifest['format'] ?? 0 ) !== SP_Backup::FORMAT ) {
			throw new RuntimeException( 'この Sub Pod では読み取れない形式のバックアップです。' );
		}

		foreach ( array( 'home', 'siteurl', 'abspath', 'content_dir', 'engine', 'table_prefix', 'content_hash' ) as $key ) {
			if ( ! is_string( $manifest[ $key ] ?? null ) || '' === $manifest[ $key ] ) {
				throw new RuntimeException( 'バックアップの情報が正しくありません。' );
			}
		}
		if ( ! is_int( $manifest['file_count'] ?? null ) || ! is_array( $manifest['tables'] ?? null ) ) {
			throw new RuntimeException( 'バックアップの情報が正しくありません。' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $manifest['table_prefix'] ) ) {
			throw new RuntimeException( 'バックアップのテーブル接頭辞が正しくありません。' );
		}
		if ( 'mysql' === $manifest['engine'] && ! $manifest['tables'] ) {
			throw new RuntimeException( 'バックアップにデータベースが含まれていません。' );
		}
		foreach ( $manifest['tables'] as $table ) {
			if ( ! is_string( $table ) || ! SP_Database::is_valid_table_name( $table ) || ! str_starts_with( $table, $manifest['table_prefix'] ) ) {
				throw new RuntimeException( 'バックアップのテーブル名が正しくありません。' );
			}
		}
		return $manifest;
	}

	/**
	 * 復元後の wp-config.php を組み立てる
	 *
	 * 接続先と URL だけ今の環境の値を残し、他(ソルトなど)はバックアップのまま戻す。
	 */
	private function build_config( string $staging, array $manifest, array $new_urls ): string {
		$content = (string) file_get_contents( $staging . '/' . SP_Backup::CONFIG );
		$content = self::replace_in_strings( $content, self::url_pairs( $manifest, $new_urls ) );

		$prefix = self::config_table_prefix( $content );
		if ( null !== $prefix && $prefix !== $manifest['table_prefix'] ) {
			throw new RuntimeException( 'バックアップの wp-config.php とデータベースの情報が一致しません。' );
		}

		if ( 'mysql' === $manifest['engine'] ) {
			$values = array(
				'DB_NAME'     => DB_NAME,
				'DB_USER'     => DB_USER,
				'DB_PASSWORD' => DB_PASSWORD,
				'DB_HOST'     => DB_HOST,
			);
			foreach ( $values as $name => $value ) {
				$content = self::replace_define( $content, $name, $value, $found );
				if ( ! $found ) {
					throw new RuntimeException( 'バックアップの wp-config.php に ' . $name . ' が見つかりません。' );
				}
			}
		}

		$content = self::replace_define( $content, 'WP_HOME', $new_urls['home'], $found );
		$content = self::replace_define( $content, 'WP_SITEURL', $new_urls['siteurl'], $found );

		// 入れ替えてから構文エラーに気づくと戻せないので、ここで確かめる
		try {
			token_get_all( $content, TOKEN_PARSE );
		} catch ( ParseError ) {
			throw new RuntimeException( 'バックアップの wp-config.php を書き換えられませんでした。' );
		}

		return $content;
	}

	/** コメントの中を誤って拾わないよう、トークン単位で読む */
	private static function config_table_prefix( string $content ): ?string {
		$tokens = token_get_all( $content );
		foreach ( $tokens as $index => $token ) {
			if ( ! is_array( $token ) || T_VARIABLE !== $token[0] || '$table_prefix' !== $token[1] ) {
				continue;
			}
			$equals = self::next_token( $tokens, $index );
			$value  = null !== $equals && '=' === $tokens[ $equals ] ? self::next_token( $tokens, $equals ) : null;
			if ( null !== $value && is_array( $tokens[ $value ] ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $value ][0] ) {
				return trim( $tokens[ $value ][1], '\'"' );
			}
		}
		return null;
	}

	/**
	 * PHP の文字列の中にある古いパスや URL を置き換える
	 *
	 * WP_CONTENT_DIR などが元の環境を指したままだと、別のサイトのファイルを読み書きしてしまう。
	 */
	public static function replace_in_strings( string $content, array $pairs ): string {
		if ( ! $pairs ) {
			return $content;
		}

		$unsafe = (bool) preg_grep( '/[\'"$]/', array_values( $pairs ) );
		$result = '';
		foreach ( token_get_all( $content ) as $token ) {
			if ( ! is_array( $token ) ) {
				$result .= $token;
				continue;
			}
			$text = $token[1];
			if ( in_array( $token[0], array( T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE ), true ) ) {
				$new = SP_Database::replace_text( $text, $pairs );
				if ( $new !== $text && $unsafe ) {
					throw new RuntimeException( 'サイトのパスに使えない文字があるので、wp-config.php を書き換えられません。' );
				}
				$text = $new;
			}
			$result .= $text;
		}
		return $result;
	}

	/**
	 * define() の値を置き換える
	 *
	 * コメントや文字列の中を誤って書き換えず、関数呼び出しの値も丸ごと扱えるよう、トークン単位で読む。
	 */
	public static function replace_define( string $content, string $name, string $value, ?bool &$found = null ): string {
		$found  = false;
		$tokens = token_get_all( $content );
		$result = '';

		$count = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$end = self::find_define_end( $tokens, $i, $name );
			if ( null === $end ) {
				$result .= is_array( $tokens[ $i ] ) ? $tokens[ $i ][1] : $tokens[ $i ];
				continue;
			}
			$result .= "define( '" . $name . "', " . var_export( $value, true ) . ' )'; // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$found   = true;
			$i       = $end;
		}

		return $result;
	}

	/**
	 * $start が define( '$name', ... ) の始まりなら、閉じかっこの位置を返す
	 */
	private static function find_define_end( array $tokens, int $start, string $name ): ?int {
		$token = $tokens[ $start ];
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED ), true ) || 'define' !== strtolower( ltrim( $token[1], '\\' ) ) ) {
			return null;
		}

		$i = self::next_token( $tokens, $start );
		if ( null === $i || '(' !== $tokens[ $i ] ) {
			return null;
		}
		$i = self::next_token( $tokens, $i );
		if ( null === $i || ! is_array( $tokens[ $i ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $i ][0] || trim( $tokens[ $i ][1], '\'"' ) !== $name ) {
			return null;
		}
		$i = self::next_token( $tokens, $i );
		if ( null === $i || ',' !== $tokens[ $i ] ) {
			return null;
		}

		$depth = 0;
		$count = count( $tokens );
		for ( $i++; $i < $count; $i++ ) {
			if ( '(' === $tokens[ $i ] ) {
				++$depth;
			} elseif ( ')' === $tokens[ $i ] ) {
				if ( 0 === $depth ) {
					return $i;
				}
				--$depth;
			}
		}
		return null;
	}

	private static function next_token( array $tokens, int $index ): ?int {
		$count = count( $tokens );
		for ( $i = $index + 1; $i < $count; $i++ ) {
			if ( ! is_array( $tokens[ $i ] ) || ! in_array( $tokens[ $i ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return $i;
			}
		}
		return null;
	}

	/**
	 * 別のディスクへの移動はコピーになるので、途中で足りなくなる前に確かめる
	 */
	private function assert_swap_space( string $staging ): void {
		$live = untrailingslashit( ABSPATH );
		if ( stat( $live )['dev'] === stat( $this->job )['dev'] ) {
			return;
		}

		$live_size     = self::tree_size( $live );
		$incoming_size = self::tree_size( $staging );
		$free_work     = disk_free_space( $this->job );
		$free_live     = disk_free_space( $live );
		if ( ( false !== $free_work && $free_work < $live_size + self::DISK_MARGIN )
			|| ( false !== $free_live && $free_live + $live_size < $incoming_size + self::DISK_MARGIN ) ) {
			throw new RuntimeException( '空き容量が足りないので復元を中止しました。' );
		}
	}

	public static function tree_size( string $dir ): int {
		$size = 0;
		foreach ( self::list_dir( $dir ) as $entry ) {
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				$size += self::tree_size( $path );
			} elseif ( is_file( $path ) && ! is_link( $path ) ) {
				$size += (int) filesize( $path );
			}
		}
		return $size;
	}

	/** @return string[] */
	private static function list_dir( string $dir ): array {
		$entries = scandir( $dir );
		if ( false === $entries ) {
			throw new RuntimeException( 'フォルダを読み取れませんでした: ' . $dir );
		}
		return array_values( array_diff( $entries, array( '.', '..' ) ) );
	}

	/**
	 * 同じデータベースの別サイトのテーブルを上書きしないよう確かめる
	 *
	 * 一時ファイルに書き出すのは今のサイトのテーブルだけなので、それ以外を上書きすると戻せない。
	 */
	private function assert_tables_available( array $tables ): void {
		global $wpdb;

		// 大文字小文字を区別しないサーバーでは、名前の違うテーブルが同じものになる
		$folded = array_map( array( SP_Database::class, 'fold_case' ), $tables );
		if ( 0 !== (int) $wpdb->get_var( 'SELECT @@lower_case_table_names' ) && count( array_unique( $folded ) ) !== count( $tables ) ) {
			throw new RuntimeException( 'バックアップに、このサーバーでは区別できない名前のテーブルが含まれています。' );
		}

		// サーバーによっては大文字小文字を区別しないので、区別せずに比べる
		$others    = array_map( array( SP_Database::class, 'fold_case' ), array_diff( SP_Database::all_tables(), SP_Database::tables() ) );
		$conflicts = array_filter( $tables, static fn( string $table ): bool => in_array( SP_Database::fold_case( $table ), $others, true ) );
		if ( $conflicts ) {
			throw new RuntimeException( '同じデータベースに、バックアップと同じ名前の別のテーブルがあります: ' . implode( ', ', array_slice( $conflicts, 0, 5 ) ) );
		}
	}

	private function restore_database( string $staging, array $manifest, array $new_urls, SP_Progress $tracker ): void {
		$tick = array( $tracker, 'tick' );

		$this->lost_triggers = SP_Database::triggers();
		$tracker->begin( 'rollback', '今のデータベースを一時ファイルに書き出しています', count( SP_Database::tables() ) );
		$this->current_tables = SP_Database::dump( $this->job . '/rollback.sql', $tick );

		$tracker->begin( 'import', 'データベースを復元しています', (int) filesize( $staging . '/' . SP_Backup::DATABASE ) );
		$this->restored_tables  = $manifest['tables'];
		$this->start_install_guard();
		$this->database_touched = true;
		// 今のテーブルが残っていると、外部キーの名前や型がバックアップとぶつかる。失敗したら rollback.sql から戻す
		SP_Database::drop_tables( $this->current_tables );
		SP_Database::import( $staging . '/' . SP_Backup::DATABASE, $manifest['tables'], $tick );

		$kept  = 0;
		$pairs = self::url_pairs( $manifest, $new_urls );
		if ( $pairs ) {
			$tracker->begin( 'rewrite', 'URL を書き換えています' );
			$kept = SP_Database::replace_in_tables( $manifest['tables'], $pairs, $tick );
		}
		$this->reset_restored_state( $manifest['table_prefix'], $new_urls, (bool) $pairs, $kept );
		wp_cache_flush();
	}

	/**
	 * @return array<string, string> 置き換え前 => 置き換え後
	 */
	public static function url_pairs( array $manifest, array $new_urls ): array {
		$pairs = array();
		$add   = static function ( string $old, string $new ) use ( &$pairs ): void {
			$old = untrailingslashit( $old );
			$new = untrailingslashit( $new );
			if ( '' === $old || $old === $new ) {
				return;
			}
			$pairs[ $old ] = $new;
			// JSON の中では / が \/ になっている
			$pairs[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
		};

		foreach ( array( 'home', 'siteurl' ) as $key ) {
			$add( $manifest[ $key ], $new_urls[ $key ] );
			// 本文に http / https が混ざっていても拾えるよう、スキームなしの形も置き換える
			$add( (string) preg_replace( '#^https?:#', '', $manifest[ $key ] ), (string) preg_replace( '#^https?:#', '', $new_urls[ $key ] ) );
		}
		$add( $manifest['abspath'], untrailingslashit( ABSPATH ) );
		$add( $manifest['content_dir'], untrailingslashit( WP_CONTENT_DIR ) );

		return $pairs;
	}

	/** バックアップした瞬間の「実行中」の印などが残っていると、復元後に動かなくなる */
	private function reset_restored_state( string $prefix, array $new_urls, bool $moved, int $kept ): void {
		global $wpdb;

		$options = $prefix . 'options';
		foreach ( $new_urls as $name => $url ) {
			if ( false === $wpdb->update( $options, array( 'option_value' => $url ), array( 'option_name' => $name ) ) ) {
				throw new RuntimeException( 'サイトの設定を書き換えられませんでした: ' . $name );
			}
		}

		// 作業用フォルダの名前は今の環境のものに揃える
		$wpdb->delete( $options, array( 'option_name' => 'sp_work_fallback' ) );
		if ( '' !== $this->work_fallback ) {
			$wpdb->insert(
				$options,
				array(
					'option_name'  => 'sp_work_fallback',
					'option_value' => $this->work_fallback,
					'autoload'     => 'off',
				)
			);
		}

		foreach ( self::stale_options() as $name ) {
			$wpdb->delete( $options, array( 'option_name' => $name ) );
		}

		$notices = array();
		if ( $kept > 0 ) {
			$notices[] = self::kept_notice( $kept );
		}
		if ( $moved ) {
			$stored   = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$options} WHERE option_name = %s", 'sp_settings' ) ); // phpcs:ignore WordPress.DB
			$settings = is_string( $stored ) ? SP_Settings::settings_for_new_environment( $stored ) : null;
			if ( null !== $settings ) {
				$wpdb->update( $options, array( 'option_value' => $settings ), array( 'option_name' => 'sp_settings' ) );
				$notices[] = self::MOVED_NOTICE;
			}
		}
		if ( $notices ) {
			$wpdb->insert(
				$options,
				array(
					'option_name'  => SP_Backup::RESTORE_NOTICE_OPTION,
					'option_value' => implode( ' ', $notices ),
					'autoload'     => 'off',
				)
			);
		}
	}

	private static function kept_notice( int $kept ): string {
		return 'URL を書き換えられなかった値が ' . $kept . ' 件あります(列に入らない長さなど)。元の環境の URL のまま残しています。';
	}

	/** @return string[] バックアップした瞬間の状態を表すだけで、復元後に残すと困るオプション */
	private static function stale_options(): array {
		$names = array( 'sp_pending_download', 'sp_download_offered', 'sp_download_owner', SP_Backup::RESTORE_NOTICE_OPTION, 'sp_last_error', 'sp_skipped_links', 'sp_last_log' );
		foreach ( array( 'cp_manual_running', 'cp_auto_running' ) as $transient ) {
			$names[] = '_transient_' . $transient;
			$names[] = '_transient_timeout_' . $transient;
		}
		return $names;
	}

	/**
	 * サイトの URL のパスが変わるときは、固定リンクの設定も合わせる
	 */
	private function adjust_htaccess( string $staging, array $manifest, array $new_urls ): void {
		$path = $staging . '/files/.htaccess';
		if ( ! is_file( $path ) ) {
			return;
		}

		$base    = static fn( string $url ): string => trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$content = (string) file_get_contents( $path );
		$new     = self::rewrite_htaccess( $content, $base( $manifest['home'] ), $base( $new_urls['home'] ) );
		if ( $new !== $content && false === file_put_contents( $path, $new ) ) {
			throw new RuntimeException( '.htaccess を書き換えられませんでした。' );
		}
	}

	/**
	 * WordPress が管理する範囲の RewriteBase と書き換え先だけを置き換える
	 */
	public static function rewrite_htaccess( string $content, string $old_base, string $new_base ): string {
		if ( $old_base === $new_base ) {
			return $content;
		}

		$old = preg_quote( $old_base, '/' );
		return (string) preg_replace_callback(
			'/# BEGIN WordPress.*?# END WordPress/s',
			static function ( array $block ) use ( $old, $new_base ): string {
				$text = preg_replace_callback(
					'/^(\s*RewriteBase\s+)' . $old . '(\s*)$/m',
					static fn( array $m ): string => $m[1] . $new_base . $m[2],
					$block[0]
				);
				return (string) preg_replace_callback(
					'/^(\s*RewriteRule\s+\S+\s+)' . $old . 'index\.php/m',
					static fn( array $m ): string => $m[1] . $new_base . 'index.php',
					(string) $text
				);
			},
			$content
		);
	}

	/**
	 * 入れ替える前に、展開した SQLite ファイルとドロップインを新しい環境に合わせる
	 */
	private function prepare_sqlite( string $staging, array $manifest, array $new_urls, ?callable $tick ): void {
		$file   = $staging . '/' . $manifest['sqlite_file'];
		$pairs  = self::url_pairs( $manifest, $new_urls );
		$update = $new_urls;
		$insert = '' !== $this->work_fallback ? array( 'sp_work_fallback' => $this->work_fallback ) : array();

		if ( $pairs ) {
			$stored   = SP_Database::read_sqlite_option( $file, $manifest['table_prefix'], 'sp_settings' );
			$settings = null !== $stored ? SP_Settings::settings_for_new_environment( $stored ) : null;
			if ( null !== $settings ) {
				$update['sp_settings']   = $settings;
				$insert[ SP_Backup::RESTORE_NOTICE_OPTION ] = self::MOVED_NOTICE;
			}
		}

		$kept = SP_Database::rewrite_sqlite_file(
			$file,
			$manifest['table_prefix'],
			$pairs,
			$update,
			array_merge( self::stale_options(), array( 'sp_work_fallback' ) ),
			$insert,
			$tick
		);
		if ( $kept > 0 ) {
			SP_Database::set_sqlite_option( $file, $manifest['table_prefix'], SP_Backup::RESTORE_NOTICE_OPTION, trim( self::kept_notice( $kept ) . ' ' . ( $insert[ SP_Backup::RESTORE_NOTICE_OPTION ] ?? '' ) ) );
		}

		// ドロップインには SQLite 実装への絶対パスが書かれている
		$old = untrailingslashit( $manifest['abspath'] );
		$new = untrailingslashit( ABSPATH );
		foreach ( array( 'files/wp-content/db.php', 'content/db.php' ) as $name ) {
			$path = $staging . '/' . $name;
			if ( $old !== $new && is_file( $path ) ) {
				$written = file_put_contents( $path, str_replace( $old, $new, (string) file_get_contents( $path ) ) );
				if ( false === $written ) {
					throw new RuntimeException( 'SQLite の設定を書き換えられませんでした。' );
				}
			}
		}
	}

	private function swap_files( string $staging, array $manifest ): void {
		$rollback = $this->job . '/rollback';
		mkdir( $rollback, 0700 );

		// メンテナンスの印は入れ替えの対象にしない。バックアップに入っていても持ち込まない
		@unlink( $staging . '/files/.maintenance' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		self::align_content_layout( $staging );
		$this->set_aside_incoming_install( $staging );

		$this->swap_tree( untrailingslashit( ABSPATH ), $staging . '/files', $rollback . '/files' );
		if ( is_dir( $staging . '/content' ) ) {
			$this->swap_tree( untrailingslashit( WP_CONTENT_DIR ), $staging . '/content', $rollback . '/content' );
		}
	}

	/**
	 * バックアップと今の環境で wp-content の置き方が違うときは、展開したものを今の環境に合わせる
	 *
	 * そのままだと、中へ戻すときは退避したあとの入れ替え先がなくなり、
	 * サイトの外へのリンクへ戻すときはリンクとぶつかる。
	 */
	private static function align_content_layout( string $staging ): void {
		$root    = trailingslashit( wp_normalize_path( (string) realpath( ABSPATH ) ) );
		$content = wp_normalize_path( (string) realpath( WP_CONTENT_DIR ) );
		$from    = '';
		$to      = '';
		if ( is_dir( $staging . '/content' ) && str_starts_with( $content, $root ) ) {
			$from = $staging . '/content';
			$to   = $staging . '/files/' . substr( $content, strlen( $root ) );
		} elseif ( ! is_dir( $staging . '/content' ) && is_link( ABSPATH . 'wp-content' ) && realpath( ABSPATH . 'wp-content' ) === realpath( WP_CONTENT_DIR ) ) {
			$from = $staging . '/files/wp-content';
			$to   = $staging . '/content';
		}
		if ( '' === $from || ! is_dir( $from ) || file_exists( $to ) ) {
			return;
		}
		if ( ( ! is_dir( dirname( $to ) ) && ! mkdir( dirname( $to ), 0755, true ) ) || ! rename( $from, $to ) ) {
			throw new RuntimeException( 'ファイルの入れ替えに失敗しました: ' . $to );
		}
	}

	private function swap_tree( string $live, string $incoming, string $rollback ): void {
		if ( ! is_dir( $incoming ) ) {
			throw new RuntimeException( 'バックアップにサイトのファイルが含まれていません。' );
		}
		mkdir( $rollback, 0700 );

		// 作業用フォルダがサイトの中にある環境では、それを含むフォルダごとは動かせないので 1 段下りて入れ替える
		// リンクの先には入らない(入るとサイトの外のファイルを動かしてしまう)
		// Windows の realpath は \ を返すので、比べる前に / へ揃える
		$work         = wp_normalize_path( (string) realpath( SP_Settings::work_root() ) );
		$content_real = wp_normalize_path( (string) realpath( WP_CONTENT_DIR ) );
		$guard        = $content_real . '/install.php';
		$descend      = array();
		foreach ( self::list_dir( $live ) as $entry ) {
			$path = $live . '/' . $entry;
			if ( self::maintenance_path() === $path || untrailingslashit( ABSPATH ) . '/' . SP_Backup::CONFIG === $path ) {
				continue;
			}
			$real = wp_normalize_path( (string) realpath( $path ) );
			if ( is_link( $path ) ) {
				// wp-content がサイトの外へのリンクなら、中身は別に入れ替えるのでリンクは残す
				if ( '' !== $real && $real === $content_real ) {
					continue;
				}
			} elseif ( '' !== $real ) {
				if ( $real === $work || $real === $guard ) {
					continue;
				}
				// 入れ替えの間も残すものを含むフォルダは、丸ごと動かさない
				if ( ( '' !== $work && str_starts_with( $work, $real . '/' ) ) || str_starts_with( $guard, $real . '/' ) ) {
					$descend[] = $entry;
					continue;
				}
			}
			self::move( $path, $rollback . '/' . $entry );
			$this->moved_out[] = array( $path, $rollback . '/' . $entry );
		}

		foreach ( $descend as $entry ) {
			if ( ! is_dir( $incoming . '/' . $entry ) && ! mkdir( $incoming . '/' . $entry, 0755 ) ) {
				throw new RuntimeException( 'ファイルの入れ替えに失敗しました: ' . $entry );
			}
			$this->swap_tree( $live . '/' . $entry, $incoming . '/' . $entry, $rollback . '/' . $entry );
		}

		foreach ( self::list_dir( $incoming ) as $entry ) {
			if ( in_array( $entry, $descend, true ) ) {
				continue;
			}
			$target = $live . '/' . $entry;
			if ( file_exists( $target ) || is_link( $target ) ) {
				throw new RuntimeException( 'ファイルの入れ替えに失敗しました: ' . $target );
			}
			$this->placed[] = $target;
			self::move( $incoming . '/' . $entry, $target );
		}
	}

	private function swap_config( string $config ): void {
		$path = SP_Settings::config_path();
		if ( null === $path ) {
			throw new RuntimeException( 'wp-config.php が見つかりません。' );
		}

		$original = (string) file_get_contents( $path );
		$mode     = fileperms( $path ) & 0777;

		// 落ちて残っても中身を読まれないよう、PHP として実行される推測できない名前で、元と同じ権限にしておく
		$part  = dirname( $path ) . '/wp-config-' . bin2hex( random_bytes( 8 ) ) . '.php';
		$umask = umask( 0077 );
		$saved = file_put_contents( $part, $config );
		umask( $umask );
		if ( false === $saved || ! chmod( $part, $mode ) || ! @rename( $part, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new RuntimeException( 'wp-config.php を書き込めませんでした。' );
		}

		$this->original_config      = $original;
		$this->original_config_mode = $mode;
	}

	private function rollback(): bool {
		$ok = true;

		// ファイルで失敗してもデータベースは戻せるよう、別々に試す
		try {
			if ( null !== $this->original_config ) {
				$path = SP_Settings::config_path();
				if ( null === $path || false === file_put_contents( $path, $this->original_config ) ) {
					$ok = false;
				} else {
					chmod( $path, $this->original_config_mode );
				}
			}

			foreach ( array_reverse( $this->placed ) as $path ) {
				self::delete_path( $path );
			}
		} catch ( Throwable ) {
			$ok = false;
		}

		// 1 つ戻せなくても、残りは戻す
		foreach ( array_reverse( $this->moved_out ) as [ $original, $saved ] ) {
			try {
				self::move( $saved, $original );
			} catch ( Throwable ) {
				$ok = false;
			}
		}

		try {
			if ( $this->database_touched ) {
				global $wpdb;
				// 接続が切れたまま問い合わせると wp_die で止まり、残りの片付けができない
				if ( ! $wpdb->check_connection( false ) ) {
					throw new RuntimeException( 'データベースに接続できません。' );
				}
				SP_Database::drop_tables( $this->restored_tables );
				SP_Database::import( $this->job . '/rollback.sql', $this->current_tables, self::$heartbeat );
			}
		} catch ( Throwable ) {
			$ok = false;
		}

		self::reset_opcache();
		$this->end_maintenance();

		// 戻せなかったときは、閉じたままにする
		if ( $ok ) {
			SP_Settings::remove_install_guard();
			SP_Settings::remove_directory( $this->job );
		}
		return $ok;
	}

	/** 同じディスクなら rename で一瞬で動かし、別のディスクならコピーして消す */
	private static function move( string $from, string $to ): void {
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return;
		}
		try {
			self::copy_path( $from, $to );
		} catch ( RuntimeException $e ) {
			self::delete_path( $to );
			throw $e;
		}
		self::delete_path( $from );
	}

	private static function copy_path( string $from, string $to ): void {
		if ( is_link( $from ) ) {
			if ( ! symlink( (string) readlink( $from ), $to ) ) {
				throw new RuntimeException( 'ファイルの入れ替えに失敗しました: ' . $to );
			}
			return;
		}
		if ( is_dir( $from ) ) {
			if ( ! is_dir( $to ) && ! mkdir( $to, 0755, true ) ) {
				throw new RuntimeException( 'ファイルの入れ替えに失敗しました: ' . $to );
			}
			foreach ( self::list_dir( $from ) as $entry ) {
				self::copy_path( $from . '/' . $entry, $to . '/' . $entry );
			}
			chmod( $to, fileperms( $from ) & 0777 );
			return;
		}
		if ( ! @copy( $from, $to ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			throw new RuntimeException( 'ファイルの入れ替えに失敗しました: ' . $to );
		}
		chmod( $to, fileperms( $from ) & 0777 );
		if ( self::$heartbeat ) {
			( self::$heartbeat )();
		}
	}

	private static function delete_path( string $path ): void {
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			SP_Settings::remove_directory( $path );
		} elseif ( file_exists( $path ) || is_link( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}
}
