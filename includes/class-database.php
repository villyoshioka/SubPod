<?php
/**
 * データベースの書き出し・読み込み・URL 置き換え
 *
 * ダンプは「長さ付きの 1 文」を並べた独自形式にして、復元時に文の区切りを探さなくて済むようにする。
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Database {
	private const int BATCH_ROWS = 500;
	private const int MAX_STATEMENT = 1048576;

	/** サイトの外のファイルや別のサーバーを読み書きしないストレージエンジン */
	private const array SAFE_ENGINES = array( 'innodb', 'myisam', 'aria', 'memory', 'heap', 'archive', 'csv', 'blackhole', 'rocksdb' );

	/** mysql コマンドでも読み込めるよう、必要な設定を先頭に書く */
	private const string DUMP_HEADER = "SET NAMES utf8mb4;\nSET time_zone = '+00:00';\nSET foreign_key_checks = 0;\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n";

	/** 読み込むときの sql_mode。ANSI_QUOTES や NO_BACKSLASH_ESCAPES が入っていると文の読み方が変わる */
	private const string DUMP_SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

	/** 値を書き込めない自動計算の列。MySQL 8 の DEFAULT_GENERATED(CURRENT_TIMESTAMP などの初期値)は普通の列 */
	private const string GENERATED_COLUMN = '/\b(?:VIRTUAL|STORED|PERSISTENT)\b/i';

	/** SQLite では DB が wp-content 内のファイルなので、ファイルとして一緒にバックアップされる */
	public static function is_sqlite(): bool {
		return ( defined( 'DB_ENGINE' ) && 'sqlite' === DB_ENGINE ) || defined( 'SQLITE_DB_DROPIN_VERSION' );
	}

	/**
	 * @return string[] データベースにあるすべてのテーブル名
	 */
	public static function all_tables(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SHOW FULL TABLES WHERE Table_type = %s', 'BASE TABLE' ),
			ARRAY_N
		);
		if ( ! is_array( $rows ) || '' !== $wpdb->last_error ) {
			throw new RuntimeException( 'テーブルの一覧を取得できませんでした。' );
		}
		return array_column( $rows, 0 );
	}

	/**
	 * このサイトのテーブル名を返す
	 *
	 * 接頭辞が前方一致するだけの別サイト(wp_ に対する wp_site2_ など)は、
	 * WordPress の主要テーブルが揃っているかで見分けて除く。
	 *
	 * @return string[]
	 */
	public static function tables(): array {
		global $wpdb;

		$all    = self::all_tables();
		$others = array();
		foreach ( $all as $table ) {
			if ( str_starts_with( $table, $wpdb->prefix ) && str_ends_with( $table, 'options' ) ) {
				$other = substr( $table, 0, -strlen( 'options' ) );
				if ( $other !== $wpdb->prefix && in_array( $other . 'posts', $all, true ) && in_array( $other . 'users', $all, true ) ) {
					$others[] = $other;
				}
			}
		}

		$tables = array();
		foreach ( $all as $table ) {
			if ( ! str_starts_with( $table, $wpdb->prefix ) ) {
				continue;
			}
			foreach ( $others as $other ) {
				if ( str_starts_with( $table, $other ) ) {
					continue 2;
				}
			}
			if ( ! self::is_valid_table_name( $table ) ) {
				throw new RuntimeException( '復元できない名前のテーブルがあります: ' . $table );
			}
			$tables[] = $table;
		}

		if ( ! $tables ) {
			throw new RuntimeException( 'このサイトのテーブルが見つかりませんでした。' );
		}
		return $tables;
	}

	/**
	 * バックアップに入らないデータベースの機能の名前を返す
	 *
	 * 作成者などの環境に依存する情報を含むので、別の環境へ戻せる形にできない。
	 *
	 * @return string[]
	 */
	public static function unsupported_objects(): array {
		global $wpdb;

		$names = array();
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW FULL TABLES WHERE Table_type = %s', 'VIEW' ) ) as $view ) {
			if ( str_starts_with( $view, $wpdb->prefix ) ) {
				$names[] = 'ビュー ' . $view;
			}
		}
		foreach ( self::triggers() as $trigger ) {
			$names[] = 'トリガー ' . $trigger;
		}
		foreach ( (array) $wpdb->get_col( 'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()' ) as $routine ) {
			$names[] = 'ルーチン ' . $routine;
		}
		return $names;
	}

	/**
	 * このサイトのテーブルに付いているトリガーの名前を返す
	 *
	 * テーブルを作り直すと一緒に消えて、バックアップからも戻せない。
	 *
	 * @return string[]
	 */
	public static function triggers(): array {
		global $wpdb;
		return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
	}

	/** 大文字小文字を区別しないサーバーと同じように名前を比べるための形にする */
	public static function fold_case( string $name ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name );
	}

	public static function is_valid_table_name( string $name ): bool {
		return 1 === preg_match( '/^[^`\/\\\\.\x00]+$/', $name );
	}

	/**
	 * @return string[] 書き出したテーブル名
	 */
	public static function dump( string $path, ?callable $tick = null ): array {
		global $wpdb;

		$out = fopen( $path, 'xb' );
		if ( false === $out ) {
			throw new RuntimeException( 'データベースの書き出し先を作成できませんでした。' );
		}

		// 読み込み側と同じ文字コードで書き出す。別の文字コードの接続だと、復元時にデータが欠ける
		$wpdb->set_charset( $wpdb->dbh, 'utf8mb4' );
		$time_zone = self::use_utc();
		$sql_mode  = self::use_sql_mode( self::DUMP_SQL_MODE );
		try {
			$connection = $wpdb->get_var( 'SELECT CONNECTION_ID()' );

			// 書き出しの途中でサイトが更新されても、すべてのテーブルが同じ時点の内容になるようにする
			$wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
			if ( false === $wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' ) ) {
				throw new RuntimeException( 'データベースの書き出しを始められませんでした。' );
			}

			if ( fwrite( $out, self::DUMP_HEADER ) !== strlen( self::DUMP_HEADER ) ) {
				throw new RuntimeException( 'データベースの書き出しに失敗しました。空き容量を確認してください。' );
			}

			$tables = self::tables();
			foreach ( $tables as $table ) {
				$quoted = self::quote_name( $table );
				$create = $wpdb->get_row( "SHOW CREATE TABLE {$quoted}", ARRAY_N ); // phpcs:ignore WordPress.DB
				if ( empty( $create[1] ) ) {
					throw new RuntimeException( 'テーブル定義を取得できませんでした: ' . $table );
				}
				// 取れても戻せないテーブルは、バックアップが必要になってからでは手遅れなので今止める
				if ( null === self::statement_table( $create[1] ) || ! self::references_only( $create[1], $tables ) ) {
					throw new RuntimeException( '復元できない形式のテーブルがあるので中止しました: ' . $table );
				}
				self::write_statement( $out, "DROP TABLE IF EXISTS {$quoted}" );
				self::write_statement( $out, $create[1] );
				self::dump_rows( $out, $table, $tick );
				if ( $tick ) {
					$tick( 1 );
				}
			}

			// 途中で再接続されると、同じ時点の内容という前提も文字コードの設定も失われている
			if ( $wpdb->get_var( 'SELECT CONNECTION_ID()' ) !== $connection ) {
				throw new RuntimeException( 'データベースの書き出し中に接続が切れました。' );
			}
		} finally {
			$wpdb->query( 'COMMIT' );
			fclose( $out );
			self::use_sql_mode( $sql_mode );
			self::restore_time_zone( $time_zone );
			$wpdb->set_charset( $wpdb->dbh );
		}

		// 書き出せても読み込めない大きさなら、必要になってからでは手遅れなので今止める
		self::assert_fits_packet( $path );

		return $tables;
	}

	/**
	 * ダンプを読み込む
	 *
	 * 読み違いで別のテーブルを壊したり、細工されたファイルがデータベースの権限でサイトの外に触ったり
	 * しないよう、manifest に載っているテーブルへの、ダンプが書き出す形の命令だけを通す。
	 *
	 * @param string[] $tables 許可するテーブル名
	 */
	public static function import( string $path, array $tables, ?callable $tick = null ): void {
		global $wpdb;

		self::assert_fits_packet( $path );

		$in = fopen( $path, 'rb' );
		if ( false === $in ) {
			throw new RuntimeException( 'データベースのバックアップを読み取れませんでした。' );
		}
		if ( fread( $in, strlen( self::DUMP_HEADER ) ) !== self::DUMP_HEADER ) {
			throw new RuntimeException( 'データベースのバックアップの形式が正しくありません。' );
		}

		$sql_mode = self::use_sql_mode( self::DUMP_SQL_MODE );
		$wpdb->query( 'SET SESSION foreign_key_checks = 0' );
		$wpdb->set_charset( $wpdb->dbh, 'utf8mb4' );
		$time_zone = self::use_utc();

		$allowed = array_flip( $tables );
		try {
			while ( false !== ( $line = fgets( $in ) ) ) {
				if ( ! preg_match( '/^-- SP:(\d+)\n$/', $line, $match ) ) {
					throw new RuntimeException( 'データベースのバックアップの形式が正しくありません。' );
				}
				$length = (int) $match[1];
				$sql    = $length > 0 ? (string) stream_get_contents( $in, $length ) : '';
				if ( strlen( $sql ) !== $length || ";\n" !== fread( $in, 2 ) ) {
					throw new RuntimeException( 'データベースのバックアップが途中で切れています。' );
				}
				$table = self::statement_table( $sql );
				if ( null === $table || ! isset( $allowed[ $table ] ) || ! self::references_only( $sql, $tables ) ) {
					throw new RuntimeException( 'データベースのバックアップに受け付けられない命令が含まれています。' );
				}
				// wpdb::query() は非 ASCII の文を文字コード検査で書き換えたり拒否したりするので通さない
				if ( ! mysqli_query( $wpdb->dbh, $sql ) && ! self::retry_with_known_collation( $sql ) ) {
					throw new RuntimeException( 'データベースの復元に失敗しました: ' . $table . '(' . mysqli_error( $wpdb->dbh ) . ')' );
				}
				if ( $tick ) {
					$tick( $length );
				}
			}
		} finally {
			fclose( $in );
			self::use_sql_mode( $sql_mode );
			self::restore_time_zone( $time_zone );
			$wpdb->set_charset( $wpdb->dbh );
			$wpdb->query( 'SET SESSION foreign_key_checks = 1' );
		}
		wp_cache_flush();
	}

	/**
	 * 新しいサーバーにしかない照合順序なら、どのサーバーにもあるものに置き換えて読み込む
	 *
	 * MySQL 8 や新しい MariaDB から、別のサーバーの環境へ移すときに起きる。
	 */
	private static function retry_with_known_collation( string $sql ): bool {
		global $wpdb;

		if ( 1273 !== mysqli_errno( $wpdb->dbh ) || ! str_starts_with( $sql, 'CREATE' ) ) {
			return false;
		}
		// 区別の細かい照合順序は、一意の制約で値がぶつからないよう _bin にする
		$known = preg_replace_callback(
			'/\b(utf8mb[34])_(?:[a-z]+_)*(?:0900|uca1400)_(\w+)/',
			static fn( array $m ): string => $m[1] . ( 'ai_ci' === $m[2] ? '_unicode_520_ci' : '_bin' ),
			$sql
		);
		return (bool) mysqli_query( $wpdb->dbh, (string) $known );
	}

	/**
	 * @return string 元の sql_mode
	 */
	private static function use_sql_mode( string $mode ): string {
		global $wpdb;
		$current = (string) $wpdb->get_var( 'SELECT @@session.sql_mode' );
		$wpdb->query( $wpdb->prepare( 'SET SESSION sql_mode = %s', $mode ) );
		return $current;
	}

	/**
	 * TIMESTAMP 列が環境の時差でずれないよう、書き出しと読み込みを UTC に揃える
	 *
	 * @return string 元のタイムゾーン
	 */
	private static function use_utc(): string {
		global $wpdb;
		$time_zone = (string) $wpdb->get_var( 'SELECT @@session.time_zone' );
		$wpdb->query( "SET SESSION time_zone = '+00:00'" );
		return $time_zone;
	}

	private static function restore_time_zone( string $time_zone ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SET SESSION time_zone = %s', $time_zone ) );
	}

	/**
	 * ダンプの中で一番長い命令が、サーバーの上限に収まるか確かめる
	 */
	private static function assert_fits_packet( string $path ): void {
		global $wpdb;

		$limit = (int) $wpdb->get_var( 'SELECT @@max_allowed_packet' );
		$in    = fopen( $path, 'rb' );
		if ( false === $in ) {
			throw new RuntimeException( 'データベースのバックアップを読み取れませんでした。' );
		}

		$longest = 0;
		fseek( $in, strlen( self::DUMP_HEADER ) );
		while ( false !== ( $line = fgets( $in ) ) ) {
			if ( ! preg_match( '/^-- SP:(\d+)\n$/', $line, $match ) ) {
				fclose( $in );
				throw new RuntimeException( 'データベースのバックアップの形式が正しくありません。' );
			}
			$longest = max( $longest, (int) $match[1] );
			fseek( $in, (int) $match[1] + 2, SEEK_CUR );
		}
		fclose( $in );

		if ( $limit > 0 && $longest >= $limit ) {
			throw new RuntimeException( 'データベースに大きすぎる行があります。MySQL の max_allowed_packet を増やしてください。' );
		}
	}

	/**
	 * ダンプが書き出す形の命令なら対象のテーブル名を返す
	 */
	public static function statement_table( string $sql ): ?string {
		if ( ! preg_match( '/^(?:DROP TABLE IF EXISTS `([^`]+)`$|CREATE TABLE `([^`]+)` \(|INSERT INTO `([^`]+)` \()/', $sql, $match ) ) {
			return null;
		}
		$table = (string) end( $match );
		if ( str_starts_with( $sql, 'DROP' ) ) {
			return $table;
		}

		$code = self::strip_literals( $sql );
		if ( null === $code ) {
			return null;
		}
		$safe = str_starts_with( $sql, 'CREATE' ) ? self::is_safe_create( $code ) : self::is_safe_insert( $code );
		return $safe ? $table : null;
	}

	/**
	 * 外部キーの参照先が、一緒に復元するテーブルだけか確かめる
	 *
	 * 別のデータベースや別のサイトのテーブルを参照されると、そちらの削除や変更を邪魔してしまう。
	 *
	 * @param string[] $tables
	 */
	private static function references_only( string $sql, array $tables ): bool {
		if ( ! str_starts_with( $sql, 'CREATE' ) ) {
			return true;
		}
		$masked = self::strip_literals( $sql );
		$named  = self::strip_literals( $sql, true );
		if ( null === $masked || null === $named ) {
			return false;
		}
		// references という名前の列を外部キーと読み違えないよう、引用符付きの名前の中は見ない
		$count = preg_match_all( '/\bREFERENCES\b/i', $masked );
		preg_match_all( '/`[^`]*`(*SKIP)(*F)|\bREFERENCES\s*`([^`]+)`(?!`|\s*\.)/i', $named, $targets );
		return count( $targets[1] ) === $count && ! array_diff( array_map( array( self::class, 'fold_case' ), $targets[1] ), array_map( array( self::class, 'fold_case' ), $tables ) );
	}

	/** サイトの外や別のテーブルを読み書きできる書き方を通さない */
	private static function is_safe_create( string $code ): bool {
		$body = substr( $code, strlen( 'CREATE TABLE' ) );
		if ( preg_match( '/\b(?:SELECT|TABLE|UNION|DIRECTORY|CONNECTION|LOAD_FILE)\b/i', $body ) ) {
			return false;
		}
		preg_match_all( '/\bENGINE\b\s*=?\s*([^\s,)]*)/i', $body, $engines );
		return ! array_diff( array_map( 'strtolower', $engines[1] ), self::SAFE_ENGINES );
	}

	/** 値は文字列、16 進数、NULL だけ。ダンプはこれ以外を書かないので、関数や副問い合わせは通らない */
	private static function is_safe_insert( string $code ): bool {
		if ( ! preg_match( '/^INSERT INTO \?+ \(\?+(?:, \?+)*\) VALUES /', $code, $head ) ) {
			return false;
		}
		$values = preg_replace( '/0x[0-9a-f]++|NULL/', '?', substr( $code, strlen( $head[0] ) ) );
		return null !== $values && strspn( $values, '(),?' ) === strlen( $values );
	}

	/**
	 * 文字列と引用符付きの名前を ? に置き換え、コメントを外す
	 *
	 * バージョン付きコメント(SHOW CREATE TABLE が書き出す)は実行されることがあるので、中身を残す。
	 * MySQL と読み方がずれうる形は null を返す。
	 *
	 * @param bool $keep_names 引用符付きの名前は残す
	 */
	private static function strip_literals( string $sql, bool $keep_names = false ): ?string {
		$result  = '';
		$length  = strlen( $sql );
		$offset  = 0;
		$comment = false;
		while ( $offset < $length ) {
			$plain   = strcspn( $sql, "'\"`/*#-", $offset );
			$result .= substr( $sql, $offset, $plain );
			$offset += $plain;
			if ( $offset >= $length ) {
				break;
			}

			$char = $sql[ $offset ];
			$next = $sql[ $offset + 1 ] ?? '';
			if ( '#' === $char || ( '-' === $char && '-' === $next ) ) {
				return null;
			}
			if ( '*' === $char && '/' === $next ) {
				$comment = false;
				$result .= ' ';
				$offset += 2;
			} elseif ( '/' === $char && '*' === $next ) {
				if ( $comment ) {
					return null;
				}
				if ( preg_match( '/\G\/\*M?!\d*/', $sql, $open, 0, $offset ) ) {
					$comment = true;
					$offset += strlen( $open[0] );
				} else {
					$end = strpos( $sql, '*/', $offset + 2 );
					if ( false === $end ) {
						return null;
					}
					$offset = $end + 2;
				}
				$result .= ' ';
			} elseif ( '\'' === $char || '"' === $char || '`' === $char ) {
				$end = self::literal_end( $sql, $offset );
				// サーバーのバージョンによっては、コメントの中の引用符は引用符として働かない
				if ( null === $end || ( $comment && str_contains( substr( $sql, $offset, $end - $offset ), '*/' ) ) ) {
					return null;
				}
				$result .= $keep_names && '`' === $char ? substr( $sql, $offset, $end + 1 - $offset ) : '?';
				$offset  = $end + 1;
			} else {
				$result .= $char;
				++$offset;
			}
		}
		return $comment ? null : $result;
	}

	/** 文字列や名前を閉じる引用符の位置を返す。読み込み時の sql_mode では \ がエスケープになる */
	private static function literal_end( string $sql, int $start ): ?int {
		$quote  = $sql[ $start ];
		$stops  = '`' === $quote ? '`' : $quote . '\\';
		$length = strlen( $sql );
		$offset = $start + 1;
		while ( $offset < $length ) {
			$offset += strcspn( $sql, $stops, $offset );
			if ( $offset >= $length ) {
				return null;
			}
			if ( '\\' !== $sql[ $offset ] ) {
				return $offset;
			}
			$offset += 2;
		}
		return null;
	}

	public static function drop_tables( array $tables ): void {
		global $wpdb;
		$wpdb->query( 'SET SESSION foreign_key_checks = 0' );
		foreach ( $tables as $table ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::quote_name( $table ) ); // phpcs:ignore WordPress.DB
		}
		$wpdb->query( 'SET SESSION foreign_key_checks = 1' );
	}

	/**
	 * テーブル内の文字列を置き換える(シリアライズ済みの値は長さも直す)
	 *
	 * @param string[]              $tables
	 * @param array<string, string> $pairs  置き換え前 => 置き換え後
	 * @return int 書き換えられずに元のまま残した値の数
	 */
	public static function replace_in_tables( array $tables, array $pairs, ?callable $tick = null ): int {
		global $wpdb;

		// 接続の文字コードで表せない文字が ? に化けて書き戻されないよう、utf8mb4 のまま読み書きする
		$wpdb->set_charset( $wpdb->dbh, 'utf8mb4' );
		// 長くなった値が黙って切り詰められて壊れないよう、入らないときはエラーにする
		$sql_mode = self::use_sql_mode( 'STRICT_ALL_TABLES,NO_AUTO_VALUE_ON_ZERO' );
		// 外部キーの列に URL が入っていても、途中の状態で止まらないようにする
		$wpdb->query( 'SET SESSION foreign_key_checks = 0' );
		$kept = 0;
		try {
			foreach ( $tables as $table ) {
				$quoted  = self::quote_name( $table );
				$columns = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$quoted}", ARRAY_A ); // phpcs:ignore WordPress.DB
				$keys    = array();
				$texts   = array();
				$stamped = array();
				foreach ( $columns as $column ) {
					if ( 'PRI' === $column['Key'] ) {
						$keys[] = $column['Field'];
					}
					if ( false !== stripos( (string) $column['Extra'], 'on update' ) ) {
						$stamped[] = $column['Field'];
					}
					if ( preg_match( '/char|text|json/i', $column['Type'] ) && 'guid' !== $column['Field'] && ! preg_match( self::GENERATED_COLUMN, (string) $column['Extra'] ) ) {
						$texts[] = $column['Field'];
					}
				}
				foreach ( $texts as $text ) {
					$kept += self::replace_in_column( $table, $keys, $stamped, $text, $pairs, $tick );
				}
			}
		} finally {
			$wpdb->query( 'SET SESSION foreign_key_checks = 1' );
			self::use_sql_mode( $sql_mode );
			$wpdb->set_charset( $wpdb->dbh );
		}
		return $kept;
	}

	/**
	 * 文字列の中の URL やパスを置き換える
	 *
	 * http://localhost が http://localhost:8080 に、/wordpress が //wordpress.org に
	 * 効かないよう、前後が区切りになっているものだけを置き換える。
	 * 置き換えた結果をもう一度置き換えることはない。
	 */
	public static function replace_text( string $text, array $pairs ): string {
		static $patterns = array();

		$id = md5( (string) json_encode( $pairs ) );
		if ( ! isset( $patterns[ $id ] ) ) {
			$keys = array_map( 'strval', array_keys( $pairs ) );
			usort( $keys, static fn( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) );

			$parts = array();
			foreach ( $keys as $key ) {
				$is_path = preg_match( '#^(?:/|\\\\/)#', $key ) && ! preg_match( '#^(?://|\\\\/\\\\/)#', $key );
				$parts[] = ( $is_path ? '(?<![\\w/\\\\.:-])' : '' ) . preg_quote( $key, '~' );
			}
			$patterns[ $id ] = '~(?:' . implode( '|', $parts ) . ')(?![\\w-]|\\.\\w|:\\d)~';
		}

		$result = preg_replace_callback(
			$patterns[ $id ],
			static fn( array $match ): string => $pairs[ $match[0] ],
			$text
		);
		if ( null === $result ) {
			throw new RuntimeException( 'URL の書き換えに失敗しました。' );
		}
		return $result;
	}

	/**
	 * データベースファイルの整合性が取れたコピーを作る
	 *
	 * 動いているファイルをそのままコピーすると、書き込みの途中の状態を取ってしまう。
	 */
	public static function snapshot_sqlite( string $source, string $target ): void {
		try {
			$pdo = new PDO( 'sqlite:' . $source, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$pdo->exec( 'VACUUM INTO ' . $pdo->quote( $target ) );
		} catch ( PDOException $e ) {
			throw new RuntimeException( 'データベースファイルをコピーできませんでした: ' . $e->getMessage() );
		}
	}

	/**
	 * 入れ替える前の SQLite ファイルを書き換える
	 *
	 * 動いているサイトの接続は今のファイルを掴んだままなので、展開したファイルを直接開く。
	 *
	 * @param array<string, string> $pairs  置き換え前 => 置き換え後
	 * @param array<string, string> $update 値を書き換えるオプション
	 * @param string[]              $delete 消すオプション
	 * @param array<string, string> $insert 作り直すオプション
	 * @param callable|null         $tick   長い処理の途中で定期的に呼ぶ
	 * @return int 書き換えられずに元のまま残した値の数
	 */
	public static function rewrite_sqlite_file( string $path, string $prefix, array $pairs, array $update, array $delete, array $insert, ?callable $tick = null ): int {
		$options = self::quote_sqlite( $prefix . 'options' );
		$kept    = 0;

		try {
			$pdo = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$pdo->beginTransaction();

			$tables = $pdo->query( "SELECT name FROM sqlite_master WHERE type = 'table'" )->fetchAll( PDO::FETCH_COLUMN );
			foreach ( $tables as $table ) {
				if ( $pairs && str_starts_with( $table, $prefix ) ) {
					$kept += self::replace_in_sqlite_table( $pdo, $table, $pairs, $tick );
				}
			}

			$statement = $pdo->prepare( "UPDATE {$options} SET option_value = ? WHERE option_name = ?" );
			foreach ( $update as $name => $value ) {
				$statement->execute( array( $value, $name ) );
			}
			$statement = $pdo->prepare( "DELETE FROM {$options} WHERE option_name = ?" );
			foreach ( array_merge( $delete, array_keys( $insert ) ) as $name ) {
				$statement->execute( array( $name ) );
			}
			$statement = $pdo->prepare( "INSERT INTO {$options} (option_name, option_value, autoload) VALUES (?, ?, 'off')" );
			foreach ( $insert as $name => $value ) {
				$statement->execute( array( $name, $value ) );
			}

			// 書き換えが効いていないのに成功と報告しないよう、読み直して確かめる
			$statement = $pdo->prepare( "SELECT option_value FROM {$options} WHERE option_name = ?" );
			foreach ( $update as $name => $value ) {
				$statement->execute( array( $name ) );
				if ( $statement->fetchColumn() !== $value ) {
					throw new RuntimeException( 'サイトの設定を書き換えられませんでした: ' . $name );
				}
			}

			$pdo->commit();
		} catch ( PDOException $e ) {
			throw new RuntimeException( 'データベースファイルを書き換えられませんでした: ' . $e->getMessage() );
		}
		return $kept;
	}

	/** @return int 書き換えられずに元のまま残した値の数 */
	private static function replace_in_sqlite_table( PDO $pdo, string $table, array $pairs, ?callable $tick ): int {
		$kept   = 0;
		$quoted = self::quote_sqlite( $table );
		foreach ( $pdo->query( "PRAGMA table_info({$quoted})" )->fetchAll( PDO::FETCH_ASSOC ) as $column ) {
			if ( 'guid' === $column['name'] || preg_match( '/int|real|float|double|numeric|decimal|bool|blob|date|time/i', $column['type'] ) ) {
				continue;
			}

			$col   = self::quote_sqlite( $column['name'] );
			$where = implode( ' OR ', array_fill( 0, count( $pairs ), "instr({$col}, ?) > 0" ) );
			$find  = $pdo->prepare( "SELECT rowid FROM {$quoted} WHERE {$where}" );
			$find->execute( array_keys( $pairs ) );
			$rowids = $find->fetchAll( PDO::FETCH_COLUMN );

			$read  = $pdo->prepare( "SELECT {$col} FROM {$quoted} WHERE rowid = ?" );
			$write = $pdo->prepare( "UPDATE {$quoted} SET {$col} = ? WHERE rowid = ?" );
			foreach ( $rowids as $rowid ) {
				$read->execute( array( $rowid ) );
				$old = (string) $read->fetchColumn();
				$new = self::replace_value( $old, $pairs );
				if ( $new !== $old ) {
					try {
						$write->execute( array( $new, $rowid ) );
					} catch ( PDOException $e ) {
						// 一意の値とぶつかったら、壊さないよう元のまま残す
						if ( '23000' !== (string) $e->getCode() ) {
							throw $e;
						}
						++$kept;
						// 失敗した文は使い回せないので作り直す
						$write = $pdo->prepare( "UPDATE {$quoted} SET {$col} = ? WHERE rowid = ?" );
					}
				}
				if ( $tick ) {
					$tick();
				}
			}
		}
		return $kept;
	}

	/** 値を置き換える。なければ作る */
	public static function set_sqlite_option( string $path, string $prefix, string $name, string $value ): void {
		$options = self::quote_sqlite( $prefix . 'options' );
		try {
			$pdo = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$pdo->prepare( "DELETE FROM {$options} WHERE option_name = ?" )->execute( array( $name ) );
			$pdo->prepare( "INSERT INTO {$options} (option_name, option_value, autoload) VALUES (?, ?, 'off')" )->execute( array( $name, $value ) );
		} catch ( PDOException $e ) {
			throw new RuntimeException( 'データベースファイルを書き換えられませんでした: ' . $e->getMessage() );
		}
	}

	public static function read_sqlite_option( string $path, string $prefix, string $name ): ?string {
		try {
			$pdo       = new PDO( 'sqlite:' . $path, null, null, array( PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION ) );
			$statement = $pdo->prepare( 'SELECT option_value FROM ' . self::quote_sqlite( $prefix . 'options' ) . ' WHERE option_name = ?' );
			$statement->execute( array( $name ) );
			$value = $statement->fetchColumn();
			return is_string( $value ) ? $value : null;
		} catch ( PDOException $e ) {
			throw new RuntimeException( 'データベースファイルを読み取れませんでした: ' . $e->getMessage() );
		}
	}

	private static function quote_sqlite( string $name ): string {
		return '"' . str_replace( '"', '""', $name ) . '"';
	}

	/**
	 * シリアライズ構造を保ったまま文字列を置き換える
	 *
	 * 復元ファイル由来の値なので unserialize() はせず(オブジェクトを生成しないため)、
	 * 文字列要素だけを書式どおりに読んで長さを直す。
	 */
	public static function replace_value( string $value, array $pairs ): string {
		if ( is_serialized( $value ) ) {
			$replaced = self::replace_serialized( $value, $pairs );
			if ( null !== $replaced ) {
				return $replaced;
			}
		}
		return self::replace_text( $value, $pairs );
	}

	private static function replace_serialized( string $value, array $pairs ): ?string {
		$result = '';
		$offset = 0;
		$length = strlen( $value );

		while ( $offset < $length ) {
			if ( ! preg_match( '/s:(\d+):"/', $value, $match, PREG_OFFSET_CAPTURE, $offset ) ) {
				return $result . substr( $value, $offset );
			}
			$start = $match[0][1];

			// 文字列要素の開始位置は、値の先頭か区切り文字の直後だけ
			if ( $start > 0 && ! in_array( $value[ $start - 1 ], array( ';', '{', '}' ), true ) ) {
				$result .= substr( $value, $offset, $start + 2 - $offset );
				$offset  = $start + 2;
				continue;
			}

			$size  = (int) $match[1][0];
			$begin = $start + strlen( $match[0][0] );
			if ( $begin + $size + 2 > $length || '";' !== substr( $value, $begin + $size, 2 ) ) {
				return null;
			}

			$new     = self::replace_value( substr( $value, $begin, $size ), $pairs );
			$result .= substr( $value, $offset, $start - $offset ) . 's:' . strlen( $new ) . ':"' . $new . '";';
			$offset  = $begin + $size + 2;
		}

		return $result;
	}

	/**
	 * wpdb の文字コード検査は不正な文字を含む行を拒否したり書き換えたりするので、読み込みと同じく通さない
	 *
	 * @param string[] $keys 主キーの列。なければ値のハッシュを手がかりにして、同じ値の行をまとめて書き換える
	 * @return int 書き換えられずに元のまま残した値の数
	 */
	private static function replace_in_column( string $table, array $keys, array $stamped, string $column, array $pairs, ?callable $tick ): int {
		global $wpdb;

		$quoted = self::quote_name( $table );
		$col    = self::quote_name( $column );
		// 値そのものだと、列の文字コードによっては一致せず、大きい値は文の上限も超える
		$hash     = "SHA2(CONVERT({$col} USING utf8mb4), 256)";
		$key_list = $keys ? implode( ', ', array_map( array( self::class, 'quote_name' ), $keys ) ) : "DISTINCT {$hash} AS value_hash";

		$conditions = array();
		foreach ( array_keys( $pairs ) as $search ) {
			// latin1 などの列でも、日本語を含むパスと比べられるようにする
			$conditions[] = "CONVERT({$col} USING utf8mb4) LIKE " . self::quote_value( '%' . $wpdb->esc_like( $search ) . '%' );
		}
		$where = implode( ' OR ', $conditions );

		// 書き換えで ON UPDATE CURRENT_TIMESTAMP の列が今の時刻に変わらないよう、今の値を指定する
		$keep = '';
		foreach ( $stamped as $name ) {
			$keep .= ', ' . self::quote_name( $name ) . ' = ' . self::quote_name( $name );
		}

		$kept = 0;

		// 本文が大きい行でメモリを使い切らないよう、先にキーだけ取ってから 1 行ずつ処理する
		$names = $keys ? array_map( array( self::class, 'quote_name' ), $keys ) : array( $hash );
		$found = self::read_matches( "SELECT {$key_list} FROM {$quoted} WHERE {$where}", $names, $table );
		if ( ! $keys ) {
			$found = self::order_by_value( $found, "SELECT {$col} FROM {$quoted} WHERE ", $column, $table, $pairs );
		}
		foreach ( $found as $match ) {
			$row   = self::read_rows( "SELECT {$col} FROM {$quoted} WHERE {$match} LIMIT 1", $table )[0] ?? null;
			if ( null === $row || null === $row[ $column ] ) {
				continue;
			}
			$old = (string) $row[ $column ];
			$new = self::replace_value( $old, $pairs );
			if ( $new === $old ) {
				continue;
			}
			if ( ! mysqli_query( $wpdb->dbh, "UPDATE {$quoted} SET {$col} = " . self::quote_value( $new ) . "{$keep} WHERE {$match}" ) ) {
				// 列に入らない長さ(1406)、一意の値とのぶつかり(1062)、列の文字コードで表せない文字(1366)、
				// 選択肢にない値(1265)は、壊さないよう元のまま残す
				if ( in_array( mysqli_errno( $wpdb->dbh ), array( 1406, 1062, 1366, 1265 ), true ) ) {
					++$kept;
					continue;
				}
				throw new RuntimeException( 'URL の書き換えに失敗しました: ' . $table . '(' . mysqli_error( $wpdb->dbh ) . ')' );
			}
			if ( $tick ) {
				$tick();
			}
		}
		return $kept;
	}

	/**
	 * 値で書き換える順番を決める
	 *
	 * 書き換えた結果が、別の書き換え待ちの値と同じになると、次の書き換えで一緒に二重に変わってしまう。
	 * そうならないよう、結果と同じ値を持つほうを先に処理する。
	 *
	 * @param string[] $found 値のハッシュでの WHERE 条件
	 * @return string[] 処理する順に並べた $found
	 */
	private static function order_by_value( array $found, string $select, string $column, string $table, array $pairs ): array {
		$next = array();
		foreach ( $found as $match ) {
			$row = self::read_rows( $select . $match . ' LIMIT 1', $table )[0] ?? null;

			$next[ $match ] = substr( $match, 0, -66 ) . self::quote_value( hash( 'sha256', self::replace_value( (string) ( $row[ $column ] ?? '' ), $pairs ) ) );
		}

		$ordered = array();
		while ( $next ) {
			$ready = array_filter(
				$next,
				static fn( string $new, string $old ): bool => $new === $old || ! isset( $next[ $new ] ),
				ARRAY_FILTER_USE_BOTH
			);
			foreach ( array_keys( $ready ?: $next ) as $old ) {
				$ordered[] = (string) $old;
				unset( $next[ $old ] );
			}
		}
		return $ordered;
	}

	/**
	 * 見つかった行を指す WHERE 条件を返す
	 *
	 * 行の数が多いと連想配列のままではメモリが足りなくなるので、1 行ずつ短い文字列にする。
	 *
	 * @param string[] $names 取得する列の順に、比べる式
	 * @return string[]
	 */
	private static function read_matches( string $sql, array $names, string $table ): array {
		global $wpdb;
		$result = mysqli_query( $wpdb->dbh, $sql );
		if ( ! $result instanceof mysqli_result ) {
			throw new RuntimeException( 'URL の書き換えに失敗しました: ' . $table . '(' . mysqli_error( $wpdb->dbh ) . ')' );
		}
		$matches = array();
		while ( $row = $result->fetch_row() ) {
			$parts = array();
			foreach ( $row as $index => $value ) {
				$parts[] = $names[ $index ] . ' = ' . self::quote_value( (string) $value );
			}
			$matches[] = implode( ' AND ', $parts );
		}
		$result->free();
		return $matches;
	}

	/** @return array<int, array<string, ?string>> */
	private static function read_rows( string $sql, string $table ): array {
		global $wpdb;
		$result = mysqli_query( $wpdb->dbh, $sql );
		if ( ! $result instanceof mysqli_result ) {
			throw new RuntimeException( 'URL の書き換えに失敗しました: ' . $table . '(' . mysqli_error( $wpdb->dbh ) . ')' );
		}
		return $result->fetch_all( MYSQLI_ASSOC );
	}

	private static function quote_value( string $value ): string {
		global $wpdb;
		return "'" . mysqli_real_escape_string( $wpdb->dbh, $value ) . "'";
	}

	/** @param resource $out */
	private static function dump_rows( $out, string $table, ?callable $tick ): void {
		global $wpdb;

		$quoted  = self::quote_name( $table );
		$columns = (array) $wpdb->get_results( "SHOW COLUMNS FROM {$quoted}", ARRAY_A ); // phpcs:ignore WordPress.DB
		$names   = array();
		$selects = array();
		$kinds   = array();
		$primary = array();
		foreach ( $columns as $column ) {
			if ( preg_match( self::GENERATED_COLUMN, (string) $column['Extra'] ) ) {
				continue;
			}
			$name    = self::quote_name( $column['Field'] );
			$names[] = $name;
			// BIT は取得すると 10 進数の文字列になるので、16 進数で受け取る
			$is_bit    = (bool) preg_match( '/^bit/i', $column['Type'] );
			$selects[] = $is_bit ? "HEX({$name})" : $name;
			$kinds[]   = $is_bit ? 'hex' : ( preg_match( '/binary|blob|geometry|point|linestring|polygon/i', $column['Type'] ) ? 'binary' : 'text' );
			if ( 'PRI' === $column['Key'] ) {
				$primary[] = self::quote_name( $column['Field'] );
			}
		}

		$list   = implode( ', ', $names );
		$select = implode( ', ', $selects );
		$order  = $primary ? ' ORDER BY ' . implode( ', ', $primary ) : '';
		$head   = "INSERT INTO {$quoted} ({$list}) VALUES ";

		// 大きい行が多くてもメモリに抱え込まないよう、まとめて受け取らずに 1 行ずつ読む。
		// 読み終えるまで同じ接続で別の問い合わせはできないので、$tick は DB に触らない前提(進捗の送信だけ)
		$result = mysqli_query( $wpdb->dbh, "SELECT {$select} FROM {$quoted}{$order}", MYSQLI_USE_RESULT );
		if ( ! $result instanceof mysqli_result ) {
			throw new RuntimeException( 'データベースの読み取りに失敗しました: ' . $table );
		}
		try {
			$values = '';
			$count  = 0;
			while ( $row = $result->fetch_row() ) {
				$encoded = '(' . implode( ',', array_map( array( self::class, 'encode_value' ), $row, $kinds ) ) . ')';
				if ( '' !== $values && strlen( $values ) + strlen( $encoded ) > self::MAX_STATEMENT ) {
					self::write_statement( $out, $head . $values );
					$values = '';
				}
				$values .= ( '' === $values ? '' : ',' ) . $encoded;
				if ( 0 === ++$count % self::BATCH_ROWS && $tick ) {
					$tick();
				}
			}
			// 途中で読み取りが失敗しても、終わりと同じように抜けてくる
			if ( mysqli_errno( $wpdb->dbh ) ) {
				throw new RuntimeException( 'データベースの読み取りに失敗しました: ' . $table );
			}
			if ( '' !== $values ) {
				self::write_statement( $out, $head . $values );
			}
		} finally {
			$result->free();
		}
	}

	/** @param string $kind text / binary / hex(すでに 16 進数の文字列) */
	private static function encode_value( ?string $value, string $kind ): string {
		global $wpdb;

		if ( null === $value ) {
			return 'NULL';
		}
		if ( 'hex' === $kind ) {
			return '0x' . strtolower( $value );
		}
		if ( 'binary' === $kind ) {
			return '' === $value ? "''" : '0x' . bin2hex( $value );
		}
		return "'" . $wpdb->remove_placeholder_escape( esc_sql( $value ) ) . "'";
	}

	/** @param resource $out */
	private static function write_statement( $out, string $sql ): void {
		$record = '-- SP:' . strlen( $sql ) . "\n" . $sql . ";\n";
		if ( fwrite( $out, $record ) !== strlen( $record ) ) {
			throw new RuntimeException( 'データベースの書き出しに失敗しました。空き容量を確認してください。' );
		}
	}

	private static function quote_name( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
}
