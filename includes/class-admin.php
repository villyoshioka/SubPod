<?php
/**
 * 管理画面クラス
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SP_Admin {
	private const string CAPABILITY = 'manage_options';
	private const int MAX_CHUNK = 8388608;
	private const int HEARTBEAT = 5;

	private static ?self $instance = null;

	private int $last_output = 0;
	private int $log_started = 0;
	private string $log_message = '';

	/** @var string[] */
	private array $log_lines = array();

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );
		add_action( 'admin_init', [ $this, 'handle_beta_mode_params' ] );
		add_action( 'admin_notices', [ $this, 'show_notices' ] );

		// 通知を出す前に片付ける。WP-Cron はサイトにアクセスがないと動かないので、画面を開いたときにも行う
		add_action( 'load-tools_page_sub-pod', [ SP_Backup::class, 'cleanup' ] );

		add_action( 'wp_ajax_sp_save_settings', [ $this, 'ajax_save_settings' ] );
		add_action( 'wp_ajax_sp_backup', [ $this, 'ajax_backup' ] );
		add_action( 'wp_ajax_sp_upload_start', [ $this, 'ajax_upload_start' ] );
		add_action( 'wp_ajax_sp_upload_chunk', [ $this, 'ajax_upload_chunk' ] );
		add_action( 'wp_ajax_sp_restore', [ $this, 'ajax_restore' ] );
		add_action( 'wp_ajax_sp_discard_leftover', [ $this, 'ajax_discard_leftover' ] );
		add_action( 'wp_ajax_sp_download', [ $this, 'ajax_download' ] );
		add_action( 'wp_ajax_sp_download_log', [ $this, 'ajax_download_log' ] );
	}

	public function add_admin_menu(): void {
		add_management_page( 'Sub Pod', 'Sub Pod', self::CAPABILITY, 'sub-pod', [ $this, 'render_page' ] );
	}

	public function enqueue_admin_scripts( string $hook ): void {
		if ( 'tools_page_sub-pod' !== $hook ) {
			return;
		}

		// post_max_size が無制限(0)の環境では 0 が返る
		$max_upload = (int) wp_max_upload_size();

		wp_enqueue_style( 'nau-admin-fw', SP_PLUGIN_URL . 'assets/css/admin-fw.css', [], SP_VERSION );
		wp_enqueue_style( 'sub-pod-admin', SP_PLUGIN_URL . 'assets/css/admin.css', [ 'nau-admin-fw' ], SP_VERSION );
		// 暗号化パスワードの強さは、WordPress のプロフィール画面と同じ判定で見る
		wp_enqueue_script( 'sub-pod-admin', SP_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery', 'password-strength-meter' ], SP_VERSION, true );
		wp_localize_script(
			'sub-pod-admin',
			'subPodAdmin',
			[
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'sp_nonce' ),
				'chunkSize' => $max_upload > 0 ? min( self::MAX_CHUNK, (int) floor( $max_upload * 0.8 ) ) : self::MAX_CHUNK,
			]
		);
	}

	public function handle_beta_mode_params(): void {
		if ( ! isset( $_GET['page'] ) || 'sub-pod' !== sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return;
		}

		// admin_init は未ログインの admin-post.php でも走るため、権限と確認リンクの nonce を確かめてから反映する
		if ( isset( $_GET['sp_beta'] ) && 'off' === sanitize_text_field( wp_unslash( $_GET['sp_beta'] ) )
			&& current_user_can( self::CAPABILITY )
			&& isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'sp_beta_off' ) ) {
			SP_Settings::disable_beta_mode();
			wp_safe_redirect( admin_url( 'tools.php?page=sub-pod' ) );
			exit;
		}
	}

	/**
	 * どの管理画面でも管理者に伝える
	 *
	 * Carry Pod の裏で動いたバックアップの失敗やダウンロード待ちは、ここでしか気づけない。
	 */
	public function show_notices(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		if ( null !== SP_Restore::leftover_path() ) {
			printf(
				'<div class="notice notice-error"><p>Sub Pod: 前回の復元が完了しなかったときの一時ファイルが残っています。元のサイトのデータが含まれています。<a href="%s">Sub Pod の画面で確認する</a></p></div>',
				esc_url( admin_url( 'tools.php?page=sub-pod' ) )
			);
		}

		if ( SP_Settings::is_install_guard( SP_Settings::install_guard_path() ) && ! SP_Backup::is_running() ) {
			echo '<div class="notice notice-error"><p>Sub Pod: 前回の復元が完了しなかったので、インストール画面と更新画面を閉じています。サイトが正常なら <code>wp-content/install.php</code> を削除してください。</p></div>';
		}

		$skipped = SP_Backup::skipped_links();
		if ( null !== $skipped && ! empty( $skipped['objects'] ) ) {
			printf(
				'<div class="notice notice-warning"><p>Sub Pod: 前回のバックアップに含まれていないデータベースの機能が %d 件あります。必要なら別に控えてください: %s</p></div>',
				count( $skipped['objects'] ),
				esc_html( implode( '、', array_slice( $skipped['objects'], 0, 10 ) ) )
			);
		}
		if ( null !== $skipped && $skipped['count'] > 0 ) {
			$names = implode( '、', array_slice( (array) $skipped['names'], 0, 10 ) );
			printf(
				'<div class="notice notice-warning"><p>Sub Pod: 前回のバックアップに含まれていないリンクが %d 件あります。リンク先のファイルは別に保管してください: %s</p></div>',
				(int) $skipped['count'],
				esc_html( $names )
			);
		}

		if ( null === SP_Settings::password() ) {
			$carry_pod = defined( 'CP_VERSION' ) && SP_Settings::get_settings()['cp_backup'] ? 'Carry Pod の静的化は、バックアップなしで行われます。' : '';
			printf(
				'<div class="notice notice-warning sub-pod-password-notice"><p>Sub Pod: 暗号化パスワードが設定されていないか読み取れないため、バックアップできません。%s<a href="%s">Sub Pod の画面で設定する</a></p></div>',
				esc_html( $carry_pod ),
				esc_url( admin_url( 'tools.php?page=sub-pod' ) )
			);
		}

		$restore_notice = SP_Backup::restore_notice();
		if ( '' !== $restore_notice ) {
			printf( '<div class="notice notice-warning"><p>Sub Pod: %s</p></div>', esc_html( $restore_notice ) );
		}

		$error = SP_Backup::last_error();
		if ( '' !== $error ) {
			printf( '<div class="notice notice-error"><p>Sub Pod: %s</p></div>', esc_html( $error ) );
		}
		if ( SP_Backup::offer_download() ) {
			printf(
				'<div class="notice notice-info"><p>Sub Pod: バックアップの準備ができました。<a href="%s">ダウンロードする</a>(ほかの画面に移ると削除されます)</p></div>',
				esc_url( SP_Backup::download_url() )
			);
		}
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( '権限がありません。' );
		}

		$settings      = SP_Settings::get_settings();
		$is_running    = SP_Backup::is_running();
		$leftover_path = SP_Restore::leftover_path();

		$beta_message = '';
		$beta_param   = isset( $_GET['sp_beta'] ) ? sanitize_text_field( wp_unslash( $_GET['sp_beta'] ) ) : '';
		if ( 'off' === $beta_param ) {
			$beta_message = 'confirm_off';
		} elseif ( 'on' === $beta_param && ! SP_Settings::is_beta_mode_enabled() ) {
			$beta_message = 'need_password';
			if ( isset( $_POST['sp_beta_password'], $_POST['sp_beta_nonce'] )
				&& wp_verify_nonce( sanitize_key( wp_unslash( $_POST['sp_beta_nonce'] ) ), 'sp_beta_auth' ) ) {
				$result = SP_Settings::enable_beta_mode( sanitize_text_field( wp_unslash( $_POST['sp_beta_password'] ) ) );
				if ( is_wp_error( $result ) ) {
					$beta_message = 'rate_limit';
				} else {
					$beta_message = $result ? 'activated' : 'wrong_password';
				}
			}
		}
		$is_beta_enabled = SP_Settings::is_beta_mode_enabled();

		include SP_PLUGIN_DIR . 'views/admin-page.php';
	}

	public function check_carry_pod_compatibility(): void {
		if ( ! defined( 'CP_VERSION' ) ) {
			return;
		}

		$cp_version = CP_VERSION;

		if ( ! preg_match( '/^\d+\.\d+\.\d+(?:-[a-zA-Z0-9\-]+)?$/', $cp_version ) ) {
			return;
		}

		if ( version_compare( $cp_version, '3.5.0', '>=' ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong>⚠️ CarryPod連携</strong><br>
				CarryPod 3.5.0以降にアップデートすると、静的化前の自動バックアップが有効になります。<br>
				<small>現在: CarryPod <?php echo esc_html( $cp_version ); ?> → 推奨: CarryPod 3.5.0+</small>
			</p>
		</div>
		<?php
	}

	private function render_tooltip( string $text ): string {
		return sprintf(
			'<span class="nau-tooltip-wrapper">' .
			'<span class="nau-tooltip-trigger" tabindex="0" role="button" aria-label="詳細を表示" aria-expanded="false">?</span>' .
			'<span class="nau-tooltip-content" role="tooltip">%s</span>' .
			'</span>',
			wp_kses( $text, [ 'br' => [] ] )
		);
	}

	public function ajax_save_settings(): void {
		$this->authorize();

		$fields = [ 'dest_download', 'dest_directory', 'dest_r2', 'cp_backup', 'directory', 'r2_account_id', 'r2_bucket', 'r2_access_key', 'r2_secret_key', 'password', 'password_confirm' ];
		$input  = [];
		foreach ( $fields as $field ) {
			$input[ $field ] = isset( $_POST[ $field ] ) ? wp_unslash( (string) $_POST[ $field ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		$result = SP_Settings::save( $input );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		SP_Backup::clear_restore_notice();

		// 連動を切ったら、前の静的化で失敗した話はもう関係ない
		if ( ! SP_Settings::get_settings()['cp_backup'] ) {
			SP_Backup::clear_last_error();
		}

		wp_send_json_success( [ 'message' => '設定を保存しました。' ] );
	}

	public function ajax_backup(): void {
		$this->authorize();

		$destinations = SP_Settings::destinations();
		if ( ! $destinations ) {
			wp_send_json_error( [ 'message' => '保存先を1つ以上選んでください。' ] );
		}

		SP_Backup::clear_log();
		SP_Backup::clear_last_error();
		SP_Backup::clear_restore_notice();

		$this->log_started = time();
		$this->start_stream();
		try {
			$result = ( new SP_Backup() )->run( $destinations, [ $this, 'send_progress' ], [ $this, 'heartbeat' ] );
			SP_Backup::save_log( $this->build_log( $destinations, $result ) );
			$this->send(
				[
					'done'           => true,
					'logUrl'         => SP_Backup::log_url(),
					'skippedLinks'   => $result['skipped_links'],
					'skippedNames'   => array_slice( $result['skipped_names'], 0, 10 ),
					'skippedObjects' => array_slice( $result['skipped_objects'], 0, 10 ),
					'downloadUrl'    => in_array( 'download', $destinations, true ) ? SP_Backup::download_url() : '',
				]
			);
			// 画面が閉じられていたら、もう誰も受け取らない
			if ( connection_aborted() && in_array( 'download', $destinations, true ) ) {
				SP_Backup::delete_pending_download();
			}
		} catch ( Throwable $e ) {
			SP_Backup::save_log( $this->build_log( $destinations, null, $e->getMessage() ) );
			$this->send(
				[
					'error'  => $e->getMessage(),
					'logUrl' => SP_Backup::log_url(),
				]
			);
			// 画面が閉じられていたら、次に管理画面を開いたときにしか伝えられない
			if ( connection_aborted() ) {
				SP_Backup::set_last_error( 'バックアップに失敗しました: ' . $e->getMessage() );
			}
		}
		exit;
	}

	public function ajax_upload_start(): void {
		$this->authorize();

		if ( SP_Backup::is_running() ) {
			wp_send_json_error( [ 'message' => '別のバックアップまたは復元が実行中です。' ] );
		}
		try {
			SP_Restore::discard_upload();
			SP_Settings::work_dir( 'upload' );
			SP_Backup::schedule_cleanup();
			wp_send_json_success();
		} catch ( RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	/**
	 * 分割アップロードの 1 片を受け取る
	 *
	 * 届いた内容はサイトの外の作業用フォルダに決まった名前で置くので、元のファイル名は使わない。
	 */
	public function ajax_upload_chunk(): void {
		$this->authorize();

		$offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : -1;
		$chunk  = $_FILES['chunk'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $chunk ) || UPLOAD_ERR_OK !== ( $chunk['error'] ?? -1 ) || ! is_uploaded_file( (string) $chunk['tmp_name'] ) || (int) $chunk['size'] > self::MAX_CHUNK ) {
			wp_send_json_error( [ 'message' => 'ファイルのアップロードに失敗しました。' ] );
		}

		if ( SP_Backup::is_running() ) {
			wp_send_json_error( [ 'message' => '別のバックアップまたは復元が実行中です。' ] );
		}

		$path = SP_Restore::upload_path();
		if ( ! SP_Settings::is_work_root_safe() || ! is_dir( dirname( $path ) ) || ( is_file( $path ) ? (int) filesize( $path ) : 0 ) !== $offset ) {
			wp_send_json_error( [ 'message' => 'ファイルのアップロードが途中で食い違いました。もう一度やり直してください。' ] );
		}

		$data = file_get_contents( (string) $chunk['tmp_name'] );
		if ( false === $data || false === file_put_contents( $path, $data, FILE_APPEND ) ) {
			wp_send_json_error( [ 'message' => 'ファイルの保存に失敗しました。空き容量を確認してください。' ] );
		}

		wp_send_json_success();
	}

	public function ajax_restore(): void {
		$this->authorize();

		$user     = wp_get_current_user();
		$attempts = 'sp_restore_attempts_' . $user->ID;
		if ( (int) get_transient( $attempts ) >= 5 ) {
			SP_Restore::discard_upload();
			wp_send_json_error( [ 'message' => 'パスワードの試行回数が超過しました。10分後に再試行してください。' ] );
		}

		// WordPress のログインとプロフィール保存はエスケープされたままの値でパスワードを扱うので、
		// 記号を含むパスワードでも通るよう両方の形で確かめる。CLI で設定したものはエスケープされていない
		$admin_password = isset( $_POST['admin_password'] ) ? (string) $_POST['admin_password'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! wp_check_password( $admin_password, $user->user_pass, $user->ID ) && ! wp_check_password( wp_unslash( $admin_password ), $user->user_pass, $user->ID ) ) {
			set_transient( $attempts, (int) get_transient( $attempts ) + 1, 10 * MINUTE_IN_SECONDS );
			SP_Restore::discard_upload();
			wp_send_json_error( [ 'message' => 'ログインパスワードが正しくありません。' ] );
		}
		delete_transient( $attempts );

		$total = isset( $_POST['total'] ) ? absint( $_POST['total'] ) : -1;
		$path  = SP_Restore::upload_path();
		if ( ! is_file( $path ) || (int) filesize( $path ) !== $total ) {
			SP_Restore::discard_upload();
			wp_send_json_error( [ 'message' => 'ファイルが最後までアップロードされていません。もう一度やり直してください。' ] );
		}

		$password = isset( $_POST['encryption_password'] ) ? wp_unslash( (string) $_POST['encryption_password'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$this->start_stream();
		try {
			$restore   = new SP_Restore();
			$login_url = $restore->run( $password, [ $this, 'send_progress' ], [ $this, 'heartbeat' ] );

			// 読み込み済みの古い内容を、終了処理が復元後のデータベースへ書き戻さないようにする
			remove_all_actions( 'shutdown' );

			$this->send(
				[
					'done'         => true,
					'loginUrl'     => $login_url,
					'skippedLinks' => $restore->skipped_links(),
					'skippedNames' => array_slice( $restore->skipped_names(), 0, 10 ),
				]
			);
		} catch ( Throwable $e ) {
			SP_Restore::discard_upload();
			$this->send( [ 'error' => $e->getMessage() ] );
		}
		exit;
	}

	public function ajax_discard_leftover(): void {
		$this->authorize();

		try {
			SP_Restore::discard_leftover();
			wp_send_json_success();
		} catch ( RuntimeException $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ] );
		}
	}

	public function ajax_download_log(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( '権限がありません。', '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'sp_download_log' );

		$log = SP_Backup::last_log();
		if ( '' === $log ) {
			wp_die( '取得できるログがありません。', '', [ 'response' => 404 ] );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="sub-pod-log-' . wp_date( 'Ymd-His' ) . '.txt"' );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $log; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * バックアップのログを組み立てる
	 *
	 * 共有されることがあるので、キーやパスワードは書かない。
	 *
	 * @param string[]   $destinations
	 * @param array|null $result 失敗したときは null
	 */
	private function build_log( array $destinations, ?array $result, string $error = '' ): string {
		$settings = SP_Settings::get_settings();

		$log  = "=====================================\n";
		$log .= "Sub Pod - バックアップログ\n";
		$log .= "=====================================\n\n";

		$log .= "【基本情報】\n";
		$log .= '実行日時: ' . wp_date( 'Y-m-d H:i:s', $this->log_started ) . "\n";
		$log .= 'ステータス: ' . ( null === $result ? '失敗' : '成功' ) . "\n";
		$log .= 'プラグインバージョン: ' . SP_VERSION . "\n";
		$log .= 'WordPress バージョン: ' . get_bloginfo( 'version' ) . "\n";
		$log .= 'PHP バージョン: ' . PHP_VERSION . "\n\n";

		$log .= "【保存先】\n";
		$log .= '  - 完了時にダウンロード: ' . ( in_array( 'download', $destinations, true ) ? '有効' : '無効' ) . "\n";
		$log .= '  - 特定のディレクトリ: ' . ( in_array( 'directory', $destinations, true ) ? '有効(' . $settings['directory'] . ')' : '無効' ) . "\n";
		$log .= '  - Cloudflare R2: ' . ( in_array( 'r2', $destinations, true ) ? '有効(' . $settings['r2_bucket'] . ')' : '無効' ) . "\n\n";

		if ( null === $result ) {
			$log .= "【エラー】\n";
			$log .= $error . "\n";
			$log .= '所要時間: ' . ( time() - $this->log_started ) . "秒\n";
		} else {
			$log .= "【バックアップ】\n";
			$log .= 'ファイル名: ' . $result['name'] . "\n";
			$log .= 'サイズ: ' . size_format( $result['size'], 1 ) . "\n";
			$log .= 'ファイル数: ' . $result['file_count'] . "\n";
			$log .= '所要時間: ' . ( time() - $this->log_started ) . "秒\n";
			$log .= '含まれていないリンク: ' . $result['skipped_links'] . "件\n";
			foreach ( $result['skipped_names'] as $name ) {
				$log .= '  - ' . $name . "\n";
			}
			$log .= '含まれていないデータベースの機能: ' . count( $result['skipped_objects'] ) . "件\n";
			foreach ( $result['skipped_objects'] as $name ) {
				$log .= '  - ' . $name . "\n";
			}
		}

		return $log . "\n" . implode( "\n", $this->log_lines ) . "\n";
	}

	public function ajax_download(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( '権限がありません。', '', [ 'response' => 403 ] );
		}
		check_admin_referer( 'sp_download' );

		$path = SP_Backup::pending_download();
		if ( null === $path ) {
			wp_die( 'ダウンロードできるバックアップがありません。', '', [ 'response' => 404 ] );
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		ignore_user_abort( true );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Cache-Control: no-store' );
		header( 'X-Content-Type-Options: nosniff' );

		// ダウンロードツールの事前確認では消さない
		if ( 'HEAD' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			exit;
		}

		// 送っている間は案内を出さない。途中で切れたときも残さない。必要ならバックアップを取り直す
		SP_Backup::forget_pending_download();
		readfile( $path );
		flush();
		SP_Backup::remove_sent_download( $path );
		exit;
	}

	public function send_progress( string $message, int $percent ): void {
		// ログを残すのはバックアップだけ。復元中はデータベースが入れ替わるので、日時の設定も読めない
		if ( $this->log_started > 0 && $message !== $this->log_message ) {
			$this->log_message = $message;
			$this->log_lines[] = wp_date( 'H:i:s' ) . ' ' . $message;
		}

		$this->send(
			[
				'message' => $message,
				'percent' => $percent,
			]
		);
	}

	/** サーバーに無応答で打ち切られないよう、長い処理の途中でも定期的に何か返す */
	public function heartbeat(): void {
		if ( time() - $this->last_output >= self::HEARTBEAT ) {
			$this->send( [ 'alive' => true ] );
		}
	}

	private function authorize(): void {
		if ( ! check_ajax_referer( 'sp_nonce', 'nonce', false ) || ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( [ 'message' => '権限がありません。' ], 403 );
		}
	}

	/**
	 * 進捗を 1 行ずつ返す応答を始める
	 *
	 * 画面を閉じられても、途中で止まるほうが危ないので処理は最後まで続ける。
	 */
	private function start_stream(): void {
		ignore_user_abort( true );
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		// admin-ajax.php では管理画面用のメモリ上限にならない
		wp_raise_memory_limit( 'admin' );
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet
		// 警告が進み具合の行に混ざると、画面が読めずに失敗と表示してしまう
		@ini_set( 'display_errors', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.IniSet
		$GLOBALS['wpdb']->hide_errors();
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		header( 'Content-Type: application/x-ndjson; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'X-Accel-Buffering: no' );
	}

	private function send( array $data ): void {
		// 直前に改行なしの出力があっても 1 行として読めるよう、前にも改行を入れる
		echo "\n" . wp_json_encode( $data ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		flush();
		$this->last_output = time();
	}
}
