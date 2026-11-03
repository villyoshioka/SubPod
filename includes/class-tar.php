<?php
/**
 * .tar.gz の読み書き
 *
 * 大きいファイルでもメモリに載せないよう、すべてストリームで処理する。
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Tar_Writer {
	private const int BLOCK = 512;
	private const int CHUNK = 1048576;
	private const int MAX_OCTAL_SIZE = 8589934591;

	/** @var resource */
	private $gz;

	/** @var callable|null */
	private $tick;

	private HashContext $hash;

	public function __construct( string $path, ?callable $tick = null ) {
		$gz = gzopen( $path, 'wb9' );
		if ( false === $gz ) {
			throw new RuntimeException( '圧縮ファイルを作成できませんでした。' );
		}
		$this->gz   = $gz;
		$this->tick = $tick;
		$this->hash = hash_init( 'sha256' );
	}

	/** add_file() で追加した名前と中身の確認値。復元時に壊れていないか確かめるのに使う */
	public function content_hash(): string {
		return hash_final( hash_copy( $this->hash ) );
	}

	public function add_string( string $name, string $content ): void {
		$this->write_header( $name, strlen( $content ), 0644, time() );
		$this->write( $content );
		$this->write_padding( strlen( $content ) );
	}

	/**
	 * ファイルを追加する
	 *
	 * 読み取り中にサイズが変わってもヘッダーと食い違わないよう、ヘッダーのサイズぴったりで書く。
	 *
	 * @return bool 追加できたら true。処理中に消えたファイルは false。
	 */
	public function add_file( string $name, string $path ): bool {
		$handle = @fopen( $path, 'rb' );
		if ( false === $handle ) {
			if ( ! file_exists( $path ) ) {
				return false;
			}
			throw new RuntimeException( 'ファイルを読み取れませんでした: ' . $path );
		}

		$size = (int) filesize( $path );
		$this->write_header( $name, $size, fileperms( $path ) & 0777, (int) filemtime( $path ) );
		hash_update( $this->hash, $name . "\0" . $size . "\0" );

		$remaining = $size;
		while ( $remaining > 0 && ! feof( $handle ) ) {
			$data = fread( $handle, min( self::CHUNK, $remaining ) );
			if ( false === $data || '' === $data ) {
				break;
			}
			$this->write( $data );
			hash_update( $this->hash, $data );
			$remaining -= strlen( $data );
			if ( $this->tick ) {
				( $this->tick )( strlen( $data ) );
			}
		}
		fclose( $handle );

		while ( $remaining > 0 ) {
			$pad = str_repeat( "\0", min( self::CHUNK, $remaining ) );
			$this->write( $pad );
			hash_update( $this->hash, $pad );
			$remaining -= strlen( $pad );
		}
		$this->write_padding( $size );

		return true;
	}

	/** 空のフォルダを追加する */
	public function add_directory( string $name, string $path ): void {
		$name .= '/';
		$this->write_header( $name, 0, fileperms( $path ) & 0777, (int) filemtime( $path ), '5' );
		hash_update( $this->hash, $name . "\0" );
	}

	public function close(): void {
		$this->write( str_repeat( "\0", self::BLOCK * 2 ) );
		gzclose( $this->gz );
	}

	/**
	 * 書き終えたファイルを読み直して、終端まであるか確かめる
	 *
	 * 最後の書き出しでディスクが足りなくなっても、close では失敗を検出できない。
	 */
	public static function verify_complete( string $path, ?callable $tick = null ): void {
		$gz = gzopen( $path, 'rb' );
		if ( false === $gz ) {
			throw new RuntimeException( '圧縮ファイルを読み直せませんでした。' );
		}

		$size   = self::BLOCK * 2;
		$tail   = '';
		$crc    = hash_init( 'crc32b' );
		$length = 0;
		while ( ! gzeof( $gz ) ) {
			$data = gzread( $gz, self::CHUNK );
			if ( false === $data || '' === $data ) {
				break;
			}
			$tail = substr( $tail . $data, -$size );
			hash_update( $crc, $data );
			$length += strlen( $data );
			if ( $tick ) {
				$tick( strlen( $data ) );
			}
		}
		gzclose( $gz );

		// 中身が読めても末尾の確認値が欠けていることがあるので、gzip の末尾の 8 バイトまで突き合わせる
		$handle  = fopen( $path, 'rb' );
		$trailer = '';
		if ( false !== $handle ) {
			fseek( $handle, -8, SEEK_END );
			$trailer = (string) fread( $handle, 8 );
			fclose( $handle );
		}
		if ( str_repeat( "\0", $size ) !== $tail || pack( 'V2', hexdec( hash_final( $crc ) ), $length & 0xFFFFFFFF ) !== $trailer ) {
			throw new RuntimeException( '圧縮ファイルを最後まで書き込めませんでした。空き容量を確認してください。' );
		}
	}

	private function write( string $data ): void {
		if ( gzwrite( $this->gz, $data ) !== strlen( $data ) ) {
			throw new RuntimeException( '圧縮ファイルの書き込みに失敗しました。空き容量を確認してください。' );
		}
	}

	private function write_padding( int $size ): void {
		$rest = $size % self::BLOCK;
		if ( $rest ) {
			$this->write( str_repeat( "\0", self::BLOCK - $rest ) );
		}
	}

	private function write_header( string $name, int $size, int $mode, int $mtime, string $type = '0' ): void {
		$pax = '';
		if ( strlen( $name ) > 100 ) {
			$pax .= self::pax_record( 'path', $name );
		}
		if ( $size > self::MAX_OCTAL_SIZE ) {
			$pax .= self::pax_record( 'size', (string) $size );
		}
		if ( '' !== $pax ) {
			$this->write( self::header( 'PaxHeader/entry', strlen( $pax ), 0644, $mtime, 'x' ) );
			$this->write( $pax );
			$this->write_padding( strlen( $pax ) );
		}

		$short = strlen( $name ) > 100 ? substr( hash( 'sha256', $name ), 0, 40 ) : $name;
		$this->write( self::header( $short, min( $size, self::MAX_OCTAL_SIZE ), $mode, $mtime, $type ) );
	}

	private static function pax_record( string $key, string $value ): string {
		$body   = ' ' . $key . '=' . $value . "\n";
		$length = strlen( $body );
		$digits = strlen( (string) $length );
		while ( strlen( (string) ( $length + $digits ) ) !== $digits ) {
			++$digits;
		}
		return ( $length + $digits ) . $body;
	}

	private static function header( string $name, int $size, int $mode, int $mtime, string $type ): string {
		$block = pack(
			'a100a8a8a8a12a12A8a1a100a6a2a32a32a8a8a155a12',
			$name,
			sprintf( '%07o', $mode ),
			'0000000',
			'0000000',
			sprintf( '%011o', $size ),
			sprintf( '%011o', max( 0, $mtime ) ),
			'',
			$type,
			'',
			'ustar',
			'00',
			'',
			'',
			'',
			'',
			'',
			''
		);

		// チェックサム欄は空白で埋まった状態で計算する
		$sum = 0;
		for ( $i = 0; $i < self::BLOCK; $i++ ) {
			$sum += ord( $block[ $i ] );
		}

		return substr_replace( $block, sprintf( "%06o\0 ", $sum ), 148, 8 );
	}
}

