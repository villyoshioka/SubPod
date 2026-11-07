<?php
/**
 * 管理ページビュー
 *
 * @package SubPod
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>

<div class="wrap nau-admin-wrap">
	<h1>Sub Pod</h1>

	<?php $this->check_carry_pod_compatibility(); ?>

	<?php if ( $is_beta_enabled ) : ?>
	<div class="notice notice-info">
		<p><strong>ベータモード</strong> - プレリリース版のアップデートが有効です。無効にするには <code>&sp_beta=off</code> を追加してください。</p>
	</div>
	<?php endif; ?>

	<?php if ( 'confirm_off' === $beta_message ) : ?>
	<div class="notice notice-warning">
		<p><a href="<?php echo esc_url( wp_nonce_url( admin_url( 'tools.php?page=sub-pod&sp_beta=off' ), 'sp_beta_off' ) ); ?>">ベータモードを無効にする</a></p>
	</div>
	<?php elseif ( 'rate_limit' === $beta_message ) : ?>
	<div class="notice notice-error">
		<p>ログイン試行回数が超過しました。10分後に再試行してください。</p>
	</div>
	<?php elseif ( 'activated' === $beta_message ) : ?>
	<div class="notice notice-success">
		<p>ベータモードを有効化しました。</p>
	</div>
	<?php elseif ( 'need_password' === $beta_message || 'wrong_password' === $beta_message ) : ?>
		<?php if ( 'wrong_password' === $beta_message ) : ?>
	<div class="notice notice-error">
		<p>パスワードが正しくありません。</p>
	</div>
		<?php endif; ?>
	<div class="notice notice-warning">
		<p><strong>ベータモード認証</strong></p>
		<form method="post" class="sub-pod-beta-prompt">
			<?php wp_nonce_field( 'sp_beta_auth', 'sp_beta_nonce' ); ?>
			<input type="password" name="sp_beta_password" placeholder="パスワードを入力" />
			<input type="submit" class="button" value="認証" />
		</form>
	</div>
	<?php endif; ?>

	<div id="sub-pod-message" aria-live="polite"></div>

	<form id="sub-pod-settings" class="nau-settings-form" autocomplete="off">
		<div class="nau-accordion-section" data-section="destination">
			<button type="button" class="nau-accordion-header" id="header-destination" aria-expanded="true" aria-controls="accordion-destination">
				<span class="nau-accordion-title">バックアップ設定</span>
				<span class="nau-accordion-icon" aria-hidden="true"></span>
			</button>
			<div id="accordion-destination" class="nau-accordion-content" role="region" aria-labelledby="header-destination" aria-hidden="false">
				<div class="nau-form-group">
					<label>
						<input type="checkbox" name="dest_download" value="1" <?php checked( $settings['dest_download'] ); ?>>
						完了時にダウンロード
						<?php echo $this->render_tooltip( '保存先は複数選べます。Sub Pod の中に履歴は持たないので、古いバックアップの整理は保存先で行ってください。<br>どの保存先にも、下の暗号化パスワードで暗号化したファイルを置きます。<br>Carry Pod の自動実行ではダウンロードは使われません。保存先がこれだけのときは、バックアップなしで静的化します。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>

				<div class="nau-form-group">
					<label>
						<input type="checkbox" id="sub-pod-directory-enabled" name="dest_directory" value="1" <?php checked( $settings['dest_directory'] ); ?>>
						特定のディレクトリ
					</label>
				</div>
				<div id="sub-pod-directory-settings" class="nau-subsection" <?php echo $settings['dest_directory'] ? '' : 'style="display:none;"'; ?>>
					<div class="nau-form-group">
						<label for="sub-pod-directory">
							ディレクトリの絶対パス <span class="required">*</span>
							<?php echo $this->render_tooltip( '公開されないよう、WordPress の外だけを指定できます。<br>PC が使えなくなると一緒に失われるので、外付けドライブなども検討してください。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</label>
						<input type="text" id="sub-pod-directory" name="directory" class="regular-text" value="<?php echo esc_attr( $settings['directory'] ); ?>" spellcheck="false">
					</div>
				</div>

				<div class="nau-form-group">
					<label>
						<input type="checkbox" id="sub-pod-r2-enabled" name="dest_r2" value="1" <?php checked( $settings['dest_r2'] ); ?>>
						Cloudflare R2
					</label>
				</div>
				<div id="sub-pod-r2-settings" class="nau-subsection" <?php echo $settings['dest_r2'] ? '' : 'style="display:none;"'; ?>>
					<div class="nau-form-group">
						<label for="sub-pod-r2-account">
							アカウント ID <span class="required">*</span>
							<?php echo $this->render_tooltip( 'Cloudflare ダッシュボードの R2 の概要ページで確認できます(32 桁の英数字)。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</label>
						<input type="text" id="sub-pod-r2-account" name="r2_account_id" class="regular-text" value="<?php echo esc_attr( $settings['r2_account_id'] ); ?>" spellcheck="false">
					</div>
					<div class="nau-form-group">
						<label for="sub-pod-r2-bucket">
							バケット名 <span class="required">*</span>
							<?php echo $this->render_tooltip( 'R2 に作ったバケットの名前です。公開アクセスは有効にしないでください。<br>古いバックアップは、バケットの Settings にある Object Lifecycle Rules で自動削除できます。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</label>
						<input type="text" id="sub-pod-r2-bucket" name="r2_bucket" class="regular-text" value="<?php echo esc_attr( $settings['r2_bucket'] ); ?>" spellcheck="false">
					</div>
					<div class="nau-form-group">
						<label for="sub-pod-r2-access">
							アクセスキー ID <span class="required">*</span>
							<?php echo $this->render_tooltip( 'API トークンを作成すると表示される Access Key ID です。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</label>
						<input type="text" id="sub-pod-r2-access" name="r2_access_key" class="regular-text" value="<?php echo esc_attr( $settings['r2_access_key'] ); ?>" spellcheck="false">
						<p class="description">
							<a href="https://dash.cloudflare.com/?to=/:account/r2/overview" target="_blank" rel="noopener noreferrer">API トークンを作成</a>(Account Details の API Tokens から)<br>
							必要権限: Object Read &amp; Write(このバケットだけに限定)
						</p>
					</div>
					<div class="nau-form-group">
						<label for="sub-pod-r2-secret">
							シークレットアクセスキー <span class="required">*</span>
							<?php echo $this->render_tooltip( 'API トークンの作成時に一度だけ表示される Secret Access Key です。あとから確認できないので、作成画面でコピーしてください。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</label>
						<input type="password" id="sub-pod-r2-secret" name="r2_secret_key" class="regular-text" autocomplete="new-password" placeholder="<?php echo '' !== $settings['r2_secret_key'] ? '設定済み(変更するときだけ入力)' : 'シークレットアクセスキーを入力してください'; ?>">
						<span class="sub-pod-secret-status" <?php echo '' !== $settings['r2_secret_key'] ? '' : 'hidden'; ?>>✓ 設定済み</span>
					</div>
				</div>

				<div class="nau-form-group">
					<label for="sub-pod-backup-name">
						バックアップ名
						<?php echo $this->render_tooltip( 'バックアップのファイル名の先頭に付けます。うしろには日時が付きます。<br>英数字・ハイフン・アンダーバーで、64 文字まで入力できます。空欄のときはサイトのドメイン名を使います。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</label>
					<input type="text" id="sub-pod-backup-name" name="backup_name" class="regular-text" value="<?php echo esc_attr( $settings['backup_name'] ); ?>" maxlength="64" spellcheck="false">
				</div>

				<?php $has_password = null !== SP_Settings::password(); ?>
				<div class="nau-form-group">
					<label for="sub-pod-password">
						暗号化パスワード <span class="required">*</span>
						<?php echo $this->render_tooltip( 'すべてのバックアップの暗号化に使います。12 文字以上で、よくあるパスワードや同じ文字の繰り返しは使えません。<br>ほかのサービスのパスワードとは別のものにしてください。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</label>
					<input type="password" id="sub-pod-password" name="password" class="regular-text" autocomplete="new-password" placeholder="<?php echo $has_password ? '設定済み(変更するときだけ入力)' : '12 文字以上で入力してください'; ?>">
					<span class="sub-pod-secret-status" <?php echo $has_password ? '' : 'hidden'; ?>>✓ 設定済み</span>
				</div>
				<div class="nau-form-group">
					<label for="sub-pod-password-confirm">暗号化パスワード(確認)</label>
					<input type="password" id="sub-pod-password-confirm" name="password_confirm" class="regular-text" autocomplete="new-password" placeholder="同じパスワードをもう一度入力してください">
					<p class="description"><strong>必ず手元に控えてください。</strong>復元に必要です。サイトがなくなると一緒に失われ、忘れるとバックアップは戻せません。<br>変更しても、それまでのバックアップは前のパスワードのままです。</p>
				</div>

				<?php if ( defined( 'CP_VERSION' ) ) : ?>
				<div class="nau-form-group">
					<label>
						<input type="checkbox" name="cp_backup" value="1" <?php checked( $settings['cp_backup'] ); ?>>
						Carry Pod の静的化の前にバックアップする
						<?php echo $this->render_tooltip( '手動でも自動でも、静的化の前にバックアップを取りきります。バックアップに失敗したら、静的化は止まります。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</label>
				</div>
				<?php endif; ?>

				<div class="nau-form-group">
					<button type="submit" class="button button-primary">設定を保存</button>
				</div>
			</div>
		</div>

		<div class="nau-accordion-section" data-section="backup">
			<button type="button" class="nau-accordion-header" id="header-backup" aria-expanded="true" aria-controls="accordion-backup">
				<span class="nau-accordion-title">バックアップ</span>
				<span class="nau-accordion-icon" aria-hidden="true"></span>
			</button>
			<div id="accordion-backup" class="nau-accordion-content" role="region" aria-labelledby="header-backup" aria-hidden="false">
				<div class="nau-form-group">
					<button type="button" id="sub-pod-backup" class="button button-primary" <?php disabled( $is_running ); ?>>今すぐバックアップ</button>
					<?php $has_log = '' !== SP_Backup::last_log(); ?>
					<a id="sub-pod-log" class="button" href="<?php echo esc_url( SP_Backup::log_url() ); ?>" <?php echo $has_log ? '' : 'style="display:none;"'; ?>>ログを取得</a>
					<?php if ( $is_running ) : ?>
						<p>別のバックアップまたは復元が実行中です。</p>
					<?php endif; ?>
					<div class="sub-pod-progress" id="sub-pod-backup-progress" hidden>
						<div class="sub-pod-progress-header">
							<div class="sub-pod-progress-bar-wrapper" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
								<div class="sub-pod-progress-bar"></div>
							</div>
							<span class="sub-pod-progress-percentage">0%</span>
						</div>
						<div class="sub-pod-progress-status" aria-live="polite"></div>
					</div>
				</div>
			</div>
		</div>

		<div class="nau-accordion-section" data-section="restore">
			<button type="button" class="nau-accordion-header" id="header-restore" aria-expanded="false" aria-controls="accordion-restore">
				<span class="nau-accordion-title">復元</span>
				<span class="nau-accordion-icon" aria-hidden="true"></span>
			</button>
			<div id="accordion-restore" class="nau-accordion-content" role="region" aria-labelledby="header-restore" aria-hidden="true">
				<?php if ( null !== $leftover_path ) : ?>
				<div class="notice notice-error inline">
					<p>前回の復元が完了しなかったときの一時ファイルが残っています。元のサイトのデータが含まれていて、再起動で消えることがあります。サイトが正常でなければ、早めに次の場所から取り出してください: <code><?php echo esc_html( $leftover_path ); ?></code></p>
					<p><button type="button" id="sub-pod-discard-leftover" class="button">一時ファイルを消して復元できるようにする</button></p>
				</div>
				<?php endif; ?>

				<div class="notice notice-warning inline">
					<p>今のサイトはバックアップの内容に置き換わります。<strong>信頼できるファイルだけ</strong>を使ってください。</p>
				</div>

				<div class="nau-form-group">
					<label>
						バックアップファイル
						<?php echo $this->render_tooltip( '復元の間、今のサイトは OS の一時フォルダへ退避します。途中で PC が落ちると、再起動で消えて元に戻せないことがあります。大事なサイトは先にバックアップを取ってください。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</label>
					<input type="file" id="sub-pod-restore-file" accept=".enc" hidden>
					<div class="nau-image-upload-area" id="sub-pod-restore-drop">
						<div class="nau-image-placeholder" id="sub-pod-restore-placeholder">
							<div class="nau-image-placeholder-text">ファイルをドロップ、または選択してください。</div>
							<button type="button" class="button nau-media-button">ファイルを選択</button>
						</div>
						<div class="nau-image-selected" id="sub-pod-restore-selected" style="display:none;">
							<p class="sub-pod-selected-file"></p>
							<div class="nau-image-buttons">
								<button type="button" class="button nau-remove-button" id="sub-pod-restore-remove">削除</button>
							</div>
						</div>
					</div>
				</div>

				<div class="nau-form-group">
					<label for="sub-pod-restore-encryption">
						暗号化パスワード <span class="required">*</span>
						<?php echo $this->render_tooltip( 'バックアップしたときに設定していた暗号化パスワードです。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</label>
					<input type="password" id="sub-pod-restore-encryption" class="regular-text" autocomplete="new-password">
				</div>

				<div class="nau-form-group">
					<label for="sub-pod-restore-admin">今のログインパスワード</label>
					<input type="password" id="sub-pod-restore-admin" class="regular-text" autocomplete="current-password">
				</div>

				<div class="nau-form-group">
					<label>
						<input type="checkbox" id="sub-pod-restore-confirm">
						今のサイトが置き換わることを理解しました
						<?php echo $this->render_tooltip( '完了後はログイン画面に移ります。バックアップしたときのアカウントでログインしてください。' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</label>
				</div>

				<div class="nau-form-group">
					<button type="button" id="sub-pod-restore-submit" class="button button-primary" <?php disabled( $is_running || null !== $leftover_path ); ?>>復元する</button>
					<div class="sub-pod-progress" id="sub-pod-restore-progress" hidden>
						<div class="sub-pod-progress-header">
							<div class="sub-pod-progress-bar-wrapper" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0">
								<div class="sub-pod-progress-bar"></div>
							</div>
							<span class="sub-pod-progress-percentage">0%</span>
						</div>
						<div class="sub-pod-progress-status" aria-live="polite"></div>
					</div>
				</div>
			</div>
		</div>
	</form>

	<div class="nau-version-info">
		Sub Pod <a href="https://github.com/villyoshioka/SubPod/releases/tag/v<?php echo esc_attr( SP_VERSION ); ?>" target="_blank" rel="noopener noreferrer">v<?php echo esc_html( SP_VERSION ); ?></a>
	</div>
</div>
