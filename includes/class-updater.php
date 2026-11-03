<?php
/**
 * 自動更新クラス
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Updater {
	private static ?self $instance = null;

	private readonly string $github_owner;
	private readonly string $github_repo;
	private readonly string $plugin_basename;
	private readonly string $plugin_slug;
	private readonly string $current_version;
	private readonly string $cache_key;
	private readonly int $cache_expiry;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->github_owner    = 'villyoshioka';
		$this->github_repo     = 'SubPod';
		$this->plugin_basename = SP_PLUGIN_BASENAME;
		$this->plugin_slug     = dirname( $this->plugin_basename );
		$this->current_version = SP_VERSION;
		$this->cache_key       = 'sp_github_release_cache';
		$this->cache_expiry    = 43200;

		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'on_upgrade_complete' ], 10, 2 );
		add_action( 'admin_notices', [ $this, 'show_update_notice' ] );
	}

	/**
	 * 新バージョン公開のお知らせを管理画面に表示
	 *
	 * メジャーバージョンが異なり自動更新が提供されないケースを含め、
	 * 新しいリリースを検出したら GitHub リリースページへのリンクを案内する。
	 */
	public function show_update_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'sub-pod' !== $page ) {
			return;
		}

		$release = $this->get_latest_release();
		if ( ! $release ) {
			return;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );
		if ( version_compare( $this->current_version, $latest_version, '>=' ) ) {
			return;
		}

		if ( ! $this->is_valid_github_url( $release['html_url'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p>Sub Pod の新しいバージョン v%s が公開されています（現在 v%s）。<a href="%s" target="_blank" rel="noopener noreferrer">GitHub でリリースを見る →</a></p></div>',
			esc_html( $latest_version ),
			esc_html( $this->current_version ),
			esc_url( $release['html_url'] )
		);
	}

	/**
	 * プラグイン更新完了時にキャッシュをクリア
	 *
	 * @param \WP_Upgrader $upgrader アップグレーダーインスタンス
	 * @param array        $options  更新オプション
	 */
	public function on_upgrade_complete( \WP_Upgrader $upgrader, array $options ): void {
		if ( $options['action'] !== 'update' || $options['type'] !== 'plugin' ) {
			return;
		}

		$plugins = $options['plugins'] ?? [];
		if ( ! is_array( $plugins ) ) {
			$plugins = [ $plugins ];
		}

		if ( in_array( $this->plugin_basename, $plugins, true ) ) {
			delete_transient( $this->cache_key );
			delete_transient( $this->cache_key . '_beta' );

			$update_plugins = get_site_transient( 'update_plugins' );
			if ( $update_plugins && isset( $update_plugins->response[ $this->plugin_basename ] ) ) {
				unset( $update_plugins->response[ $this->plugin_basename ] );
				set_site_transient( 'update_plugins', $update_plugins );
			}
		}
	}

	/**
	 * 更新をチェック
	 *
	 * @param object $transient 更新トランジェント
	 * @return mixed 更新されたトランジェント
	 */
	public function check_for_update( mixed $transient ): mixed {
		if ( ! is_object( $transient ) || empty( $transient->checked ) ) {
			return $transient;
		}

		$current_version = $transient->checked[ $this->plugin_basename ] ?? $this->current_version;

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $transient;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );

		$current_parts = explode( '.', $current_version );
		$latest_parts  = explode( '.', $latest_version );
		$current_major = (int) ( $current_parts[0] ?? 0 );
		$latest_major  = (int) ( $latest_parts[0] ?? 0 );

		if ( $current_major !== $latest_major ) {
			return $transient;
		}

		if ( version_compare( $current_version, $latest_version, '<' ) ) {
			$download_url = $this->get_download_url( $release );

			if ( $download_url ) {
				$transient->response[ $this->plugin_basename ] = (object) [
					'slug'         => $this->plugin_slug,
					'plugin'       => $this->plugin_basename,
					'new_version'  => $latest_version,
					'url'          => $release['html_url'],
					'package'      => $download_url,
					'icons'        => [],
					'banners'      => [],
					'requires'     => '7.0',
					'tested'       => '7.1',
					'requires_php' => '8.5.12',
				];
			}
		} else {
			if ( isset( $transient->response[ $this->plugin_basename ] ) ) {
				unset( $transient->response[ $this->plugin_basename ] );
			}
			if ( ! isset( $transient->no_update[ $this->plugin_basename ] ) ) {
				$transient->no_update[ $this->plugin_basename ] = (object) [
					'slug'        => $this->plugin_slug,
					'plugin'      => $this->plugin_basename,
					'new_version' => $current_version,
					'url'         => '',
					'package'     => '',
				];
			}
		}

		return $transient;
	}

	/**
	 * プラグイン情報を取得（詳細ポップアップ用）
	 *
	 * @param false|object|array $result 結果
	 * @param string             $action アクション
	 * @param object             $args   引数
	 * @return false|object 結果
	 */
	public function plugin_info( mixed $result, string $action, object $args ): mixed {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}

		if ( 'sub-pod' !== $args->slug ) {
			return $result;
		}

		$release = $this->get_latest_release();

		if ( ! $release ) {
			return $result;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );
		$download_url   = $this->get_download_url( $release );

		return (object) [
			'name'              => 'Sub Pod',
			'slug'              => $this->plugin_slug,
			'version'           => $latest_version,
			'author'            => '<a href="https://github.com/villyoshioka">villyoshioka</a>',
			'author_profile'    => 'https://github.com/villyoshioka',
			'homepage'          => 'https://github.com/villyoshioka/SubPod',
			'short_description' => 'WordPressサイト全体をバックアップ・復元するプラグイン',
			'sections'          => [
				'description' => $this->get_readme_description(),
				'changelog'   => $this->format_changelog( (string) ( $release['body'] ?? '' ) ),
			],
			'download_link'     => $download_url,
			'requires'          => '7.0',
			'tested'            => '7.1',
			'requires_php'      => '8.5.12',
			'last_updated'      => $release['published_at'],
		];
	}

	private function get_latest_release(): array|false {
		$include_prerelease = $this->is_beta_channel_enabled();
		$cache_key          = $include_prerelease ? $this->cache_key . '_beta' : $this->cache_key;

		$cached = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		if ( $include_prerelease ) {
			$url = sprintf(
				'https://api.github.com/repos/%s/%s/releases',
				$this->github_owner,
				$this->github_repo
			);
		} else {
			$url = sprintf(
				'https://api.github.com/repos/%s/%s/releases/latest',
				$this->github_owner,
				$this->github_repo
			);
		}

		$response = wp_safe_remote_get( $url, [
			'timeout' => 10,
			'headers' => [
				'Accept'     => 'application/vnd.github.v3+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			],
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( $status_code !== 200 ) {
			return false;
		}

		$body_raw = wp_remote_retrieve_body( $response );

		if ( ! json_validate( $body_raw ) ) {
			return false;
		}

		$body = json_decode( $body_raw, true );

		if ( $include_prerelease ) {
			$body = $this->get_latest_from_releases( $body );
		}

		if ( empty( $body ) || ! is_array( $body ) ) {
			return false;
		}

		$required_fields = [ 'tag_name', 'html_url', 'zipball_url' ];
		foreach ( $required_fields as $field ) {
			if ( ! isset( $body[ $field ] ) || ! is_string( $body[ $field ] ) ) {
				return false;
			}
		}

		if ( ! preg_match( '/^v?\d+\.\d+(\.\d+)?(-[a-zA-Z0-9.]+)?$/', $body['tag_name'] ) ) {
			return false;
		}

		set_transient( $cache_key, $body, $this->cache_expiry );

		return $body;
	}

	private function is_beta_channel_enabled(): bool {
		return (bool) get_transient( 'sp_beta_channel' );
	}

	/**
	 * リリース一覧から最新のリリースを取得（プレリリース含む）
	 *
	 * リリースは公開日順（降順）で返されるので、最初の要素が最新
	 */
	private function get_latest_from_releases( mixed $releases ): array|false {
		if ( empty( $releases ) || ! is_array( $releases ) ) {
			return false;
		}

		foreach ( $releases as $release ) {
			if ( is_array( $release ) && isset( $release['tag_name'] ) ) {
				return $release;
			}
		}

		return false;
	}

	private function get_download_url( array $release ): string|false {
		if ( ! empty( $release['assets'] ) && is_array( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( isset( $asset['name'] ) && $asset['name'] === 'sub-pod.zip' ) {
					if ( isset( $asset['browser_download_url'] ) ) {
						// SSRF対策: URLがGitHubのドメインであることを検証
						if ( $this->is_valid_github_url( $asset['browser_download_url'] ) ) {
							return $asset['browser_download_url'];
						}
					}
				}
			}
		}

		return false;
	}

	/**
	 * URLが正当なGitHub URLかどうかを検証（SSRF対策）
	 */
	private function is_valid_github_url( string $url ): bool {
		if ( empty( $url ) ) {
			return false;
		}

		$parsed = wp_parse_url( $url );

		if ( ! isset( $parsed['scheme'] ) || $parsed['scheme'] !== 'https' ) {
			return false;
		}

		if ( ! isset( $parsed['host'] ) ) {
			return false;
		}

		$allowed_hosts = [
			'api.github.com',
			'github.com',
			'codeload.github.com',
			'objects.githubusercontent.com', // リリースアセットのリダイレクト先
		];

		if ( ! in_array( $parsed['host'], $allowed_hosts, true ) ) {
			return false;
		}

		if ( ! isset( $parsed['path'] ) || str_contains( $parsed['path'], '..' ) ) {
			return false;
		}

		// objects.githubusercontent.com はリダイレクト先なのでパス検証をスキップ
		if ( $parsed['host'] === 'objects.githubusercontent.com' ) {
			return true;
		}

		$expected_path_part = '/' . $this->github_owner . '/' . $this->github_repo;
		if ( ! str_starts_with( $parsed['path'], $expected_path_part . '/' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * ソースディレクトリ名を修正
	 *
	 * GitHub の zipball は「owner-repo-hash」形式のディレクトリ名になるため、
	 * 正しいプラグインディレクトリ名に修正する
	 *
	 * @param string       $source        ソースパス
	 * @param string       $remote_source リモートソース
	 * @param \WP_Upgrader $upgrader      アップグレーダー
	 * @param array        $hook_extra    追加情報
	 * @return string|\WP_Error 修正されたソースパス
	 */
	public function fix_source_dir( mixed $source, string $remote_source, \WP_Upgrader $upgrader, array $hook_extra ): mixed {
		global $wp_filesystem;

		// ほかのフィルターがすでに失敗を返していたら、そのまま渡す
		if ( ! is_string( $source ) ) {
			return $source;
		}

		if ( ! isset( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->plugin_basename ) {
			return $source;
		}

		$source_dirname = basename( untrailingslashit( $source ) );
		if ( $source_dirname === $this->plugin_slug ) {
			return $source;
		}

		$github_pattern = '/^' . preg_quote( $this->github_owner, '/' ) . '-' . preg_quote( $this->github_repo, '/' ) . '-[a-f0-9]+$/i';
		if ( ! preg_match( $github_pattern, $source_dirname ) ) {
			return $source;
		}

		// パストラバーサル対策
		$real_source = realpath( $source );
		$real_remote = realpath( $remote_source );

		if ( $real_source === false || $real_remote === false ) {
			return new \WP_Error( 'invalid_path', '無効なパスが検出されました。' );
		}

		if ( $real_source !== $real_remote && ! str_starts_with( $real_source, trailingslashit( $real_remote ) ) ) {
			return new \WP_Error( 'path_traversal', 'パストラバーサルが検出されました。' );
		}

		$correct_dir = trailingslashit( $remote_source ) . $this->plugin_slug;

		// Null バイトチェック
		if ( str_contains( $correct_dir, "\0" ) ) {
			return new \WP_Error( 'null_byte', '無効な文字が含まれています。' );
		}

		if ( $wp_filesystem->exists( $correct_dir ) ) {
			$wp_filesystem->delete( $correct_dir, true );
		}

		if ( $wp_filesystem->move( $source, $correct_dir ) ) {
			return trailingslashit( $correct_dir );
		}

		return new \WP_Error( 'rename_failed', 'プラグインディレクトリ名の変更に失敗しました。' );
	}

	private function get_readme_description(): string {
		return 'Sub Pod は WordPress サイト全体をバックアップ・復元するプラグインです。';
	}

	private function format_changelog( string $body ): string {
		if ( empty( $body ) ) {
			return '<p>変更履歴はありません。</p>';
		}

		$html = esc_html( $body );
		$html = nl2br( $html );

		$html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.+<\/li>\s*)+/', '<ul>$0</ul>', $html );

		return $html;
	}

	public function clear_cache(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		delete_transient( $this->cache_key );
		return true;
	}
}
