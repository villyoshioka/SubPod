<?php
/**
 * Cloudflare R2 へのアップロード(S3 互換 API のマルチパートアップロード)
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_R2 {
	private const int MIN_PART = 16777216;
	private const int MAX_PARTS = 9000;
	private const int RETRIES = 3;

	/** @var callable|null 長い処理の途中で定期的に呼ぶ */
	private $tick = null;

	public function __construct(
		private readonly string $account_id,
		private readonly string $bucket,
		private readonly string $access_key,
		private readonly string $secret_key
	) {
		// 接続先の組み立てに使うので、保存時の検査を通っていない値(復元したデータベースなど)でも確かめる
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $account_id ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D', $bucket ) || ! preg_match( '/^[A-Za-z0-9]+$/D', $access_key ) ) {
			throw new RuntimeException( 'R2 の設定が正しくありません。設定画面でもう一度入力してください。' );
		}
	}

	public function upload( string $path, string $key, ?callable $tick = null ): void {
		$this->tick = $tick;

		$size      = (int) filesize( $path );
		$part_size = max( self::MIN_PART, (int) ceil( $size / self::MAX_PARTS ) );

		$created = $this->request( 'POST', $key, array( 'uploads' => '' ) );
		if ( ! preg_match( '#<UploadId>([^<]+)</UploadId>#', $created['body'], $match ) ) {
			throw new RuntimeException( 'R2 へのアップロードを開始できませんでした。' );
		}
		$upload_id = html_entity_decode( $match[1], ENT_XML1 );

		try {
			$handle = fopen( $path, 'rb' );
			if ( false === $handle ) {
				throw new RuntimeException( 'R2 へ送るファイルを読み取れませんでした。' );
			}

			$parts  = '';
			$number = 1;
			$sent   = 0;
			while ( ! feof( $handle ) ) {
				$data = (string) fread( $handle, $part_size );
				if ( '' === $data ) {
					break;
				}
				$response = $this->request_with_retry(
					'PUT',
					$key,
					array(
						'partNumber' => (string) $number,
						'uploadId'   => $upload_id,
					),
					$data
				);
				$etag = trim( (string) wp_remote_retrieve_header( $response['raw'], 'etag' ) );
				if ( '' === $etag ) {
					throw new RuntimeException( 'R2 からの応答が正しくありません。' );
				}
				$parts .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>' . esc_xml( $etag ) . '</ETag></Part>';
				++$number;
				$sent += strlen( $data );
				if ( $tick ) {
					$tick( strlen( $data ) );
				}
			}
			fclose( $handle );

			// 読み取りが途中で止まっても、切れたファイルを完成させない
			if ( $sent !== $size ) {
				throw new RuntimeException( 'R2 へ送るファイルを最後まで読み取れませんでした。' );
			}

			try {
				$this->request_with_retry(
					'POST',
					$key,
					array( 'uploadId' => $upload_id ),
					'<CompleteMultipartUpload>' . $parts . '</CompleteMultipartUpload>'
				);
			} catch ( RuntimeException $e ) {
				// 応答だけが届かなかったときは、再試行が「もうない」と返す。届いたファイルを見て判断する
				if ( ! str_contains( $e->getMessage(), 'NoSuchUpload' ) || ! $this->is_uploaded( $key, $size ) ) {
					throw $e;
				}
			}
		} catch ( RuntimeException $e ) {
			try {
				$this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ) );
			} catch ( RuntimeException ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// 取り消しに失敗しても、元の失敗理由を伝える
			}
			throw $e;
		}
	}

	private function is_uploaded( string $key, int $size ): bool {
		try {
			$response = $this->request( 'HEAD', $key, array() );
		} catch ( RuntimeException ) {
			return false;
		}
		return (int) wp_remote_retrieve_header( $response['raw'], 'content-length' ) === $size;
	}

	private function request_with_retry( string $method, string $key, array $query, string $body ): array {
		$attempt = 0;
		while ( true ) {
			try {
				return $this->request( $method, $key, $query, $body );
			} catch ( RuntimeException $e ) {
				if ( ++$attempt >= self::RETRIES ) {
					throw $e;
				}
			}
		}
	}

	/**
	 * @return array{body: string, raw: array}
	 */
	private function request( string $method, string $key, array $query, string $body = '' ): array {
		$host = $this->account_id . '.r2.cloudflarestorage.com';
		$path = '/' . rawurlencode( $this->bucket ) . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );

		ksort( $query );
		$canonical_query = '';
		foreach ( $query as $name => $value ) {
			$canonical_query .= ( '' === $canonical_query ? '' : '&' ) . rawurlencode( $name ) . '=' . rawurlencode( $value );
		}

		$headers = self::sign( $method, $host, $path, $canonical_query, hash( 'sha256', $body ), $this->access_key, $this->secret_key, gmdate( 'Ymd\THis\Z' ) );

		// 1 片を送っている間も、無応答で打ち切られないように進み具合を返す
		$keep_alive = function ( $handle ): void {
			curl_setopt( $handle, CURLOPT_NOPROGRESS, false );
			curl_setopt(
				$handle,
				CURLOPT_XFERINFOFUNCTION,
				function (): int {
					( $this->tick )();
					return 0;
				}
			);
		};
		if ( $this->tick ) {
			add_action( 'http_api_curl', $keep_alive );
		}

		$response = wp_remote_request(
			'https://' . $host . $path . ( '' === $canonical_query ? '' : '?' . $canonical_query ),
			array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => $body,
				'timeout'     => 300,
				'redirection' => 0,
				'sslverify'   => true,
			)
		);

		remove_action( 'http_api_curl', $keep_alive );

		if ( is_wp_error( $response ) ) {
			throw new RuntimeException( 'R2 に接続できませんでした。' );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$text   = (string) wp_remote_retrieve_body( $response );
		// S3 の完了の応答は、失敗でも 200 で本文にエラーを入れて返すことがある
		if ( $status < 200 || $status >= 300 || str_contains( $text, '<Error>' ) ) {
			$code = preg_match( '#<Code>([A-Za-z0-9]+)</Code>#', $text, $match ) ? $match[1] : (string) $status;
			throw new RuntimeException( 'R2 へのアップロードに失敗しました: ' . $code );
		}

		return array(
			'body' => $text,
			'raw'  => $response,
		);
	}

	/**
	 * AWS Signature Version 4 のヘッダーを作る
	 *
	 * @return array<string, string>
	 */
	public static function sign( string $method, string $host, string $path, string $query, string $payload_hash, string $access_key, string $secret_key, string $timestamp ): array {
		$date    = substr( $timestamp, 0, 8 );
		$scope   = $date . '/auto/s3/aws4_request';
		$signed  = 'host;x-amz-content-sha256;x-amz-date';
		$request = implode(
			"\n",
			array(
				$method,
				$path,
				$query,
				'host:' . $host,
				'x-amz-content-sha256:' . $payload_hash,
				'x-amz-date:' . $timestamp,
				'',
				$signed,
				$payload_hash,
			)
		);
		$to_sign = "AWS4-HMAC-SHA256\n{$timestamp}\n{$scope}\n" . hash( 'sha256', $request );

		$key = 'AWS4' . $secret_key;
		foreach ( array( $date, 'auto', 's3', 'aws4_request' ) as $part ) {
			$key = hash_hmac( 'sha256', $part, $key, true );
		}
		$signature = hash_hmac( 'sha256', $to_sign, $key );

		return array(
			'Authorization'        => "AWS4-HMAC-SHA256 Credential={$access_key}/{$scope}, SignedHeaders={$signed}, Signature={$signature}",
			'x-amz-content-sha256' => $payload_hash,
			'x-amz-date'           => $timestamp,
		);
	}
}