class SP_Tar_Reader {
	private const int BLOCK = 512;
	private const int CHUNK = 1048576;

	/** @var resource */
	private $gz;

	/** @var callable|null */
	private $tick;

	private HashContext $hash;

	/**
	 * @param string $unhashed 確認値に含めないエントリの名前
	 */
	public function __construct( string $path, ?callable $tick = null, private readonly string $unhashed = '' ) {
		$gz = gzopen( $path, 'rb' );
		if ( false === $gz ) {
			throw new RuntimeException( '圧縮ファイルを開けませんでした。' );
		}
		$this->gz   = $gz;
		$this->tick = $tick;
		$this->hash = hash_init( 'sha256' );
	}

	public function content_hash(): string {
		return hash_final( hash_copy( $this->hash ) );
	}

	/**
	 * すべてのエントリを展開先へ書き出す
	 *
	 * エントリはファイルとフォルダだけを受け付け、リンクなど他の種類が含まれていたら中断する。
	 * サイトの外を指す名前の扱いは $destination が決める。
	 *
	 * @param callable $destination fn( string $name, int $size ): string 書き出し先のパスを返す。フォルダの $size は -1
	 * @return bool 終端まで正しく読めたら true
	 */
	public function extract( callable $destination ): bool {
		$pax = array();

		while ( true ) {
			$block = $this->read_exact( self::BLOCK );
			if ( null === $block ) {
				return false;
			}
			if ( str_repeat( "\0", self::BLOCK ) === $block ) {
				return true;
			}

			$header = self::parse_header( $block );
			$size   = $pax['size'] ?? $header['size'];
			if ( $size < 0 ) {
				throw new RuntimeException( '圧縮ファイルの形式が正しくありません。' );
			}

			if ( 'x' === $header['type'] ) {
				if ( $header['size'] > self::CHUNK ) {
					throw new RuntimeException( '圧縮ファイルの形式が正しくありません。' );
				}
				$data = $this->read_exact( $header['size'] );
				$this->skip_padding( $header['size'] );
				$pax = self::parse_pax( (string) $data );
				continue;
			}

			if ( '0' !== $header['type'] && "\0" !== $header['type'] && '5' !== $header['type'] ) {
				throw new RuntimeException( '受け付けられない種類のデータが含まれています。' );
			}

			$name = $pax['path'] ?? $header['name'];
			$pax  = array();

			if ( '5' === $header['type'] ) {
				if ( 0 !== $size ) {
					throw new RuntimeException( '圧縮ファイルの形式が正しくありません。' );
				}
				hash_update( $this->hash, $name . "\0" );
				$dir = $destination( rtrim( $name, '/' ), -1 );
				if ( ! is_dir( $dir ) && ! mkdir( $dir, ( $header['mode'] & 0755 ) ?: 0755, true ) && ! is_dir( $dir ) ) {
					throw new RuntimeException( '展開先を作成できませんでした。' );
				}
				continue;
			}

			$hashed = $name !== $this->unhashed;
			if ( $hashed ) {
				hash_update( $this->hash, $name . "\0" . $size . "\0" );
			}
			$this->extract_file( $destination( $name, $size ), $size, $header['mode'], $hashed );
			$this->skip_padding( $size );
		}
	}

