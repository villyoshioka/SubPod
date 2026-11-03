<?php
/**
 * バックアップファイルの暗号化
 *
 * ファイルごとにランダムなソルトで鍵を作り、固定サイズの塊ごとに AES-256-GCM で暗号化する。
 * 鍵はパスワードだけから導くので、元の環境がなくなってもパスワードがあれば復号できる。
 * 塊の順番と「最後の塊か」も認証対象に含めて、入れ替えや切り詰めを検出する。
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Crypto {
	private const string MAGIC = "SPENC1\n";
	private const string CIPHER = 'aes-256-gcm';
	private const int CHUNK = 1048576;
	private const int SALT_LENGTH = 16;
	private const int TAG_LENGTH = 16;
	private const int ITERATIONS = 600000;

	public static function is_encrypted( string $path ): bool {
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return false;
		}
		$head = fread( $handle, strlen( self::MAGIC ) );
		fclose( $handle );
		return self::MAGIC === $head;
	}

	public static function encrypt_file( string $source, string $target, string $password, ?callable $tick = null ): void {
		$in  = fopen( $source, 'rb' );
		$out = fopen( $target, 'xb' );
		if ( false === $in || false === $out ) {
			throw new RuntimeException( '暗号化ファイルを作成できませんでした。' );
		}

		$salt = random_bytes( self::SALT_LENGTH );
		$key  = self::derive_key( $password, $salt );
		self::write( $out, self::MAGIC . $salt );

		$remaining = (int) filesize( $source );
		$counter   = 0;
		do {
			$plain = (string) fread( $in, min( self::CHUNK, max( 1, $remaining ) ) );
			if ( '' === $plain && $remaining > 0 ) {
				throw new RuntimeException( '暗号化するファイルを読み取れませんでした。' );
			}
			$remaining -= strlen( $plain );
			$final      = $remaining <= 0;

			$tag    = '';
			$cipher = openssl_encrypt( $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, self::nonce( $counter ), $tag, self::aad( $counter, $final ), self::TAG_LENGTH );
			if ( false === $cipher ) {
				throw new RuntimeException( '暗号化に失敗しました。' );
			}
			self::write( $out, pack( 'N', strlen( $cipher ) ) . $tag . $cipher );
			++$counter;
			if ( $tick ) {
				$tick( strlen( $plain ) );
			}
		} while ( ! $final );

		fclose( $in );
		fclose( $out );
	}

	public static function decrypt_file( string $source, string $target, string $password, ?callable $tick = null ): void {
		$in  = fopen( $source, 'rb' );
		$out = fopen( $target, 'xb' );
		if ( false === $in || false === $out ) {
			throw new RuntimeException( '暗号化ファイルを読み取れませんでした。' );
		}

		$size = (int) filesize( $source );
		if ( fread( $in, strlen( self::MAGIC ) ) !== self::MAGIC ) {
			throw new RuntimeException( '暗号化ファイルの形式が正しくありません。' );
		}
		$salt = (string) fread( $in, self::SALT_LENGTH );
		$key  = self::derive_key( $password, $salt );

		$counter = 0;
		do {
			$head = fread( $in, 4 + self::TAG_LENGTH );
			if ( false === $head || strlen( $head ) !== 4 + self::TAG_LENGTH ) {
				throw new RuntimeException( '暗号化ファイルが途中で切れているか、壊れています。' );
			}
			$length = unpack( 'N', substr( $head, 0, 4 ) )[1];
			if ( $length > self::CHUNK ) {
				throw new RuntimeException( '暗号化ファイルの形式が正しくありません。' );
			}
			$cipher = $length > 0 ? (string) fread( $in, $length ) : '';
			$final  = ftell( $in ) >= $size;
			$plain  = openssl_decrypt( $cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, self::nonce( $counter ), substr( $head, 4 ), self::aad( $counter, $final ) );
			if ( false === $plain ) {
				$message = 0 === $counter ? 'パスワードが正しくないか、ファイルが壊れています。' : '暗号化ファイルが途中で切れているか、壊れています。';
				throw new RuntimeException( $message );
			}
			self::write( $out, $plain );
			++$counter;
			if ( $tick ) {
				$tick( $length );
			}
		} while ( ! $final );

		fclose( $in );
		fclose( $out );
	}

	private static function derive_key( string $password, string $salt ): string {
		return hash_pbkdf2( 'sha256', $password, $salt, self::ITERATIONS, 32, true );
	}

	/** ファイルごとに鍵が変わるので、塊の番号をそのまま nonce にできる */
	private static function nonce( int $counter ): string {
		return "\0\0\0\0" . pack( 'J', $counter );
	}

	private static function aad( int $counter, bool $final ): string {
		return pack( 'J', $counter ) . ( $final ? "\1" : "\0" );
	}

	/** @param resource $handle */
	private static function write( $handle, string $data ): void {
		if ( fwrite( $handle, $data ) !== strlen( $data ) ) {
			throw new RuntimeException( '書き込みに失敗しました。空き容量を確認してください。' );
		}
	}
}