	public function close(): void {
		gzclose( $this->gz );
	}

	/**
	 * 展開後の大きさの目安を返す
	 *
	 * gzip の末尾に記録された大きさは 4GB で一周するので、圧縮後より小さければ圧縮後の大きさを使う。
	 */
	public static function estimate_size( string $path ): int {
		$size   = (int) filesize( $path );
		$handle = fopen( $path, 'rb' );
		if ( false === $handle || $size < 4 ) {
			return $size;
		}
		fseek( $handle, -4, SEEK_END );
		$tail = (string) fread( $handle, 4 );
		fclose( $handle );
		return strlen( $tail ) === 4 ? max( $size, (int) unpack( 'V', $tail )[1] ) : $size;
	}

	private function extract_file( string $path, int $size, int $mode, bool $hashed ): void {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) {
			throw new RuntimeException( '展開先を作成できませんでした。' );
		}

		$out = fopen( $path, 'xb' );
		if ( false === $out ) {
			throw new RuntimeException( 'ファイルを展開できませんでした。' );
		}

		$remaining = $size;
		while ( $remaining > 0 ) {
			$data = gzread( $this->gz, min( self::CHUNK, $remaining ) );
			if ( false === $data || '' === $data ) {
				fclose( $out );
				throw new RuntimeException( '圧縮ファイルが途中で切れているか、壊れています。' );
			}
			if ( fwrite( $out, $data ) !== strlen( $data ) ) {
				fclose( $out );
				throw new RuntimeException( 'ファイルを展開できませんでした。空き容量を確認してください。' );
			}
			if ( $hashed ) {
				hash_update( $this->hash, $data );
			}
			$remaining -= strlen( $data );
			if ( $this->tick ) {
				( $this->tick )( strlen( $data ) );
			}
		}
		fclose( $out );
		chmod( $path, ( $mode & 0755 ) ?: 0644 );
	}

	private function read_exact( int $length ): ?string {
		$data = '';
		while ( strlen( $data ) < $length ) {
			$part = gzread( $this->gz, $length - strlen( $data ) );
			if ( false === $part || '' === $part ) {
				return null;
			}
			$data .= $part;
		}
		return $data;
	}

	private function skip_padding( int $size ): void {
		$rest = $size % self::BLOCK;
		if ( $rest && null === $this->read_exact( self::BLOCK - $rest ) ) {
			throw new RuntimeException( '圧縮ファイルが途中で切れているか、壊れています。' );
		}
	}

	private static function parse_header( string $block ): array {
		$sum = 0;
		for ( $i = 0; $i < self::BLOCK; $i++ ) {
			$sum += ( $i >= 148 && $i < 156 ) ? 32 : ord( $block[ $i ] );
		}
		if ( $sum !== (int) octdec( trim( substr( $block, 148, 8 ), " \0" ) ) ) {
			throw new RuntimeException( '圧縮ファイルの形式が正しくありません。' );
		}

		$name   = rtrim( substr( $block, 0, 100 ), "\0" );
		$prefix = rtrim( substr( $block, 345, 155 ), "\0" );

		return array(
			'name' => '' !== $prefix ? $prefix . '/' . $name : $name,
			'mode' => (int) octdec( trim( substr( $block, 100, 8 ), " \0" ) ),
			'size' => (int) octdec( trim( substr( $block, 124, 12 ), " \0" ) ),
			'type' => $block[156],
		);
	}

	private static function parse_pax( string $data ): array {
		$records = array();
		$offset  = 0;
		while ( $offset < strlen( $data ) ) {
			$space = strpos( $data, ' ', $offset );
			if ( false === $space ) {
				throw new RuntimeException( '圧縮ファイルの形式が正しくありません。' );
			}
			$length = (int) substr( $data, $offset, $space - $offset );
			if ( $length <= $space - $offset + 1 ) {
				throw new RuntimeException( '圧縮ファイルの形式が正しくありません。' );
			}
			$record = substr( $data, $space + 1, $length - ( $space - $offset ) - 2 );
			$parts  = explode( '=', $record, 2 );
			if ( 2 === count( $parts ) ) {
				$records[ $parts[0] ] = 'size' === $parts[0] ? (int) $parts[1] : $parts[1];
			}
			$offset += $length;
		}
		return $records;
	}
}
